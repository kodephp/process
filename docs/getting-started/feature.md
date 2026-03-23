# 特性说明

## 1、纯 PHP 开发

使用 Kode Process 开发的应用程序不依赖 php-fpm、apache、nginx 这些容器就可以独立运行。这使得 PHP 开发者开发、部署、调试应用程序非常方便。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// 一行代码启动 HTTP 服务器
Kode::http('http://0.0.0.0:8080')
    ->onMessage(fn($conn, $req) => $conn->send('Hello World'))
    ->start();
```

## 2、支持 PHP 多进程

为了充分发挥服务器多 CPU 的性能，Kode Process 默认支持多进程多任务。开启一个主进程和多个子进程对外提供服务，主进程负责监控子进程，子进程独自监听网络连接并接收发送及处理数据。

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('tcp://0.0.0.0:8080');
$worker->count = 4;  // 4 个子进程
$worker->onMessage = function ($connection, $data) {
    $connection->send('Hello');
};
Worker::runAll();
```

## 3、支持 TCP、UDP

Kode Process 支持 TCP 和 UDP 两种传输层协议，只需要更改地址前缀即可切换协议，业务代码无需改动。

```php
// TCP 服务
$tcp = new Worker('tcp://0.0.0.0:9000');

// UDP 服务
$udp = new Worker('udp://0.0.0.0:9001');
```

## 4、支持长连接

Kode Process 支持长连接，单个进程可以支持上万的并发连接，多进程则支持数十万甚至百万并发连接。

```php
$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 4;

$worker->onConnect = function ($connection) {
    echo "新连接: {$connection->id}\n";
};

$worker->onMessage = function ($connection, $data) {
    // 保持连接，不主动关闭
    $connection->send($data);
};
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

## 6、支持高并发

Kode Process 支持 Fiber 协程，在长连接高并发时性能非常卓越。

```php
use Kode\Process\Coroutine\CoroutineManager;

$manager = CoroutineManager::getInstance();

// 创建协程
$manager->go(function () {
    // 异步处理
    $result = asyncOperation();
    return $result;
});

// 批量并发
$results = $manager->batch($items, function ($item) {
    return processItem($item);
}, 10);  // 并发 10 个
```

## 7、支持服务平滑重启

Kode Process 提供平滑重启功能，能够保障服务平滑升级，不影响客户端的使用。

```bash
# 启动服务
kode start

# 平滑重载（不中断现有连接）
kode reload

# 完全重启
kode stop && kode start

# 查看状态
kode status
```

## 8、支持文件更新检测及自动加载

在开发过程中，修改代码后可以自动重载。

```php
use Kode\Process\Reload\HotReload;

// 开启热重载（仅开发环境）
HotReload::enable(__DIR__ . '/src');
```

## 9、支持以指定用户运行子进程

为了安全考虑，子进程可以指定用户运行。

```php
$worker = new Worker('tcp://0.0.0.0:8080');
$worker->user = 'www-data';
$worker->group = 'www-data';
```

## 10、支持对象或资源永久保持

Kode Process 在运行过程中只会载入解析一次 PHP 文件，然后常驻内存。静态成员或全局变量在不主动销毁的情况下是永久保持的。

```php
$worker->onWorkerStart = function ($worker) {
    // 只初始化一次，所有请求复用
    global $db;
    $db = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
};

$worker->onMessage = function ($connection, $data) {
    global $db;
    // 复用数据库连接
    $stmt = $db->query('SELECT * FROM users');
    $connection->send(json_encode($stmt->fetchAll()));
};
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
use Kode\Process\Coroutine\CoroutineManager;

// 使用 kode/fibers（默认）
$manager = CoroutineManager::getInstance('fibers');

// 使用 Swow（需要安装扩展）
$manager = CoroutineManager::getInstance('swow');
```

## 13、支持分布式部署

Kode Process 提供 Channel 和 GlobalData 组件，支持分布式部署。

```php
// Channel 分布式通讯
use Kode\Process\Channel\Client;
Client::connect('127.0.0.1', 2206);
Client::publish('event', ['data' => 'value']);

// GlobalData 全局数据共享
use Kode\Process\GlobalData\Client;
$global = new Client('127.0.0.1:2207');
$global->counter = 0;
$global->increment('counter', 1);
```

## 14、支持守护进程化

守护进程模式启动后，使用信号控制：

```bash
kill -TERM $PID  # 停止
kill -HUP $PID   # 重载
kill -USR2 $PID  # 查看状态
```

## 15、支持多端口监听

```php
use Kode\Process\Compat\Worker;

// HTTP 服务
$http = new Worker('http://0.0.0.0:8080');
$http->count = 4;
$http->onMessage = function ($connection, $request) {
    $connection->send('HTTP Response');
};

// WebSocket 服务
$ws = new Worker('websocket://0.0.0.0:8081');
$ws->count = 2;
$ws->onMessage = function ($connection, $data) {
    $connection->send($data);
};

// 同时运行多个 Worker
Worker::runAll();
```

## 16、支持标准输入输出重定向

```php
$worker = new Worker('tcp://0.0.0.0:8080');
$worker->stdoutFile = '/var/log/kode-process/stdout.log';
$worker->stderrFile = '/var/log/kode-process/stderr.log';
```

## 17、Workerman 兼容

Kode Process 提供完整的 Workerman 兼容层，可以无缝迁移。

```php
// 原来的 Workerman 代码
// use Workerman\Worker;

// 改为
use Kode\Process\Compat\Worker;

// 其他代码无需修改
```
