<?php

/**
 * 带 body 的 POST 长时压测目标服务器（请求体方向 + 接收流控健壮性验证）。
 *
 * 与 portable-server-large.php 对称：后者压「大响应 / 发送流控」，本脚本压
 * 「大请求体 / 接收流控」。业务代码与运行时无关——仅 argv/runtime 切换。
 *
 * 行为：每个请求读取 POST 体（强制请求体在 handler 内被物化），回一个固定小响应，
 * 使流正常关闭、请求体缓冲随之释放。每个 worker 每处理 N 次请求向 STDERR 打印
 * 一次 PHP 内存峰值，用于观测「请求体缓冲（含 DATA 帧 recv 流控）是否泄漏」——
 * 峰值应在热身期后趋于平稳，而非随时间无界增长。
 *
 * 关键验证点：客户端发大请求体时，服务端 recv 窗口会耗尽并回发 WINDOW_UPDATE，
 * 长时施压下吞吐应稳定、无流控停滞、无内存泄漏。
 *
 * 用法：
 *   php benchmarks/portable-server-post.php native 19311 4
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Kode;

$runtime = $argv[1] ?? 'native';
$port    = (int)($argv[2] ?? 19311);
$workers = (int)($argv[3] ?? 4);

$reusePort     = getenv('REUSE_PORT');
$reusePortOpt  = $reusePort === false ? null : (bool) (int) $reusePort;

$server = Kode::serve("http://127.0.0.1:{$port}", [
    'workers'   => $workers,
    'name'      => "bench-post-{$runtime}",
    'reusePort' => $reusePortOpt,
], $runtime);

$server->on('workerStart', function () use ($runtime, $workers): void {
    fwrite(STDERR, sprintf(
        "[%s] post-body worker started (workers=%d)\n",
        $runtime, $workers
    ));
});

// 响应体大小：默认小响应（压力集中在请求体接收方向）；设 KODE_RESP_KB 后可回大响应，
// 形成「大请求体接收 + 大响应发送」双向同时压——混合负载健壮性验证（§5.2）。
$respKb = (int)(getenv('KODE_RESP_KB') ?: 0);
if ($respKb > 0) {
    $response = str_repeat('x', $respKb * 1024);
    fwrite(STDERR, sprintf("[%s] mixed-load: responding with %d KB body\n", $runtime, $respKb));
} else {
    $response = 'OK';
}

// 每处理 N 次请求打印一次内存峰值（按 worker 独立计数），用于检测请求体缓冲泄漏
$logEvery = (int)(getenv('KODE_MEM_EVERY') ?: 10000);

$server->on('message', function ($conn, $request) use ($response, $logEvery): void {
    static $count = 0;
    // 物化请求体（strlen 触发实际读取；框架已在流内收齐 DATA 帧）
    $len = isset($request['body']) ? strlen((string) $request['body']) : 0;
    if (++$count % $logEvery === 0) {
        fwrite(STDERR, sprintf(
            "MEM %d %d %d %d body=%d\n",
            getmypid(), $count, memory_get_peak_usage(true), memory_get_usage(true), $len
        ));
    }
    $conn->send($response);
});

$server->start();
