<?php
// 上界参考：Swoole 原生 HTTP Server（全 C 实现）
$workers = (int)(getenv('BENCH_W') ?: 4);
$port    = (int)(getenv('BENCH_PORT') ?: 9504);
$http = new Swoole\Http\Server('0.0.0.0', $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$http->set(['worker_num' => $workers, 'log_level' => SWOOLE_LOG_ERROR, 'enable_coroutine' => false]);
$http->on('request', function ($req, $resp) {
    $resp->header('Content-Type', 'text/plain');
    $resp->end('Hello World');
});
$http->start();
