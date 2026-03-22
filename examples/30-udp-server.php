<?php

declare(strict_types=1);

/**
 * 示例：UDP 服务
 * 
 * 运行方式：php examples/30-udp-server.php
 * 
 * 测试命令: echo '{"action":"ping"}' | nc -u 127.0.0.1 9292
 */

use Kode\Process\Compat\Worker;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== UDP 服务示例 ===\n\n";

$worker = new Worker('udp://0.0.0.0:9292');
$worker->name = 'udp-server';
$worker->count = 1;

$worker->onMessage = function ($connection, $data) {
    echo "收到来自 {$connection->getRemoteAddress()} 的数据: {$data}\n";

    $msg = json_decode($data, true);

    if ($msg && isset($msg['action'])) {
        $response = match ($msg['action']) {
            'ping' => json_encode(['action' => 'pong', 'time' => time()]),
            'echo' => json_encode(['action' => 'echo', 'data' => $msg['data'] ?? '']),
            'time' => json_encode(['action' => 'time', 'time' => date('Y-m-d H:i:s')]),
            default => json_encode(['error' => 'Unknown action'])
        };
    } else {
        $response = "Echo: {$data}";
    }

    $connection->send($response);
};

echo "UDP 服务启动在 0.0.0.0:9292\n";
echo "测试命令: echo '{\"action\":\"ping\"}' | nc -u 127.0.0.1 9292\n";
echo "按 Ctrl+C 停止服务\n\n";

Worker::runAll();
