# 组合案例：实时聊天系统

本文档展示如何使用 Kode Process 构建一个完整的实时聊天系统。

## 系统架构

```
┌─────────────────────────────────────────────────────────────┐
│                        负载均衡                              │
└─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
┌───────┴───────┐     ┌───────┴───────┐     ┌───────┴───────┐
│  Chat Server  │     │  Chat Server  │     │  Chat Server  │
│   Worker 1-4  │     │   Worker 1-4  │     │   Worker 1-4  │
└───────┬───────┘     └───────┬───────┘     └───────┬───────┘
        │                     │                     │
        └─────────────────────┼─────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
┌───────┴───────┐     ┌───────┴───────┐     ┌───────┴───────┐
│    Channel    │     │  GlobalData   │     │    Redis      │
│    Server     │     │    Server     │     │   (可选)      │
└───────────────┘     └───────────────┘     └───────────────┘
```

## 第一步：启动基础服务

### Channel 服务端

```php
<?php
// channel-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Channel\Server;

$server = new Server('0.0.0.0', 2206);
$server->start();
```

### GlobalData 服务端

```php
<?php
// global-data-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Server;

$server = new Server('0.0.0.0', 2207);
$server->start();
```

## 第二步：创建聊天服务

```php
<?php
// chat-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;
use Kode\Process\Channel\Client as ChannelClient;
use Kode\Process\GlobalData\Client as GlobalDataClient;
use Kode\Process\Broadcast\Broadcaster;

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'ChatServer';

// 全局变量
$globalData = null;
$users = [];

$worker->onWorkerStart = function ($worker) use (&$globalData, &$users) {
    // 连接 Channel 服务端
    ChannelClient::connect('127.0.0.1', 2206);
    
    // 连接 GlobalData 服务端
    $globalData = new GlobalDataClient('127.0.0.1:2207');
    
    // 初始化在线用户数
    if ($worker->id === 0) {
        $globalData->online_count = 0;
        $globalData->message_count = 0;
    }
    
    // 订阅广播事件
    ChannelClient::on('broadcast', function ($data) use ($worker) {
        $broadcaster = Broadcaster::getInstance();
        foreach ($worker->connections as $conn) {
            $conn->send(json_encode($data));
        }
    });
    
    // 订阅私聊事件
    ChannelClient::on('private_message', function ($data) use ($worker) {
        $connId = $data['to_connection_id'];
        if (isset($worker->connections[$connId])) {
            $worker->connections[$connId]->send(json_encode($data));
        }
    });
    
    // 订阅群组消息事件
    ChannelClient::on('group_message', function ($data) use ($worker) {
        $broadcaster = Broadcaster::getInstance();
        $broadcaster->broadcastToGroup($data['group'], json_encode($data));
    });
    
    // 定时广播在线人数
    Timer::add(10, function () use ($worker, &$globalData) {
        $count = $globalData->online_count;
        $broadcaster = Broadcaster::getInstance();
        foreach ($worker->connections as $conn) {
            $conn->send(json_encode([
                'type' => 'online_count',
                'count' => $count
            ]));
        }
    });
};

$worker->onConnect = function ($connection) use (&$globalData) {
    $broadcaster = Broadcaster::getInstance();
    
    // 注册连接
    $broadcaster->register($connection);
    
    // 增加在线人数
    $globalData->increment('online_count', 1);
    
    // 发送欢迎消息
    $connection->send(json_encode([
        'type' => 'welcome',
        'connection_id' => $connection->id,
        'message' => '欢迎加入聊天室'
    ]));
    
    // 广播用户加入
    ChannelClient::publish('broadcast', [
        'type' => 'user_join',
        'connection_id' => $connection->id
    ]);
};

$worker->onMessage = function ($connection, $data) use (&$globalData, &$users) {
    $broadcaster = Broadcaster::getInstance();
    $message = json_decode($data, true);
    
    if (!$message) {
        $connection->send(json_encode([
            'type' => 'error',
            'message' => '无效的消息格式'
        ]));
        return;
    }
    
    switch ($message['type'] ?? 'message') {
        case 'login':
            // 用户登录
            $users[$connection->id] = [
                'uid' => $message['uid'] ?? uniqid(),
                'name' => $message['name'] ?? '匿名用户',
                'avatar' => $message['avatar'] ?? ''
            ];
            
            $broadcaster->register($connection, $message['uid']);
            
            $connection->send(json_encode([
                'type' => 'login_success',
                'user' => $users[$connection->id]
            ]));
            break;
            
        case 'join_room':
            // 加入房间
            $room = $message['room'];
            $broadcaster->joinGroup($connection, $room);
            
            ChannelClient::publish('group_message', [
                'type' => 'room_join',
                'group' => $room,
                'user' => $users[$connection->id] ?? null,
                'connection_id' => $connection->id
            ]);
            break;
            
        case 'leave_room':
            // 离开房间
            $room = $message['room'];
            $broadcaster->leaveGroup($connection, $room);
            break;
            
        case 'room_message':
            // 房间消息
            $globalData->increment('message_count', 1);
            
            ChannelClient::publish('group_message', [
                'type' => 'room_message',
                'group' => $message['room'],
                'user' => $users[$connection->id] ?? null,
                'content' => $message['content'],
                'time' => date('H:i:s')
            ]);
            break;
            
        case 'private_message':
            // 私聊消息
            $globalData->increment('message_count', 1);
            
            ChannelClient::publish('private_message', [
                'type' => 'private_message',
                'from' => $users[$connection->id] ?? null,
                'to_connection_id' => $message['to'],
                'content' => $message['content'],
                'time' => date('H:i:s')
            ]);
            break;
            
        case 'broadcast':
        default:
            // 全局广播
            $globalData->increment('message_count', 1);
            
            ChannelClient::publish('broadcast', [
                'type' => 'message',
                'user' => $users[$connection->id] ?? null,
                'content' => $message['content'] ?? $data,
                'time' => date('H:i:s')
            ]);
            break;
    }
};

$worker->onClose = function ($connection) use (&$globalData, &$users) {
    $broadcaster = Broadcaster::getInstance();
    
    // 减少在线人数
    $globalData->decrement('online_count', 1);
    
    // 注销连接
    $broadcaster->unregister($connection);
    
    // 移除用户
    $user = $users[$connection->id] ?? null;
    unset($users[$connection->id]);
    
    // 广播用户离开
    ChannelClient::publish('broadcast', [
        'type' => 'user_leave',
        'connection_id' => $connection->id,
        'user' => $user
    ]);
};

Worker::runAll();
```

## 第三步：创建 HTTP API

```php
<?php
// api-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Channel\Client as ChannelClient;
use Kode\Process\GlobalData\Client as GlobalDataClient;
use Kode\Process\Response;

$worker = new Worker('http://0.0.0.0:8081');
$worker->count = 2;
$worker->name = 'ApiServer';

$globalData = null;

$worker->onWorkerStart = function ($worker) use (&$globalData) {
    ChannelClient::connect('127.0.0.1', 2206);
    $globalData = new GlobalDataClient('127.0.0.1:2207');
};

$worker->onMessage = function ($connection, $request) use (&$globalData) {
    $path = $request['path'] ?? '/';
    $method = $request['method'] ?? 'GET';
    
    switch ($path) {
        case '/':
            $response = Response::ok([
                'name' => 'Chat API',
                'version' => '1.0.0'
            ]);
            break;
            
        case '/api/stats':
            $response = Response::ok([
                'online_count' => $globalData->online_count ?? 0,
                'message_count' => $globalData->message_count ?? 0
            ]);
            break;
            
        case '/api/broadcast':
            if ($method !== 'POST') {
                $response = Response::error('Method not allowed', 405);
                break;
            }
            
            $content = $request['post']['content'] ?? '';
            ChannelClient::publish('broadcast', [
                'type' => 'system',
                'content' => $content,
                'time' => date('H:i:s')
            ]);
            
            $response = Response::ok(['message' => '已发送']);
            break;
            
        case '/api/push':
            if ($method !== 'POST') {
                $response = Response::error('Method not allowed', 405);
                break;
            }
            
            $toConnectionId = $request['post']['connection_id'] ?? null;
            $content = $request['post']['content'] ?? '';
            
            if (!$toConnectionId) {
                $response = Response::error('缺少 connection_id');
                break;
            }
            
            ChannelClient::publish('private_message', [
                'type' => 'system',
                'to_connection_id' => $toConnectionId,
                'content' => $content,
                'time' => date('H:i:s')
            ]);
            
            $response = Response::ok(['message' => '已发送']);
            break;
            
        default:
            $response = Response::error('Not Found', 404);
    }
    
    $connection->send($response->toJson());
};

Worker::runAll();
```

## 第四步：启动脚本

```bash
# start.sh
#!/bin/bash

# 启动基础服务
php channel-server.php start -d
php global-data-server.php start -d

# 等待基础服务启动
sleep 1

# 启动业务服务
php chat-server.php start -d
php api-server.php start -d

echo "所有服务已启动"
```

```bash
# stop.sh
#!/bin/bash

php chat-server.php stop
php api-server.php stop
php channel-server.php stop
php global-data-server.php stop

echo "所有服务已停止"
```

## 第五步：前端页面

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>实时聊天</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        #messages { height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; }
        .message { margin: 5px 0; padding: 5px; border-radius: 5px; }
        .message.self { background: #e3f2fd; text-align: right; }
        .message.other { background: #f5f5f5; }
        .system { color: #666; font-style: italic; }
        #input { width: 70%; padding: 10px; }
        button { padding: 10px 20px; }
    </style>
</head>
<body>
    <h1>实时聊天室</h1>
    <div>在线人数: <span id="onlineCount">0</span></div>
    <div id="messages"></div>
    <input type="text" id="input" placeholder="输入消息">
    <button onclick="send()">发送</button>
    
    <script>
        const ws = new WebSocket('ws://localhost:8080');
        const messages = document.getElementById('messages');
        const input = document.getElementById('input');
        const onlineCount = document.getElementById('onlineCount');
        
        ws.onopen = function() {
            // 登录
            ws.send(JSON.stringify({
                type: 'login',
                name: '用户' + Math.floor(Math.random() * 1000)
            }));
        };
        
        ws.onmessage = function(e) {
            const data = JSON.parse(e.data);
            
            switch (data.type) {
                case 'welcome':
                    addMessage('系统', data.message, 'system');
                    break;
                    
                case 'online_count':
                    onlineCount.textContent = data.count;
                    break;
                    
                case 'message':
                case 'room_message':
                    addMessage(data.user?.name || '匿名', data.content, 'other');
                    break;
                    
                case 'private_message':
                    addMessage('[私聊] ' + (data.from?.name || '匿名'), data.content, 'other');
                    break;
                    
                case 'user_join':
                    addMessage('系统', '用户加入聊天室', 'system');
                    break;
                    
                case 'user_leave':
                    addMessage('系统', '用户离开聊天室', 'system');
                    break;
            }
        };
        
        function addMessage(user, content, type) {
            const div = document.createElement('div');
            div.className = 'message ' + type;
            div.textContent = user + ': ' + content;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }
        
        function send() {
            const content = input.value.trim();
            if (!content) return;
            
            ws.send(JSON.stringify({
                type: 'broadcast',
                content: content
            }));
            
            input.value = '';
        }
        
        input.onkeypress = function(e) {
            if (e.key === 'Enter') send();
        };
    </script>
</body>
</html>
```

## 运行

```bash
# 启动所有服务
chmod +x start.sh
./start.sh

# 查看状态
php chat-server.php status

# 停止所有服务
./stop.sh
```

## 总结

本案例展示了：

1. **Channel** - 分布式消息广播
2. **GlobalData** - 在线人数统计
3. **Broadcast** - 群组管理
4. **WebSocket** - 实时通信
5. **HTTP API** - 外部接口

这些组件可以灵活组合，构建更复杂的实时应用。
