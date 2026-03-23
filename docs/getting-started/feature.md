# 特性说明

## 1、纯 PHP 开发

使用 Kode Process 开发的应用程序不依赖 php-fpm、apache、nginx 这些容器就可以独立运行。这使得 PHP 开发者开发、部署、调试应用程序非常方便。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $req) => $conn->send('Hello World'))
    ->start();
```

## 2、支持 PHP 多进程

为了充分发挥服务器多 CPU 的性能，Kode Process 默认支持多进程多任务。开启一个主进程和多个子进程对外提供服务，主进程负责监控子进程，子进程独自监听网络连接并接收发送及处理数据。

```php
use Kode\Process\Kode;

Kode::worker('tcp://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => $conn->send('Hello'))
    ->start();
```

## 3、支持 TCP、UDP

Kode Process 支持 TCP 和 UDP 两种传输层协议，只需要更改地址前缀即可切换协议，业务代码无需改动。

```php
use Kode\Process\Kode;

// TCP 服务
Kode::worker('tcp://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send('Hello'))
    ->start();

// UDP 服务
Kode::worker('udp://0.0.0.0:9001', 1)
    ->onMessage(fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

## 4、支持长连接

Kode Process 支持长连接，单个进程可以支持上万的并发连接，多进程则支持数十万甚至百万并发连接。

```php
use Kode\Process\Kode;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onConnect(fn($conn) => console.log("新连接: {$conn->id}"))
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

## 5、支持各种应用层协议

Kode Process 支持多种应用层协议，包括自定义协议。更换协议只需修改地址前缀，业务代码零改动。

| 协议 | 地址格式 | 说明 |
|------|----------|------|
| HTTP | `http://0.0.0.0:8080` | HTTP 协议 |
| WebSocket | `websocket://0.0.0.0:8081` | WebSocket 协议 |
| TCP | `tcp://0.0.0.0:9000` | 原始 TCP |
| Text | `text://0.0.0.0:9001` | 文本+换行符 |
| UDP | `udp://0.0.0.0:9002` | UDP 协议 |
| SSL | `ssl://0.0.0.0:443` | SSL/TLS |

```php
use Kode\Process\Kode;

// HTTP 服务
Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $req) => $conn->send('HTTP Response'))
    ->start();

// WebSocket 服务
Kode::worker('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

## 6、支持高并发

Kode Process 支持 Fiber 协程，在长连接高并发时性能非常卓越。

```php
use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($conn, $req) {
        Kode::go(function () use ($conn) {
            $result = heavyOperation();
            $conn->send(json_encode($result));
        });
    })
    ->start();
```

## 7、支持服务平滑重启

Kode Process 提供平滑重启功能，能够保障服务平滑升级，不影响客户端的使用。

```bash
kode start              # 启动服务
kode reload            # 平滑重载
kode status            # 查看状态
```

## 8、支持文件更新检测及自动加载

在开发过程中，修改代码后可以自动重载。

```php
use Kode\Process\Reload\HotReloader;

HotReloader::enable(__DIR__ . '/src');
```

## 9、支持以指定用户运行子进程

为了安全考虑，子进程可以指定用户运行。

```php
use Kode\Process\Kode;

Kode::app(['user' => 'www-data'])
    ->listen('tcp://0.0.0.0:8080')
    ->onMessage(fn($conn, $data) => $conn->send('Hello'))
    ->start();
```

## 10、支持对象或资源永久保持

Kode Process 在运行过程中只会载入解析一次 PHP 文件，然后常驻内存。静态成员或全局变量在不主动销毁的情况下是永久保持的。

```php
use Kode\Process\Kode;

$db = null;

Kode::worker('tcp://0.0.0.0:8080', 4)
    ->onWorkerStart(function () use (&$db) {
        $db = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
    })
    ->onMessage(function ($conn, $data) use (&$db) {
        $stmt = $db->query('SELECT * FROM users');
        $conn->send(json_encode($stmt->fetchAll()));
    })
    ->start();
```

## 11、高性能

由于 PHP 文件常驻内存，减少了磁盘 IO 及 PHP 初始化开销，性能非常高。

## 12、支持多种协程驱动

Kode Process 支持多种协程后端，可以根据需求选择：

| 驱动 | 安装方式 | 性能 | 跨平台 |
|------|----------|------|--------|
| kode/fibers | Composer | 高 | ✅ 全平台 |
| Swow | pecl/composer | 最高 | ✅ 全平台 |

```php
use Kode\Process\Kode;
use Kode\Process\Coroutine\CoroutineManager;

Kode::go(function () {
    echo "协程中执行\n";
});

$results = Kode::batch([1, 2, 3], fn($i) => $i * 2, 2);
```

## 13、支持分布式部署

Kode Process 提供 Channel 和 GlobalData 组件，支持分布式部署。

```php
use Kode\Process\Kode;
use Kode\Process\Channel\Client;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onWorkerStart(function () {
        Client::connect('127.0.0.1', 2206);
    })
    ->onMessage(function ($conn, $data) {
        Client::publish('broadcast', ['message' => $data]);
    })
    ->start();
```

## 14、支持守护进程化

```php
use Kode\Process\Kode;

Kode::app([
    'worker_count' => 4,
    'daemonize' => true,
    'pid_file' => '/var/run/kode.pid',
])
->listen('http://0.0.0.0:8080')
->onMessage(fn($conn, $req) => $conn->send('Hello'))
->start();
```

使用命令控制：

```bash
kode stop   # 停止
kode reload # 重载
kode status # 查看状态
```

## 15、支持多端口监听

```php
use Kode\Process\Kode;

$app = Kode::app(['worker_count' => 4]);

$app->listen('http://0.0.0.0:8080')
    ->onMessage(fn($conn, $req) => $conn->send('HTTP'));

$app->listen('websocket://0.0.0.0:8081')
    ->onMessage(fn($conn, $data) => $conn->send($data));

$app->start();
```

## 16、支持标准输入输出重定向

```php
use Kode\Process\Kode;

Kode::app([
    'worker_count' => 4,
    'stdout_file' => '/var/log/kode/stdout.log',
    'stderr_file' => '/var/log/kode/stderr.log',
])
->listen('http://0.0.0.0:8080')
->onMessage(fn($conn, $req) => $conn->send('Hello'))
->start();
```

## 17、Workerman 兼容

Kode Process 提供完整的 Workerman 兼容层，可以无缝迁移。

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->onMessage = function ($connection, $request) {
    $connection->send('hello world');
};

Worker::runAll();
```

> **推荐**：新项目建议使用 `Kode::worker()` 统一方法，更简洁易用。
