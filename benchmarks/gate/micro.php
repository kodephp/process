<?php
/**
 * 微基准：剥离网络，纯测「HTTP 解析 + 响应编码」的 PHP 用户态开销
 */
require __DIR__ . '/vendor/autoload.php';

use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response;
use Workerman\Connection\TcpConnection;

$N = (int)($argv[1] ?? 300000);
$raw = "GET /api/user?id=42 HTTP/1.1\r\nHost: 127.0.0.1:9501\r\nUser-Agent: wrk/4.2.0\r\n"
     . "Accept: */*\r\nConnection: keep-alive\r\n\r\n";

// --- 构造 Workerman TcpConnection（绕过构造函数）---
$ref  = new ReflectionClass(TcpConnection::class);
$conn = $ref->newInstanceWithoutConstructor();
foreach (['protocol' => Http::class, 'maxPackageSize' => 10485760] as $p => $v) {
    if ($ref->hasProperty($p)) { $pr = $ref->getProperty($p); $pr->setAccessible(true); $pr->setValue($conn, $v); }
}

function bench(string $name, callable $fn, int $n): array {
    $fn(); // 预热
    for ($i = 0; $i < 1000; $i++) $fn();
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) $fn();
    $ns = hrtime(true) - $t0;
    return [$name, $n / ($ns / 1e9), $ns / $n];
}

$rows = [];

// A. Workerman 全链路：input -> decode -> Response -> encode
$rows[] = bench('Workerman', function () use ($raw, $conn) {
    $len = Http::input($raw, $conn);
    if ($len <= 0) return;
    $req = Http::decode($raw, $conn);
    $req->path();
    $resp = new Response(200, ['Content-Type' => 'text/plain'], 'Hello World');
    return Http::encode($resp, $conn);
}, $N);

// B. 极简手写解析（预构建响应模板）
$tpl = "HTTP/1.1 200 OK\r\nServer: k\r\nContent-Type: text/plain\r\nContent-Length: 11\r\n\r\nHello World";
$rows[] = bench('Minimal', function () use ($raw, $tpl) {
    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) return;
    $sp1  = strpos($raw, ' ');
    $sp2  = strpos($raw, ' ', $sp1 + 1);
    $path = substr($raw, $sp1 + 1, $sp2 - $sp1 - 1);
    if (($q = strpos($path, '?')) !== false) $path = substr($path, 0, $q);
    return $tpl;
}, $N);

// C. 只解析、不编码（看解析占比）
$rows[] = bench('WM-parseOnly', function () use ($raw, $conn) {
    $len = Http::input($raw, $conn);
    if ($len <= 0) return;
    return Http::decode($raw, $conn)->path();
}, $N);

printf("%-14s %-16s %-14s %s\n", 'IMPL', 'ops/s', 'ns/op', 'vs Workerman');
$base = $rows[0][1];
foreach ($rows as [$n, $ops, $nsop]) {
    printf("%-14s %-16s %-14s %.2fx\n", $n, number_format($ops, 0), number_format($nsop, 0), $ops / $base);
}
