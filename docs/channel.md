# Channel 分布式通讯

Channel 是基于订阅发布模型的分布式通讯组件。

## 架构

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Server A   │     │  Server B   │     │  Server C   │
│  Worker 1-4 │     │  Worker 1-4 │     │  Worker 1-4 │
└──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │                   │                   │
       └───────────────────┼───────────────────┘
                           │
                    ┌──────┴──────┐
                    │   Channel   │
                    │   Server    │
                    │  (2206端口)  │
                    └─────────────┘
```

## Channel 服务端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Channel\Server;

// 启动 Channel 服务端
$server = new Server('0.0.0.0', 2206);
$server->start();
```

## Channel 客户端

### 连接服务端

```php
use Kode\Process\Channel\Client;

Client::connect('127.0.0.1', 2206);
```

### 订阅事件

```php
use Kode\Process\Channel\Client;

Client::on('broadcast', function ($data) {
    echo "收到广播: " . json_encode($data) . "\n";
});

Client::on('user_message', function ($data) {
    echo "用户消息: {$data['message']}\n";
});
```

### 发布事件

```php
use Kode\Process\Channel\Client;

// 发布事件
Client::publish('broadcast', [
    'message' => 'Hello everyone!'
]);

Client::publish('user_message', [
    'user_id' => 123,
    'message' => 'Hello'
]);
```

## 完整示例：广播聊天

### 服务端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Channel\Client;

// WebSocket 服务
$wsWorker = new Worker('websocket://0.0.0.0:8080');
$wsWorker->count = 4;
$wsWorker->name = 'ChatServer';

$wsWorker->onWorkerStart = function ($worker) {
    // 连接 Channel 服务端
    Client::connect('127.0.0.1', 2206);
    
    // 订阅广播事件
    Client::on('broadcast', function ($data) use ($worker) {
        foreach ($worker->connections as $conn) {
            $conn->send($data['message']);
        }
    });
    
    // 订阅私聊事件
    Client::on('private_message', function ($data) use ($worker) {
        $connId = $data['connection_id'];
        if (isset($worker->connections[$connId])) {
            $worker->connections[$connId]->send($data['message']);
        }
    });
};

$wsWorker->onConnect = function ($connection) {
    echo "新连接: {$connection->id}\n";
};

$wsWorker->onMessage = function ($connection, $data) {
    $message = json_decode($data, true);
    
    if ($message['type'] === 'broadcast') {
        // 广播给所有连接
        Client::publish('broadcast', [
            'message' => $message['content']
        ]);
    } elseif ($message['type'] === 'private') {
        // 发送给特定连接
        Client::publish('private_message', [
            'connection_id' => $message['to'],
            'message' => $message['content']
        ]);
    }
};

$wsWorker->onClose = function ($connection) {
    echo "连接关闭: {$connection->id}\n";
};

Worker::runAll();
```

### HTTP 推送服务

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Channel\Client;

// HTTP 推送服务
$httpWorker = new Worker('http://0.0.0.0:8081');
$httpWorker->count = 2;
$httpWorker->name = 'PushServer';

$httpWorker->onWorkerStart = function () {
    Client::connect('127.0.0.1', 2206);
};

$httpWorker->onMessage = function ($connection, $request) {
    $content = $request['post']['content'] ?? '';
    $type = $request['post']['type'] ?? 'broadcast';
    
    if ($type === 'broadcast') {
        // 广播
        Client::publish('broadcast', ['message' => $content]);
    } else {
        // 定向推送
        $toWorkerId = $request['post']['worker_id'] ?? null;
        $toConnId = $request['post']['connection_id'] ?? null;
        
        if ($toWorkerId && $toConnId) {
            Client::publish($toWorkerId, [
                'to_connection_id' => $toConnId,
                'content' => $content
            ]);
        }
    }
    
    $connection->send(json_encode(['code' => 0, 'msg' => 'ok']));
};

Worker::runAll();
```

## API 参考

```php
use Kode\Process\Channel\Client;

// 连接
Client::connect('127.0.0.1', 2206);

// 订阅
Client::on('event_name', function ($data) {
    // 处理事件
});

// 取消订阅
Client::off('event_name');

// 发布
Client::publish('event_name', ['key' => 'value']);

// 检查连接状态
Client::isConnected();  // bool

// 重连
Client::reconnect();

// 断开
Client::disconnect();

// 处理消息（在主循环中自动调用）
Client::tick();
```

## 部署建议

1. **Channel 服务端** - 单独部署一台服务器
2. **多服务器** - 所有业务服务器连接 Channel 服务端
3. **监控** - 监控 Channel 服务端连接数和消息量
