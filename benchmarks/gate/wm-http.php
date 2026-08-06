<?php
// 对照组：Workerman 5.x HTTP echo（参数走环境变量，argv 留给 Workerman 的 start 命令）
require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Protocols\Http\Response;

$workers = (int)(getenv('BENCH_W') ?: 4);
$port    = (int)(getenv('BENCH_PORT') ?: 9501);

Worker::$logFile = '/dev/null';
Worker::$pidFile = __DIR__ . '/wm.pid';

$w = new Worker("http://0.0.0.0:{$port}");
$w->count = $workers;
$w->name  = 'wm-http';
$w->onMessage = function ($conn, $req) {
    $conn->send(new Response(200, ['Content-Type' => 'text/plain'], 'Hello World'));
};
Worker::runAll();
