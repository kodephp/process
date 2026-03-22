<?php

declare(strict_types=1);

/**
 * Workerman 兼容层示例
 * 
 * 展示如何使用 Workerman 风格的 API
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;

echo "=== Workerman 兼容层示例 ===\n\n";

// 方式一：完全兼容 Workerman 写法
$worker = new Worker('tcp://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'my-worker';

$worker->onWorkerStart = function (Worker $worker) {
    echo "Worker {$worker->id} 启动\n";

    // 定时器
    Timer::add(1, function () use ($worker) {
        echo "Worker {$worker->id} 心跳，连接数: {$worker->getConnectionCount()}\n";
    });
};

$worker->onMessage = function ($connection, $data) {
    echo "收到消息: {$data}\n";
    $connection->send("Hello: {$data}");
};

$worker->onClose = function ($connection) {
    echo "连接关闭: {$connection->id}\n";
};

// 启动所有 Worker
Worker::runAll();
