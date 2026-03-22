# 广播系统

广播系统用于向多个连接发送消息，支持全局广播、群组广播、定向发送。

## 基本概念

- **全局广播** - 向所有连接发送消息
- **群组广播** - 向特定群组发送消息
- **定向发送** - 向特定用户发送消息

## 快速开始

### 创建广播器

```php
use Kode\Process\Broadcast\Broadcaster;

$broadcaster = Broadcaster::getInstance();
```

### 注册连接

```php
// 注册连接（可选：关联用户 ID）
$broadcaster->register($connection, 'user_123');
```

### 群组操作

```php
// 加入群组
$broadcaster->joinGroup($connection, 'room_1');
$broadcaster->joinGroup($connection, 'room_2');

// 离开群组
$broadcaster->leaveGroup($connection, 'room_1');
```

### 发送消息

```php
// 全局广播
$broadcaster->broadcast('系统公告：服务器即将维护');

// 群组广播
$broadcaster->broadcastToGroup('room_1', '群组消息');

// 发送给特定用户
$broadcaster->sendToUid('user_123', '私人消息');

// 发送给特定连接
$broadcaster->sendToConnection($connectionId, '消息');
```

### 注销连接

```php
// 连接关闭时注销
$broadcaster->unregister($connection);
```

## 完整示例：聊天室

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Broadcast\Broadcaster;

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'ChatRoom';

$worker->onConnect = function ($connection) {
    $broadcaster = Broadcaster::getInstance();
    
    // 注册连接
    $broadcaster->register($connection);
    
    // 发送欢迎消息
    $connection->send(json_encode([
        'type' => 'system',
        'message' => '欢迎加入聊天室'
    ]));
    
    // 广播用户加入
    $broadcaster->broadcast(json_encode([
        'type' => 'join',
        'connection_id' => $connection->id
    ]));
};

$worker->onMessage = function ($connection, $data) {
    $broadcaster = Broadcaster::getInstance();
    $message = json_decode($data, true);
    
    switch ($message['type'] ?? 'message') {
        case 'join_room':
            // 加入房间
            $room = $message['room'];
            $broadcaster->joinGroup($connection, $room);
            
            // 通知房间内其他用户
            $broadcaster->broadcastToGroup($room, json_encode([
                'type' => 'room_join',
                'connection_id' => $connection->id
            ]));
            break;
            
        case 'leave_room':
            // 离开房间
            $room = $message['room'];
            $broadcaster->leaveGroup($connection, $room);
            break;
            
        case 'room_message':
            // 房间消息
            $room = $message['room'];
            $broadcaster->broadcastToGroup($room, json_encode([
                'type' => 'room_message',
                'from' => $connection->id,
                'message' => $message['content']
            ]));
            break;
            
        case 'private_message':
            // 私聊消息
            $to = $message['to'];
            $broadcaster->sendToConnection($to, json_encode([
                'type' => 'private_message',
                'from' => $connection->id,
                'message' => $message['content']
            ]));
            break;
            
        case 'broadcast':
        default:
            // 全局广播
            $broadcaster->broadcast(json_encode([
                'type' => 'message',
                'from' => $connection->id,
                'message' => $message['content'] ?? $data
            ]));
            break;
    }
};

$worker->onClose = function ($connection) {
    $broadcaster = Broadcaster::getInstance();
    
    // 注销连接（自动离开所有群组）
    $broadcaster->unregister($connection);
    
    // 广播用户离开
    $broadcaster->broadcast(json_encode([
        'type' => 'leave',
        'connection_id' => $connection->id
    ]));
};

Worker::runAll();
```

## 客户端示例

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>聊天室</title>
</head>
<body>
    <div id="messages"></div>
    <input type="text" id="input" placeholder="输入消息">
    <button onclick="send()">发送</button>
    <button onclick="joinRoom('room1')">加入房间1</button>
    
    <script>
        const ws = new WebSocket('ws://localhost:8080');
        const messages = document.getElementById('messages');
        
        ws.onmessage = function(e) {
            const data = JSON.parse(e.data);
            const div = document.createElement('div');
            div.textContent = JSON.stringify(data);
            messages.appendChild(div);
        };
        
        function send() {
            const input = document.getElementById('input');
            ws.send(JSON.stringify({
                type: 'broadcast',
                content: input.value
            }));
            input.value = '';
        }
        
        function joinRoom(room) {
            ws.send(JSON.stringify({
                type: 'join_room',
                room: room
            }));
        }
    </script>
</body>
</html>
```

## API 参考

```php
use Kode\Process\Broadcast\Broadcaster;

$broadcaster = Broadcaster::getInstance();

// 注册/注销
$broadcaster->register($connection, $uid = null);
$broadcaster->unregister($connection);

// 群组操作
$broadcaster->joinGroup($connection, $groupName);
$broadcaster->leaveGroup($connection, $groupName);
$broadcaster->leaveAllGroups($connection);

// 发送消息
$broadcaster->broadcast($message);
$broadcaster->broadcastToGroup($groupName, $message);
$broadcaster->sendToUid($uid, $message);
$broadcaster->sendToConnection($connectionId, $message);

// 查询
$broadcaster->getConnections();
$broadcaster->getGroupConnections($groupName);
$broadcaster->getUid($connection);
$broadcaster->getConnectionByUid($uid);
```

## 分布式广播

在多服务器环境下，需要配合 Channel 实现分布式广播。

```php
use Kode\Process\Compat\Worker;
use Kode\Process\Broadcast\Broadcaster;
use Kode\Process\Channel\Client;

$worker = new Worker('websocket://0.0.0.0:8080');

$worker->onWorkerStart = function ($worker) {
    // 连接 Channel 服务端
    Client::connect('127.0.0.1', 2206);
    
    // 订阅广播事件
    Client::on('broadcast', function ($data) use ($worker) {
        $broadcaster = Broadcaster::getInstance();
        foreach ($worker->connections as $conn) {
            $conn->send($data['message']);
        }
    });
    
    // 订阅群组广播事件
    Client::on('group_broadcast', function ($data) use ($worker) {
        $broadcaster = Broadcaster::getInstance();
        $broadcaster->broadcastToGroup($data['group'], $data['message']);
    });
};

$worker->onMessage = function ($connection, $data) {
    $message = json_decode($data, true);
    
    // 发布广播事件（跨服务器）
    Client::publish('broadcast', [
        'message' => $message
    ]);
};

Worker::runAll();
```
