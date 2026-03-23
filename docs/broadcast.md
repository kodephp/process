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
$broadcaster->register($connection, 'user_123');
```

### 群组操作

```php
$broadcaster->joinGroup($connection, 'room_1');
$broadcaster->joinGroup($connection, 'room_2');
$broadcaster->leaveGroup($connection, 'room_1');
```

### 发送消息

```php
$broadcaster->broadcast('系统公告');
$broadcaster->broadcastToGroup('room_1', '群组消息');
$broadcaster->sendToUid('user_123', '私人消息');
$broadcaster->sendToConnection($connectionId, '消息');
```

## 完整示例：聊天室

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Broadcast\Broadcaster;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onConnect(function ($conn) {
        $broadcaster = Broadcaster::getInstance();
        $broadcaster->register($conn);
        $conn->send(json_encode(['type' => 'system', 'message' => '欢迎加入聊天室']));
    })
    ->onMessage(function ($conn, $data) {
        $broadcaster = Broadcaster::getInstance();
        $message = json_decode($data, true);

        switch ($message['type'] ?? 'message') {
            case 'join_room':
                $broadcaster->joinGroup($conn, $message['room']);
                break;
            case 'leave_room':
                $broadcaster->leaveGroup($conn, $message['room']);
                break;
            case 'room_message':
                $broadcaster->broadcastToGroup($message['room'], json_encode([
                    'type' => 'room_message',
                    'from' => $conn->id,
                    'content' => $message['content']
                ]));
                break;
            case 'broadcast':
            default:
                $broadcaster->broadcast(json_encode([
                    'type' => 'message',
                    'from' => $conn->id,
                    'content' => $message['content'] ?? $data
                ]));
                break;
        }
    })
    ->onClose(function ($conn) {
        Broadcaster::getInstance()->unregister($conn);
    })
    ->start();
```

## API 参考

```php
use Kode\Process\Broadcast\Broadcaster;

$broadcaster = Broadcaster::getInstance();

$broadcaster->register($connection, $uid = null);
$broadcaster->unregister($connection);
$broadcaster->joinGroup($connection, $groupName);
$broadcaster->leaveGroup($connection, $groupName);
$broadcaster->broadcast($message);
$broadcaster->broadcastToGroup($groupName, $message);
$broadcaster->sendToUid($uid, $message);
$broadcaster->sendToConnection($connectionId, $message);
$broadcaster->getConnections();
$broadcaster->getGroupConnections($groupName);
```

## 分布式广播

配合 Channel 实现跨服务器广播：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Broadcast\Broadcaster;
use Kode\Process\Channel\Client;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onWorkerStart(function () {
        Client::connect('127.0.0.1', 2206);

        Client::on('broadcast', function ($data) {
            foreach (Kode::getConnections() as $conn) {
                $conn->send($data['message']);
            }
        });
    })
    ->onMessage(function ($conn, $data) {
        $message = json_decode($data, true);
        Client::publish('broadcast', ['message' => $message]);
    })
    ->start();
```
