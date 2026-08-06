# 特性说明

## 1、纯 PHP 开发

使用 Kode Process 开发的应用程序不依赖 php-fpm、apache、nginx 这些容器就可以独立运行。这使得 PHP 开发者开发、部署、调试应用程序非常方便。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', fn($conn, $req) => $conn->send('Hello World'))
    ->start();
```

## 2、支持 PHP 多进程

为了充分发挥服务器多 CPU 的性能，Kode Process 默认支持多进程多任务。开启一个主进程和多个子进程对外提供服务，主进程负责监控子进程，子进程独自监听网络连接并接收发送及处理数据（Swoole / Workerman 运行时均为 master-worker 模型）。

```php
use Kode\Process\Kode;

Kode::serve('tcp://0.0.0.0:8080', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send('Hello'))
    ->start();
```

## 3、支持 TCP、UDP

Kode Process 支持 TCP 和 UDP 两种传输层协议，只需要更改地址前缀即可切换协议，业务代码无需改动。

> UDP 仅 Swoole / Workerman 运行时支持，需显式指定运行时。

```php
use Kode\Process\Kode;

// TCP 服务
Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send('Hello'))
    ->start();

// UDP 服务（指定运行时）
Kode::serve('udp://0.0.0.0:9001', ['workers' => 1], 'swoole')
    ->on('message', fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

## 4、支持长连接

Kode Process 支持长连接，单个进程可以支持上万的并发连接，多进程则支持数十万甚至百万并发连接。

```php
use Kode\Process\Kode;

Kode::serve('websocket://0.0.0.0:8080', ['workers' => 4])
    ->on('connect', fn($conn) => printf("新连接: %d\n", $conn->id()))
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```

## 5、支持各种应用层协议

Kode Process 支持多种应用层协议，包括自定义协议。更换协议只需修改地址前缀，业务代码零改动。

| 协议 | 地址格式 | 运行时支持 |
|------|----------|-----------|
| HTTP | `http://0.0.0.0:8080` | 全部 |
| WebSocket | `websocket://0.0.0.0:8081` | 全部 |
| TCP | `tcp://0.0.0.0:9000` | 全部 |
| Text | `text://0.0.0.0:9001` | 全部 |
| Unix Socket | `unix:///tmp/app.sock` | 全部 |
| UDP | `udp://0.0.0.0:9002` | 仅 Swoole / Workerman |
| SSL | `ssl://0.0.0.0:443` | 全部（需 ext-openssl） |

```php
use Kode\Process\Kode;

// HTTP 服务
Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', fn($conn, $req) => $conn->send('HTTP Response'))
    ->start();

// WebSocket 服务
Kode::serve('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```

## 6、支持高并发

Kode Process 支持 Fiber 协程（委托 `kode/fibers`），在长连接高并发时性能非常卓越。

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($conn, $req) {
        Kode::go(function () use ($conn) {
            $result = heavyOperation();
            $conn->send(json_encode($result));
        });
    })
    ->start();
```

## 7、支持服务平滑重启

Kode Process 提供平滑重启功能（依赖 `pcntl` / `posix` 信号机制），能够保障服务平滑升级，不影响客户端的使用。

```bash
kode start              # 启动服务
kode reload            # 平滑重载
kode status            # 查看状态
```

运行中的服务也可用 `kill -USR1 $PID` 触发平滑重载。

## 8、支持请求数触发的自动重载

`HotReloader` 可按累计请求数自动重启 worker，适合开发期快速回收内存或更新逻辑：

```php
use Kode\Process\Reload\HotReloader;

$reloader = HotReloader::getInstance()->setMaxRequests(1000)->enable();

// 每次处理完请求后调用
$reloader->increment();
```

> 注：内置 `HotReloader` 基于请求计数，不监听文件变更；文件级热更新建议配合 `maxRequest` 选项或外部文件监视器。

## 9、支持对象或资源永久保持

Kode Process 在运行过程中只会载入解析一次 PHP 文件，然后常驻内存。静态成员或全局变量在不主动销毁的情况下是永久保持的。

```php
use Kode\Process\Kode;

$db = null;

Kode::serve('tcp://0.0.0.0:8080', ['workers' => 4])
    ->on('workerStart', function () use (&$db) {
        $db = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
    })
    ->on('message', function ($conn, $data) use (&$db) {
        $stmt = $db->query('SELECT * FROM users');
        $conn->send(json_encode($stmt->fetchAll()));
    })
    ->start();
```

## 10、高性能

由于 PHP 文件常驻内存，减少了磁盘 IO 及 PHP 初始化开销，性能非常高。关于网络吞吐的定位，详见 [五维硬门槛判定报告](gate-report.md)：本包不追求「比 Workerman 更快的网络 I/O」，而是提供「一套 API 在 Swoole / Workerman 之间可移植」的进程编排内核。

## 11、支持协程并发原语

Kode Process 通过 `kode/fibers` 提供协程能力，单线程 I/O 并发：

```php
use Kode\Process\Kode;

Kode::go(function () {
    echo "协程中执行\n";
});

$results = Kode::batch([1, 2, 3], fn($i) => $i * 2, 2);
```

## 12、跨进程共享数据

通过 `SharedTable` 实现同主机多进程共享数据，零安装择优（apcu → sysvshm）；也可复用 Swoole / Workerman 的表。跨主机共享请使用网络版 `GlobalData`。详见 [共享数据文档](global-data.md)。

```php
use Kode\Process\Kode;

$table = Kode::table();
$table->set('online', 0);
$table->increment('online', 1);
```

## 13、支持进程间消息（IPC）

同主机多进程可通过内置 IPC 机制交换消息；跨主机分布式通讯请使用网络版 `GlobalData`（概念源自 Workerman 的 GlobalData 组件）。

```php
use Kode\Process\Kode;
use Kode\Process\GlobalData\Client;

$client = new Client('192.168.1.100:2207');
$client->online_count = 0;
$client->increment('online_count', 1);
echo $client->online_count;
```

## 14、部署形态

运行时默认前台运行。生产守护化建议交给 `nohup`、systemd 或 supervisor 托管，`maxRequest` 选项可让 worker 在处理若干请求后自动重启以回收资源：

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', [
    'workers'    => 4,
    'name'       => 'demo-http',
    'maxRequest' => 10000,
    'reusePort'  => true,
])
    ->on('message', fn($conn, $req) => $conn->send('Hello'))
    ->start();
```

使用命令控制：

```bash
kode stop   # 停止
kode reload # 重载
kode status # 查看状态
```

## 15、支持多端口监听

`Kode::serve()` 一次监听一个地址；多端口请用 `Kode::runtime()` 多次 `listen`：

```php
use Kode\Process\Kode;

$rt = Kode::runtime();

$rt->listen('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', fn($conn, $req) => $conn->send('HTTP'));

$rt->listen('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data));

$rt->start();
```

## 16、Workerman 兼容

若项目已基于 Workerman，可显式指定运行时复用其成熟能力（需 `composer require workerman/workerman ^5.0`）：

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4], 'workerman')
    ->on('message', fn($conn, $req) => $conn->send('hello world'))
    ->start();
```

> **推荐**：新项目直接用 `Kode::serve()`，由环境自动择优运行时，部署更灵活。
