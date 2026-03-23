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
Client::on('broadcast', function ($data) {
    echo "收到广播: " . json_encode($data) . "\n";
});

Client::on('user_message', function ($data) {
    echo "用户消息: {$data['message']}\n";
});
```

### 发布事件

```php
Client::publish('broadcast', ['message' => 'Hello everyone!']);
Client::publish('user_message', ['user_id' => 123, 'message' => 'Hello']);
```

## 完整示例：广播聊天

### 服务端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Channel\Client;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onWorkerStart(function () {
        Client::connect('127.0.0.1', 2206);

        Client::on('broadcast', function ($data) {
            // 广播给所有连接
            foreach (Kode::getConnections() as $conn) {
                $conn->send($data['message']);
            }
        });
    })
    ->onMessage(function ($conn, $data) {
        $message = json_decode($data, true);

        if ($message['type'] === 'broadcast') {
            Client::publish('broadcast', ['message' => $message['content']]);
        }
    })
    ->start();
```

### HTTP 推送服务

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Channel\Client;

Kode::worker('http://0.0.0.0:8081', 2)
    ->onWorkerStart(fn() => Client::connect('127.0.0.1', 2206))
    ->onMessage(function ($conn, $request) {
        $content = $request['post']['content'] ?? '';
        Client::publish('broadcast', ['message' => $content]);
        $conn->send(json_encode(['code' => 0, 'msg' => 'ok']));
    })
    ->start();
```

## API 参考

```php
use Kode\Process\Channel\Client;

Client::connect('127.0.0.1', 2206);
Client::on('event_name', fn($data) => handle($data));
Client::off('event_name');
Client::publish('event_name', ['key' => 'value']);
Client::isConnected();
Client::reconnect();
Client::disconnect();
Client::tick();
```

## 部署建议

1. **Channel 服务端** - 单独部署一台服务器
2. **多服务器** - 所有业务服务器连接 Channel 服务端
3. **监控** - 监控 Channel 服务端连接数和消息量
