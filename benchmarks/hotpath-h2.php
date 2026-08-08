<?php

/**
 * HTTP/2 热路径微基准：隔离 socket / 事件循环，只量「每响应必付的 PHP 工作量」。
 *   - Hpack::encode(典型响应头)            —— 响应编码热点
 *   - feed(请求)+respond(响应) 每请求      —— 持久会话（一次连接，每条请求一条流）
 *   - 【含会话构造】feed+respond 每请求    —— 把连接建立成本也算进每次（偏保守）
 * 用法：php benchmarks/hotpath-h2.php [iterations]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Hpack;
use Kode\Process\Protocol\Http2\Http2Session;

$n = (int) ($argv[1] ?? 200000);

$enc = new Hpack();
$block = $enc->encode([
    [':method', 'GET'],
    [':scheme', 'http'],
    [':path', '/'],
    [':authority', 'example.com'],
    ['user-agent', 'curl/8.0'],
    ['accept', '*/*'],
]);

// 预生成足够覆盖「warmup(2000) + timed(n)」的帧（持久会话，每请求一条新流）。
// 注意 $k 以引用跨 warmup 与 timed 两次调用持续自增，必须预留余量，否则越界。
$total = $n + 10000;
$frames = [];
for ($sid = 1; $sid <= $total * 2; $sid += 2) {
    $frames[] = Frame::encode(
        Frame::TYPE_HEADERS,
        Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM,
        $sid,
        $block
    );
}

$typicalHeaders = [
    'content-type'   => 'text/html; charset=utf-8',
    'content-length' => '13',
    'cache-control'  => 'no-cache',
    'date'           => 'Mon, 08 Aug 2026 00:00:00 GMT',
    'server'         => 'kode/5.2.0',
];
$body = 'Hello, Kode!';

function bench(string $label, int $n, callable $fn): float
{
    for ($i = 0; $i < 2000; $i++) {
        $fn();
    }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $ms = (hrtime(true) - $t) / 1e6;
    printf("  %-42s %9.2f ms   %10.0f ops/s\n", $label, $ms, $n / ($ms / 1000));
    return $ms;
}

echo "HTTP/2 热路径微基准  iterations={$n}  PHP " . PHP_VERSION . "\n\n";

$list = array_merge(
    [[':status', '200']],
    array_map(fn ($k, $v) => [$k, $v], array_keys($typicalHeaders), array_values($typicalHeaders))
);

// 冷启动：每次迭代清空字面量缓存，隔离「无优化」的原始 Huffman 成本
bench('Hpack::encode(冷, 每轮清缓存)', $n, function () use ($enc, $list): void {
    Hpack::clearLiteralCache();
    $enc->encode($list);
});

// 稳态：真实服务反复发送同一组响应头，缓存命中后每条请求只做查表 + 拼接
bench('Hpack::encode(热, 重复头命中缓存)', $n, function () use ($enc, $list): void {
    $enc->encode($list);
});

// 持久会话每请求（含 body）：补足连接级流控窗口，让流能正常关闭，量真实每请求成本
$s = new Http2Session();
$s->markPrefaceReceived();
$s->sendLocalSettings();
$k = 0;
bench('feed+respond 每请求(热会话,补窗口)', $n, function () use ($frames, $typicalHeaders, $body, $s, &$k): void {
    $s->feed($frames[$k]);
    $s->respond($k * 2 + 1, 200, $typicalHeaders, $body);
    $s->feed(Frame::windowUpdate(0, strlen($body))); // 模拟客户端消费后回补窗口
    $k++;
});

bench('【含会话构造】feed+respond 每请求', $n, function () use ($frames, $typicalHeaders, $body): void {
    $s = new Http2Session();
    $s->markPrefaceReceived();
    $s->sendLocalSettings();
    $s->feed($frames[0]);
    $s->respond(1, 200, $typicalHeaders, $body);
});
