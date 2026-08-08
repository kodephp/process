<?php

/**
 * 热路径纯 CPU 微基准：把「每请求必付的 PHP 工作量」从 socket / 事件循环里剥离出来，
 * 单独对比 kode native 与 workerman 的解析 + 响应编码成本。
 *
 * 压测里两者的差距混杂了事件循环、socket 读写、内核调度等噪声，
 * 这里只跑 CPU 部分，差距归因才是干净的。
 *
 * 用法：php benchmarks/hotpath-micro.php [iterations]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Http\Request;
use Kode\Process\Protocol\HttpProtocol;

$n = (int) ($argv[1] ?? 300000);

// wrk 默认发出的请求报文（无 Accept-Encoding、无 Connection）
$raw = "GET / HTTP/1.1\r\nHost: 127.0.0.1:8081\r\n\r\n";
$body = 'Hello, Kode!';

/**
 * @param callable(): void $fn
 */
function bench(string $label, int $n, callable $fn): float
{
    // 预热，让 JIT / opcache 进入稳态
    for ($i = 0; $i < 2000; $i++) {
        $fn();
    }

    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $ms = (hrtime(true) - $t) / 1e6;

    printf("  %-34s %9.2f ms   %10.0f ops/s\n", $label, $ms, $n / ($ms / 1000));

    return $ms;
}

echo "热路径微基准  iterations={$n}  PHP " . PHP_VERSION . "\n";
echo "报文: " . strlen($raw) . " B    响应体: " . strlen($body) . " B\n\n";

echo "[kode native] 分段\n";
bench('input() 定长判定', $n, function () use ($raw): void {
    HttpProtocol::input($raw);
});
bench('decode() → Request', $n, function () use ($raw): void {
    HttpProtocol::decode($raw);
});
bench('rawHeader Accept-Encoding(miss)', $n, function () use ($raw): void {
    Request::fromRaw($raw)->rawHeader('Accept-Encoding');
});
bench('rawHeader Connection(miss)', $n, function () use ($raw): void {
    Request::fromRaw($raw)->rawHeader('Connection');
});
bench('protocol() 请求行解析', $n, function () use ($raw): void {
    Request::fromRaw($raw)->protocol();
});
bench('isHttp10() 版本快判', $n, function () use ($raw): void {
    Request::fromRaw($raw)->isHttp10();
});
bench('encode() 响应', $n, function () use ($body): void {
    HttpProtocol::encode($body);
});

echo "\n[kode native] 每请求完整热路径\n";
$kode = bench('input+decode+2×rawHeader+encode', $n, function () use ($raw, $body): void {
    $len = HttpProtocol::input($raw);
    if ($len <= 0) {
        return;
    }
    $req = HttpProtocol::decode($raw);
    $req->rawHeader('Accept-Encoding');
    $conn = $req->rawHeader('Connection');
    if ($conn === '') {
        $req->isHttp10();
    }
    HttpProtocol::encode($body);
});

$wmRequest = '\Workerman\Protocols\Http\Request';
$wmHttp    = '\Workerman\Protocols\Http';

if (!class_exists($wmRequest)) {
    echo "\n[workerman] 未安装，跳过\n";
    return;
}

// Workerman 的 Http::input 需要一个 TcpConnection 取 maxPackageSize，
// 这里绕过构造函数造一个壳对象，避免把 socket 初始化算进 CPU 成本。
$stubConn = (new ReflectionClass(\Workerman\Connection\TcpConnection::class))->newInstanceWithoutConstructor();

echo "\n[workerman] 每请求完整热路径\n";
$wm = bench('Http::input + new Request + 头访问 + 响应', $n, function () use ($raw, $body, $wmRequest, $stubConn): void {
    $len = \Workerman\Protocols\Http::input($raw, $stubConn);
    if ($len <= 0) {
        return;
    }
    /** @var \Workerman\Protocols\Http\Request $req */
    $req = new $wmRequest($raw);
    $req->header('accept-encoding');
    $conn = $req->header('connection');
    if ($conn === null) {
        $req->protocolVersion();
    }
    // workerman 的响应编码
    new \Workerman\Protocols\Http\Response(200, [], $body);
});

printf(
    "\n差距: kode %.2f ms vs workerman %.2f ms  →  %s%.1f%%\n",
    $kode,
    $wm,
    $kode <= $wm ? '-' : '+',
    abs($kode - $wm) / $wm * 100
);
