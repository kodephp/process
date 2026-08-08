<?php

/**
 * 大响应长时压测目标服务器（健壮性验证用）。
 *
 * 与 portable-server.php 一样，业务代码与运行时无关——仅 argv/runtime 切换。
 * 区别：响应体大小可配（KODE_BODY_KB，默认 256KB）。每个 worker 每处理 N 次请求，
 * 向 STDERR 打印一次 PHP 内存峰值，便于压测期间从日志观测「大响应缓冲是否泄漏」
 * （峰值应在热身期后趋于平稳，而非随时间无界增长）。
 *
 * 用法：
 *   KODE_BODY_KB=256 php benchmarks/portable-server-large.php native 19310 4
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Kode;

$runtime = $argv[1] ?? 'native';
$port    = (int)($argv[2] ?? 19310);
$workers = (int)($argv[3] ?? 4);

$bodyKb = (int)(getenv('KODE_BODY_KB') ?: 256);
$body   = str_repeat('KodePHP-large-response-payload-0123456789-', (int) ceil($bodyKb * 1024 / 44));
$body   = substr($body, 0, $bodyKb * 1024);

$reusePort     = getenv('REUSE_PORT');
$reusePortOpt  = $reusePort === false ? null : (bool) (int) $reusePort;

$server = Kode::serve("http://127.0.0.1:{$port}", [
    'workers'   => $workers,
    'name'      => "bench-large-{$runtime}",
    'reusePort' => $reusePortOpt,
], $runtime);

$server->on('workerStart', function () use ($runtime, $workers, $bodyKb): void {
    fwrite(STDERR, sprintf(
        "[%s] large-body worker started (workers=%d body=%dKB)\n",
        $runtime, $workers, $bodyKb
    ));
});

// 每处理 N 次请求打印一次内存峰值（按 worker 独立计数），用于检测大响应缓冲泄漏
$logEvery = (int)(getenv('KODE_MEM_EVERY') ?: 10000);

$server->on('message', function ($conn, $request) use ($body, $logEvery): void {
    static $count = 0;
    if (++$count % $logEvery === 0) {
        fwrite(STDERR, sprintf(
            "MEM %d %d %d %d\n",
            getmypid(), $count, memory_get_peak_usage(true), memory_get_usage(true)
        ));
    }
    $conn->send($body);
});

$server->start();
