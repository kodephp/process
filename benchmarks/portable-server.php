<?php

/**
 * 跨运行时可移植性验证 + 压测目标服务器。
 *
 * 关键点：下面的**业务代码一行都不用改**，仅靠 argv 切换底层运行时。
 * 这正是 Runtime 抽象的契约——native / swoole / workerman 三选一，行为一致。
 *
 * 用法：
 *   php benchmarks/portable-server.php [runtime] [port] [workers]
 *   php benchmarks/portable-server.php native    8081 4
 *   php benchmarks/portable-server.php swoole    8082 4
 *   php benchmarks/portable-server.php workerman 8083 4
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Kode;

$runtime = $argv[1] ?? 'native';
$port    = (int)($argv[2] ?? 8081);
$workers = (int)($argv[3] ?? 4);

// ------------------------------------------------------------------
// ↓↓↓ 以下业务代码与运行时无关，切换 native/swoole/workerman 无需改动 ↓↓↓
// ------------------------------------------------------------------

$server = Kode::serve("http://127.0.0.1:{$port}", [
    'workers' => $workers,
    'name'    => "bench-{$runtime}",
], $runtime);

$server->on('workerStart', function () use ($runtime, $server): void {
    // 统一 API：workerId() 在三种运行时下都可用
    fwrite(STDERR, sprintf("[%s] worker #%d started\n", $runtime, $server->workerId()));
});

$server->on('message', function ($conn, $request): void {
    $conn->send('Hello, Kode!');
});

$server->start();
