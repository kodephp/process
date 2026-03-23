# Kode Process

**高性能 PHP 进程管理器 | 分布式 | 协程 | 多协议**

[![PHP Version](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache%202.0-green?style=flat-square)](LICENSE)

## 简介

Kode Process 是一款专为高并发场景设计的高性能 PHP 进程管理器，支持分布式集群、Fiber 协程、多协议解析。

## 特性

| 特性 | 说明 |
|------|------|
| 🚀 **极简 API** | `Kode::worker()` 一行启动，自动检测协议 |
| 📡 **多协议** | HTTP、WebSocket、TCP、UDP、Text、SSL |
| 🌐 **分布式** | Channel 通讯、GlobalData 共享、负载均衡 |
| ⚡ **协程** | Fiber 协程支持，百万级并发 |
| 🔄 **平滑重载** | 热更新代码，不中断连接 |
| ⏱️ **定时器** | 一次性、永久、Cron 表达式 |
| 📢 **广播** | 全局广播、群组广播、定向发送 |
| 🔒 **安全** | SSL/TLS、连接认证、进程隔离 |
| 📊 **监控** | 心跳检测、状态监控、进程管理 |
| 🔧 **兼容** | Workerman 零成本迁移 |

## 安装

```bash
composer require kode/process
```

## 快速开始

### HTTP 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($conn, $request) {
        $conn->send('Hello World!');
    })
    ->start();
```

### WebSocket 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

### TCP 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('tcp://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send('Echo: ' . $data))
    ->start();
```

## 命令行工具

```bash
# 启动服务
kode start

# 停止服务
kode stop

# 平滑重载
kode reload

# 查看状态
kode status

# 版本信息
kode info
```

## 支持的协议

| 协议 | 地址格式 | 说明 |
|------|----------|------|
| HTTP | `http://0.0.0.0:8080` | HTTP 服务器 |
| WebSocket | `websocket://0.0.0.0:8081` | WebSocket 服务器 |
| TCP | `tcp://0.0.0.0:9000` | 原始 TCP |
| Text | `text://0.0.0.0:9001` | 文本+换行符 |
| UDP | `udp://0.0.0.0:9002` | UDP 服务器 |
| SSL | `ssl://0.0.0.0:443` | SSL/TLS 加密 |

## 分布式集群

### 架构

```
                    ┌─────────────┐
                    │   Nginx     │
                    │  负载均衡   │
                    └──────┬──────┘
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
   ┌────┴────┐        ┌────┴────┐        ┌────┴────┐
   │ Server A │        │ Server B │        │ Server C │
   │ Worker 1-4│        │ Worker 1-4│        │ Worker 1-4│
   └────┬────┘        └────┬────┘        └────┬────┘
        │                  │                  │
        └──────────────────┼──────────────────┘
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
   ┌────┴────┐        ┌────┴────┐        ┌────┴────┐
   │ Channel │        │GlobalData│        │  Redis  │
   │  :2206  │        │  :2207   │        │  :6379  │
   └─────────┘        └──────────┘        └─────────┘
```

### Channel 分布式通讯

```php
use Kode\Process\Channel\Client;

Client::connect('192.168.1.100', 2206);

Client::on('broadcast', function ($data) {
    echo "收到广播: " . json_encode($data) . "\n";
});

Client::publish('broadcast', [
    'type' => 'message',
    'content' => 'Hello everyone!'
]);
```

### GlobalData 数据共享

```php
use Kode\Process\GlobalData\Client;

$client = new Client('192.168.1.100:2207');

$client->online_count = 0;
$client->increment('online_count', 1);

echo $client->online_count;
```

### 分布式锁

```php
use Kode\Process\GlobalData\Client;

$lock = new Client('192.168.1.100:2207');

$key = 'order_lock_123';
$lock->$key = time() + 10;

if ($lock->$key < time()) {
    unset($lock->$key);
    $lock->$key = time() + 10;
}
```

## 协程支持

```php
use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($conn, $request) {
        Kode::go(function () use ($conn) {
            $result = fetchDataFromApi();
            $conn->send(json_encode($result));
        });
    })
    ->start();

// 批量处理
$results = Kode::batch([1, 2, 3, 4, 5], fn($i) => $i * 2, 3);
```

## 定时器

```php
use Kode\Process\Timer;

Timer::add(2.5, fn() => echo "每 2.5 秒执行\n");
Timer::once(10, fn() => echo "10 秒后执行一次\n");
Timer::cron('* * * * *', fn() => echo "每分钟执行\n");
```

## 广播系统

```php
use Kode\Process\Broadcast\Broadcaster;

$broadcaster = Broadcaster::getInstance();

$broadcaster->broadcast('全局消息');
$broadcaster->broadcastToGroup('room_1', '群组消息');
$broadcaster->sendToUid('user_123', '私人消息');
```

## 队列系统

```php
use Kode\Process\Queue\QueueManager;

QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    });

QueueManager::getInstance()->dispatch('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);
```

## SSL/TLS

```php
use Kode\Process\Kode;

Kode::app([
    'worker_count' => 4,
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
    ]
])
->listen('ssl://0.0.0.0:443')
->onMessage(fn($conn, $data) => $conn->send('Secure response'))
->start();
```

## 性能对比

| 指标 | Kode Process | Workerman | 提升 |
|------|-------------|-----------|------|
| HTTP QPS | 55,000+ | 45,000+ | **+22%** |
| WebSocket 连接 | 120,000+ | 100,000+ | **+20%** |
| Fiber 创建 | 139,000/s | ~100,000/s | **+39%** |
| 内存/进程 | ~8MB | ~10MB | **-20%** |

> 详见 [性能压测](docs/benchmark.md)

## 文档

- [快速开始](docs/quick-start.md)
- [Worker 详解](docs/worker.md)
- [协议系统](docs/protocol.md)
- [分布式集群](docs/distributed.md)
- [协程系统](docs/fiber.md)
- [定时器](docs/timer.md)
- [广播系统](docs/broadcast.md)
- [队列系统](docs/queue.md)
- [生产部署](docs/deployment.md)

## 项目结构

```
src/
├── Kode.php                    # 静态入口类
├── Application.php             # 应用主类
├── Version.php                 # 版本信息
├── Protocol/                    # 协议系统
│   ├── ProtocolInterface.php
│   ├── HttpProtocol.php
│   ├── WebSocketProtocol.php
│   └── ...
├── Channel/                     # Channel 分布式通讯
│   ├── Server.php
│   └── Client.php
├── GlobalData/                  # GlobalData 全局数据
│   ├── Server.php
│   └── Client.php
├── Broadcast/                   # 广播系统
├── Queue/                       # 队列系统
├── Worker/                      # Worker 进程池
├── Master/                      # Master 进程管理
├── Async/                       # 异步工具
├── Compat/                      # Workerman 兼容层
└── ...
```

## 测试

```bash
./vendor/bin/phpunit
```

## 许可证

Apache License 2.0
