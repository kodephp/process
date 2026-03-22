<?php

declare(strict_types=1);

/**
 * 示例：广播系统 - 聊天室
 * 
 * 运行方式：php examples/28-broadcast-chat.php start
 */

use Kode\Process\Compat\Worker;
use Kode\Process\Broadcast\Broadcaster;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== 广播系统 - 聊天室 ===\n\n";

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 1;  // 广播需要单进程，多进程需配合 Channel
$worker->name = 'chat-room';

$broadcaster = Broadcaster::getInstance();

$worker->onConnect = function ($connection) use ($broadcaster) {
    $connection->send(json_encode([
        'type' => 'system',
        'message' => '欢迎来到聊天室！请输入你的昵称。'
    ]));
};

$worker->onMessage = function ($connection, $data) use ($broadcaster, $worker) {
    $msg = json_decode($data, true);

    if ($msg === null) {
        return;
    }

    switch ($msg['type'] ?? '') {
        case 'login':
            // 用户登录
            $nickname = $msg['nickname'] ?? '匿名用户';
            $connection->nickname = $nickname;
            $connection->uid = uniqid('user_');

            // 注册到广播器
            $broadcaster->register($connection, $connection->uid);

            // 加入默认群组
            $broadcaster->joinGroup($connection, 'chatroom');

            // 广播用户加入消息
            $broadcaster->broadcast(json_encode([
                'type' => 'system',
                'message' => "{$nickname} 加入了聊天室"
            ]));

            // 发送在线用户列表
            $connection->send(json_encode([
                'type' => 'users',
                'users' => array_map(function ($conn) {
                    return $conn->nickname ?? '匿名';
                }, iterator_to_array($worker->connections))
            ]));
            break;

        case 'message':
            // 聊天消息
            $nickname = $connection->nickname ?? '匿名用户';
            $message = $msg['message'] ?? '';

            if (empty($message)) {
                break;
            }

            // 广播给所有用户
            $broadcaster->broadcast(json_encode([
                'type' => 'message',
                'nickname' => $nickname,
                'message' => $message,
                'time' => date('H:i:s')
            ]));
            break;

        case 'whisper':
            // 私聊消息
            $toUid = $msg['to'] ?? '';
            $message = $msg['message'] ?? '';
            $nickname = $connection->nickname ?? '匿名用户';

            if (empty($toUid) || empty($message)) {
                break;
            }

            // 发送给特定用户
            $broadcaster->sendToUid($toUid, json_encode([
                'type' => 'whisper',
                'from' => $connection->uid,
                'nickname' => $nickname,
                'message' => $message,
                'time' => date('H:i:s')
            ]));
            break;

        case 'join_group':
            // 加入群组
            $group = $msg['group'] ?? '';
            if (!empty($group)) {
                $broadcaster->joinGroup($connection, $group);
                $connection->send(json_encode([
                    'type' => 'system',
                    'message' => "已加入群组: {$group}"
                ]));
            }
            break;

        case 'group_message':
            // 群组消息
            $group = $msg['group'] ?? '';
            $message = $msg['message'] ?? '';
            $nickname = $connection->nickname ?? '匿名用户';

            if (empty($group) || empty($message)) {
                break;
            }

            $broadcaster->broadcastToGroup($group, json_encode([
                'type' => 'group_message',
                'group' => $group,
                'nickname' => $nickname,
                'message' => $message,
                'time' => date('H:i:s')
            ]));
            break;
    }
};

$worker->onClose = function ($connection) use ($broadcaster) {
    $nickname = $connection->nickname ?? '匿名用户';

    // 从广播器注销
    $broadcaster->unregister($connection);

    // 广播用户离开消息
    $broadcaster->broadcast(json_encode([
        'type' => 'system',
        'message' => "{$nickname} 离开了聊天室"
    ]));
};

Worker::runAll();
