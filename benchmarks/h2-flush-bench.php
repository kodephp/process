<?php

declare(strict_types=1);

/**
 * flushPending() 微基准：大响应切帧 + 多流空转两条路径。
 *
 * 用法：php benchmarks/h2-flush-bench.php [rounds]
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Hpack;
use Kode\Process\Protocol\Http2\Http2Session;

$rounds = (int) ($argv[1] ?? 200);

$client = new Hpack();
$reqBlock = $client->encode([
    [':method', 'GET'],
    [':scheme', 'http'],
    [':path', '/'],
    [':authority', 'example.com'],
]);

/** 开一条流并把窗口开满，返回可直接 respond 的 session。 */
function openStream(Http2Session $s, int $id, string $block, int $need): void
{
    $s->feed(Frame::encode(
        Frame::TYPE_HEADERS,
        Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM,
        $id,
        $block
    ));
    // 连接级 + 流级发送窗口都开到够用，避免窗口耗尽提前 break
    $s->feed(Frame::windowUpdate(0, $need));
    $s->feed(Frame::windowUpdate($id, $need));
    $s->drain();
}

echo "rounds={$rounds}\n";
echo str_repeat('-', 60), "\n";

// ---------- A. 大响应切帧（每轮一条新连接 + 新流） ----------
foreach ([64, 256, 1024] as $kb) {
    $body = str_repeat('x', $kb * 1024);
    $need = strlen($body) + 65535;
    // 每轮都要留住一份 pending + 一份 outBuffer，按体积缩放轮数以免撑爆内存
    $n = max(20, intdiv($rounds * 64, $kb));

    // 预热
    for ($i = 0; $i < 3; $i++) {
        $s = new Http2Session();
        $s->feed(Frame::PREFACE);
        openStream($s, 1, $reqBlock, $need);
        $s->respond(1, 200, ['content-type' => 'text/plain'], $body);
        $s->drain();
    }

    $sessions = [];
    for ($i = 0; $i < $n; $i++) {
        $s = new Http2Session();
        $s->feed(Frame::PREFACE);
        openStream($s, 1, $reqBlock, $need);
        $sessions[] = $s;
    }

    $t = hrtime(true);
    foreach ($sessions as $s) {
        $s->respond(1, 200, ['content-type' => 'text/plain'], $body);
    }
    $ms = (hrtime(true) - $t) / 1e6;

    $frames = (int) ceil(strlen($body) / Frame::MIN_MAX_FRAME_SIZE);
    printf(
        "A. respond %4d KB (%3d 帧/次) x%3d : %8.2f ms  (%.3f ms/次)\n",
        $kb,
        $frames,
        $n,
        $ms,
        $ms / $n
    );

    unset($sessions);
}

echo str_repeat('-', 60), "\n";

// ---------- B. 多流空转：WINDOW_UPDATE 触发的全流扫描 ----------
foreach ([16, 64, 128] as $streams) {
    $s = new Http2Session($streams + 8);
    $s->feed(Frame::PREFACE);
    for ($i = 1; $i <= $streams; $i++) {
        $id = $i * 2 - 1;
        $s->feed(Frame::encode(Frame::TYPE_HEADERS, Frame::FLAG_END_HEADERS, $id, $client->encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'example.com'],
        ])));
    }
    $s->drain();

    $wu = Frame::windowUpdate(0, 1024);
    $n  = $rounds * 20;

    for ($i = 0; $i < 200; $i++) {
        $s->feed($wu);
    }
    $s->drain();

    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s->feed($wu);
    }
    $ms = (hrtime(true) - $t) / 1e6;
    $s->drain();

    printf(
        "B. %3d 条空闲流 / WINDOW_UPDATE x%d : %8.2f ms  (%.4f ms/次)\n",
        $streams,
        $n,
        $ms,
        $ms / $n
    );
}

echo str_repeat('-', 60), "\n";
printf("peak memory: %.1f MB\n", memory_get_peak_usage(true) / 1048576);
