# 分布式集群

Kode Process 提供完整的分布式集群解决方案，支持跨机器通讯、数据共享、负载均衡。

## 架构概览

```
┌─────────────────────────────────────────────────────────────────┐
│                         负载均衡层                                │
│                      (Nginx/LVS/F5)                              │
└─────────────────────────────────────────────────────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
┌───────┴───────┐     ┌───────┴───────┐     ┌───────┴───────┐
│   Server A    │     │   Server B    │     │   Server C    │
│  Worker 1-4   │     │  Worker 1-4   │     │  Worker 1-4   │
└───────┬───────┘     └───────┬───────┘     └───────┬───────┘
        │                       │                       │
        └───────────────────────┼───────────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
┌───────┴───────┐     ┌───────┴───────┐     ┌───────┴───────┐
│   Channel     │     │  GlobalData   │     │    Redis      │
│   Server      │     │    Server     │     │   (可选)      │
│   :2206       │     │    :2207      │     │   :6379       │
└───────────────┘     └───────────────┘     └───────────────┘
```

## 核心组件

| 组件 | 端口 | 说明 |
|------|------|------|
| Channel | 2206 | 分布式消息订阅发布 |
| GlobalData | 2207 | 跨进程数据共享 |
| Redis | 6379 | 外部缓存（可选） |

## 快速开始

### 1. 启动基础服务

```php
<?php
// channel-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Channel\Server;

$server = new Server('0.0.0.0', 2206);
$server->start();
```

```php
<?php
// global-data-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Server;

$server = new Server('0.0.0.0', 2207);
$server->start();
```

### 2. 启动业务服务

```php
<?php
// server-a.php - 服务器 A
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Channel\Client;
use Kode\Process\GlobalData\Client;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onWorkerStart(function () {
        Client::connect('127.0.0.1', 2206);
    })
    ->onMessage(function ($conn, $data) {
        Client::publish('broadcast', ['msg' => $data]);
        $conn->send('ok');
    })
    ->start();
```

### 3. 使用 kube 命令管理

```bash
# 启动 Channel 服务
php channel-server.php &

# 启动 GlobalData 服务
php global-data-server.php &

# 启动业务服务
php server-a.php &
```

## Channel 分布式通讯

Channel 是基于订阅发布模式的消息队列，用于跨机器实时通讯。

### 消息发布

```php
use Kode\Process\Channel\Client;

// 连接 Channel 服务
Client::connect('192.168.1.100', 2206);

// 发布消息
Client::publish('broadcast', [
    'type' => 'message',
    'content' => 'Hello everyone!',
    'from' => 'server-a'
]);
```

### 消息订阅

```php
use Kode\Process\Channel\Client;

Client::connect('192.168.1.100', 2206);

// 订阅事件
Client::on('broadcast', function ($data) {
    echo "收到广播: " . json_encode($data) . "\n";
});

// 订阅私聊
Client::on('private_message', function ($data) {
    echo "收到私聊: " . json_encode($data) . "\n";
});
```

### RPC 远程调用

```php
use Kode\Process\Channel\Client;

Client::connect('192.168.1.100', 2206);

// 发送 RPC 请求
Client::publish('rpc:user:get', [
    'user_id' => 123,
    'callback_id' => uniqid()
]);
```

## GlobalData 数据共享

GlobalData 提供跨进程共享变量，支持原子操作。

### 基本操作

```php
use Kode\Process\GlobalData\Client;

$client = new Client('192.168.1.100:2207');

// 设置值
$client->online_count = 0;
$client->config = ['timeout' => 30];

// 获取值
echo $client->online_count;

// 删除值
unset($client->online_count);
```

### 原子操作

```php
// 原子递增
$client->increment('online_count', 1);

// 原子递减
$client->decrement('online_count', 1);

// CAS 操作（比较并交换）
$client->cas('lock_key', $oldValue, $newValue);
```

### 批量操作

```php
// 批量设置
$client->setMulti([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3'
]);

// 批量获取
$values = $client->getMulti(['key1', 'key2', 'key3']);
```

## 分布式锁

```php
use Kode\Process\GlobalData\Client;

class DistributedLock
{
    private Client $client;
    private string $prefix = 'lock:';

    public function __construct(string $address = '192.168.1.100:2207')
    {
        $this->client = new Client($address);
    }

    public function lock(string $key, int $expire = 10): bool
    {
        $lockKey = $this->prefix . $key;
        $expireTime = time() + $expire;

        if (!isset($this->client->$lockKey)) {
            $this->client->$lockKey = $expireTime;
            return true;
        }

        if ($this->client->$lockKey < time()) {
            $this->client->$lockKey = $expireTime;
            return true;
        }

        return false;
    }

    public function unlock(string $key): void
    {
        unset($this->client->{$this->prefix . $key});
    }

    public function withLock(string $key, callable $callback, int $expire = 10): mixed
    {
        if (!$this->lock($key, $expire)) {
            throw new Exception("获取锁失败: {$key}");
        }

        try {
            return $callback();
        } finally {
            $this->unlock($key);
        }
    }
}

$lock = new DistributedLock();
$lock->withLock('order_123', fn() => processOrder(123), 30);
```

## 分布式计数器

```php
use Kode\Process\GlobalData\Client;

class DistributedCounter
{
    private Client $client;

    public function __construct(string $address = '192.168.1.100:2207')
    {
        $this->client = new Client($address);
    }

    public function inc(string $key, int $step = 1): int
    {
        return (int)($this->client->increment($key, $step) ?? 0);
    }

    public function dec(string $key, int $step = 1): int
    {
        return (int)($this->client->decrement($key, $step) ?? 0);
    }

    public function get(string $key): int
    {
        return (int)($this->client->$key ?? 0);
    }

    public function reset(string $key): void
    {
        $this->client->$key = 0;
    }
}

$counter = new DistributedCounter();
$counter->inc('page_views');
echo "访问量: " . $currentCount = $counter->get('page_views');
```

## 分布式 Session

```php
use Kode\Process\GlobalData\Client;

class DistributedSession
{
    private Client $client;
    private string $sessionId;
    private int $ttl = 3600;

    public function __construct(string $sessionId, string $address = '192.168.1.100:2207')
    {
        $this->client = new Client($address);
        $this->sessionId = 'session:' . $sessionId;
    }

    public function set(string $key, mixed $value): void
    {
        $this->client->{$this->sessionId . ':' . $key} = [
            'value' => $value,
            'expire' => time() + $this->ttl
        ];
    }

    public function get(string $key): mixed
    {
        $data = $this->client->{$this->sessionId . ':' . $key};

        if (!$data) {
            return null;
        }

        if ($data['expire'] < time()) {
            unset($this->client->{$this->sessionId . ':' . $key});
            return null;
        }

        return $data['value'];
    }

    public function destroy(): void
    {
        $keys = $this->client->keys($this->sessionId . ':*');
        foreach ($keys as $key) {
            unset($this->client->$key);
        }
    }
}

$session = new DistributedSession($_COOKIE['session_id'] ?? uniqid());
$session->set('user_id', 123);
$userId = $session->get('user_id');
```

## 负载均衡

### Nginx 配置

```nginx
upstream kode_cluster {
    least_conn;
    server 192.168.1.101:8080 weight=5;
    server 192.168.1.102:8080 weight=3;
    server 192.168.1.103:8080 weight=2;
}

server {
    listen 80;
    server_name example.com;

    location / {
        proxy_pass http://kode_cluster;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }
}
```

### WebSocket 负载均衡

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}

upstream kode_ws_cluster {
    least_conn;
    server 192.168.1.101:8081;
    server 192.168.1.102:8081;
    server 192.168.1.103:8081;
}

server {
    listen 80;
    server_name ws.example.com;

    location / {
        proxy_pass http://kode_ws_cluster;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 86400;
    }
}
```

## 服务发现

```php
use Kode\Process\GlobalData\Client;

class ServiceRegistry
{
    private Client $client;
    private string $prefix = 'service:';

    public function __construct(string $address = '192.168.1.100:2207')
    {
        $this->client = new Client($address);
    }

    public function register(string $service, string $host, int $port): void
    {
        $key = $this->prefix . $service;
        $services = $this->client->$key ?? [];

        $services[] = [
            'host' => $host,
            'port' => $port,
            'registered' => time()
        ];

        $this->client->$key = $services;
    }

    public function unregister(string $service, string $host, int $port): void
    {
        $key = $this->prefix . $service;
        $services = $this->client->$key ?? [];

        $services = array_filter($services, fn($s) =>
            !($s['host'] === $host && $s['port'] === $port)
        );

        $this->client->$key = array_values($services);
    }

    public function discover(string $service): ?array
    {
        $key = $this->prefix . $service;
        $services = $this->client->$key ?? [];

        if (empty($services)) {
            return null;
        }

        return $services[array_rand($services)];
    }
}

$registry = new ServiceRegistry();
$registry->register('api', '192.168.1.101', 8080);
$api = $registry->discover('api');
```

## 完整示例：分布式聊天

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

### 聊天服务

```php
<?php
// chat-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Channel\Client;
use Kode\Process\GlobalData\Client;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onWorkerStart(function () {
        Client::connect('127.0.0.1', 2206);

        Client::on('broadcast', function ($data) {
            foreach (Kode::getConnections() as $conn) {
                $conn->send(json_encode($data));
            }
        });

        Client::on('private_message', function ($data) {
            $targetConnId = $data['to_connection_id'] ?? null;
            if ($targetConnId) {
                $conn = Kode::getConnection($targetConnId);
                if ($conn) {
                    $conn->send(json_encode($data));
                }
            }
        });
    })
    ->onConnect(function ($conn) {
        $globalData = new Client('127.0.0.1:2207');
        $globalData->increment('online_count', 1);
        $conn->send(json_encode([
            'type' => 'system',
            'content' => '欢迎加入聊天室'
        ]));
    })
    ->onMessage(function ($conn, $data) {
        $message = json_decode($data, true);
        $type = $message['type'] ?? 'message';

        switch ($type) {
            case 'broadcast':
                Client::publish('broadcast', [
                    'type' => 'message',
                    'from' => $conn->id,
                    'content' => $message['content'] ?? ''
                ]);
                break;

            case 'private':
                Client::publish('private_message', [
                    'type' => 'private',
                    'from_connection_id' => $conn->id,
                    'to_connection_id' => $message['to'] ?? '',
                    'content' => $message['content'] ?? ''
                ]);
                break;
        }
    })
    ->onClose(function ($conn) {
        $globalData = new Client('127.0.0.1:2207');
        $globalData->decrement('online_count', 1);
    })
    ->start();
```

### HTTP API 服务

```php
<?php
// api-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Channel\Client;
use Kode\Process\GlobalData\Client;

Kode::worker('http://0.0.0.0:8081', 2)
    ->onWorkerStart(function () {
        Client::connect('127.0.0.1', 2206);
    })
    ->onMessage(function ($conn, $request) {
        $path = $request['path'] ?? '/';

        switch ($path) {
            case '/api/stats':
                $globalData = new Client('127.0.0.1:2207');
                $conn->send(json_encode([
                    'code' => 0,
                    'data' => [
                        'online' => $globalData->online_count ?? 0
                    ]
                ]));
                break;

            case '/api/broadcast':
                $content = $request['post']['content'] ?? '';
                Client::publish('broadcast', [
                    'type' => 'system',
                    'content' => $content
                ]);
                $conn->send(json_encode(['code' => 0]));
                break;

            default:
                $conn->send(json_encode(['code' => 404]));
        }
    })
    ->start();
```

## 部署清单

- [ ] 部署 Channel Server (端口 2206)
- [ ] 部署 GlobalData Server (端口 2207)
- [ ] 部署业务服务 (多个实例)
- [ ] 配置 Nginx 负载均衡
- [ ] 配置防火墙规则
- [ ] 配置日志收集
- [ ] 配置监控告警
