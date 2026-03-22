<?php

declare(strict_types=1);

/**
 * 示例：WebSocket 聊天服务
 * 
 * 运行方式：php examples/32-websocket-chat.php
 * 
 * 在浏览器中打开 examples/32-websocket-client.html 测试
 */

use Kode\Process\Compat\Worker;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== WebSocket 聊天服务 ===\n\n";

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 1;
$worker->name = 'websocket-chat';

$worker->onWebSocketConnect = function ($connection) {
    echo "新 WebSocket 连接: {$connection->getRemoteAddress()}\n";
    $connection->id = uniqid('user_');
};

$worker->onMessage = function ($connection, $data) use ($worker) {
    echo "收到消息 ({$connection->id}): {$data}\n";

    $msg = json_decode($data, true);

    if ($msg === null) {
        $connection->send(json_encode(['error' => 'Invalid JSON']));
        return;
    }

    $type = $msg['type'] ?? 'message';

    switch ($type) {
        case 'login':
            $connection->nickname = $msg['nickname'] ?? '匿名用户';
            broadcast($worker, json_encode([
                'type' => 'system',
                'message' => "{$connection->nickname} 加入了聊天室",
                'time' => date('H:i:s')
            ]));
            break;

        case 'message':
            broadcast($worker, json_encode([
                'type' => 'message',
                'from' => $connection->nickname ?? $connection->id,
                'message' => $msg['message'] ?? '',
                'time' => date('H:i:s')
            ]));
            break;

        case 'ping':
            $connection->send(json_encode(['type' => 'pong', 'time' => time()]));
            break;
    }
};

$worker->onClose = function ($connection) use ($worker) {
    echo "连接关闭: {$connection->getRemoteAddress()}\n";
    $nickname = $connection->nickname ?? '用户';
    broadcast($worker, json_encode([
        'type' => 'system',
        'message' => "{$nickname} 离开了聊天室",
        'time' => date('H:i:s')
    ]));
};

function broadcast(Worker $worker, string $message): void
{
    foreach ($worker->connections as $connection) {
        $connection->send($message);
    }
}

echo "WebSocket 服务启动在 0.0.0.0:8080\n";
echo "在浏览器中打开 examples/32-websocket-client.html\n";
echo "按 Ctrl+C 停止服务\n\n";

Worker::runAll();
