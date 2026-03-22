<?php

declare(strict_types=1);

/**
 * 示例：Channel 客户端 - WebSocket 推送服务
 * 
 * 运行方式：php examples/25-channel-client.php
 */

use Kode\Process\Compat\Worker;
use Kode\Process\Channel\Client;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Channel 客户端 - WebSocket 推送服务 ===\n\n";

$worker = new Worker('websocket://0.0.0.0:4236');
$worker->count = 4;
$worker->name = 'pusher';

$worker->onWorkerStart = function ($worker) {
    // 连接 Channel 服务端
    Client::connect('127.0.0.1', 2206);

    // 订阅广播事件
    Client::on('broadcast', function ($data) use ($worker) {
        $message = $data['message'] ?? '';
        foreach ($worker->connections as $connection) {
            $connection->send($message);
        }
    });

    // 订阅特定用户推送事件
    Client::on('send_to_user', function ($data) use ($worker) {
        $uid = $data['uid'] ?? '';
        $message = $data['message'] ?? '';

        foreach ($worker->connections as $connection) {
            if (isset($connection->uid) && $connection->uid === $uid) {
                $connection->send($message);
            }
        }
    });

    echo "Worker #{$worker->id} 已连接到 Channel 服务端\n";
};

$worker->onConnect = function ($connection) {
    echo "新连接: {$connection->id}\n";
};

$worker->onMessage = function ($connection, $data) {
    // 解析消息
    $msg = json_decode($data, true);

    if ($msg && isset($msg['type'])) {
        switch ($msg['type']) {
            case 'login':
                // 用户登录，绑定 UID
                $connection->uid = $msg['uid'] ?? '';
                $connection->send(json_encode(['type' => 'login', 'status' => 'success']));
                break;

            case 'broadcast':
                // 广播消息给所有用户
                Client::publish('broadcast', ['message' => $msg['message'] ?? '']);
                break;

            case 'send_to_user':
                // 发送给特定用户
                Client::publish('send_to_user', [
                    'uid' => $msg['uid'] ?? '',
                    'message' => $msg['message'] ?? ''
                ]);
                break;
        }
    }
};

$worker->onClose = function ($connection) {
    echo "连接关闭: {$connection->id}\n";
};

Worker::runAll();
