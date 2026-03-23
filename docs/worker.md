# Worker 详解

## 创建 Worker

### 方式一：极简风格（推荐）

```php
use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => $conn->send('Hello'))
    ->start();
```

### 方式二：Workerman 兼容风格

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('tcp://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'MyWorker';
$worker->onMessage = function ($connection, $data) {
    $connection->send('Hello');
};
Worker::runAll();
```

## Worker 属性

```php
$worker = new Worker('tcp://0.0.0.0:8080');

// 进程数量
$worker->count = 4;

// Worker 名称
$worker->name = 'MyWorker';

// 监听地址
$worker->listen = 'tcp://0.0.0.0:8080';

// 传输层协议
$worker->transport = 'tcp';  // tcp, ssl, udp

// 最大连接数
$worker->maxConnections = 1000;

// 用户和组
$worker->user = 'www-data';
$worker->group = 'www-data';

// 重启间隔
$worker->reloadable = true;

// 协议类
$worker->protocol = 'Kode\Process\Protocol\TextProtocol';
```

## 事件回调

### onWorkerStart

Worker 进程启动时触发。

```php
$worker->onWorkerStart = function ($worker) {
    echo "Worker {$worker->id} 启动\n";

    // 初始化数据库连接
    global $db;
    $db = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');

    // 设置全局定时器
    \Kode\Process\Compat\Timer::add(10, function () {
        echo "心跳\n";
    });
};
```

### onWorkerStop

Worker 进程停止时触发。

```php
$worker->onWorkerStop = function ($worker) {
    echo "Worker {$worker->id} 停止\n";

    // 清理资源
    global $db;
    $db = null;
};
```

### onConnect

客户端连接时触发。

```php
$worker->onConnect = function ($connection) {
    echo "新连接: {$connection->id} 来自 {$connection->getRemoteIp()}\n";

    // 设置连接超时
    $connection->timeout = 30;
};
```

### onMessage

收到消息时触发。

```php
$worker->onMessage = function ($connection, $data) {
    echo "收到数据: {$data}\n";

    // 处理请求
    $result = processRequest($data);

    // 发送响应
    $connection->send($result);
};
```

### onClose

连接关闭时触发。

```php
$worker->onClose = function ($connection) {
    echo "连接关闭: {$connection->id}\n";

    // 清理连接相关资源
    cleanupConnection($connection->id);
};
```

### onError

连接错误时触发。

```php
$worker->onError = function ($connection, $code, $msg) {
    echo "错误: {$code} - {$msg}\n";
};
```

### onBufferFull

发送缓冲区满时触发。

```php
$worker->onBufferFull = function ($connection) {
    echo "缓冲区满，暂停读取\n";
    $connection->pauseRecv();
};
```

### onBufferEmpty

发送缓冲区空时触发。

```php
$worker->onBufferEmpty = function ($connection) {
    echo "缓冲区空，恢复读取\n";
    $connection->resumeRecv();
};
```

## Connection 对象

### 属性

```php
// 连接 ID
$connection->id;

// 远程 IP
$connection->getRemoteIp();

// 远程端口
$connection->getRemotePort();

// 本地 IP
$connection->getLocalIp();

// 本地端口
$connection->getLocalPort();

// 协议
$connection->protocol;

// 是否 SSL
$connection->isSsl();
```

### 方法

```php
// 发送数据
$connection->send($data);

// 发送并关闭
$connection->close($data);

// 关闭连接
$connection->destroy();

// 暂停接收
$connection->pauseRecv();

// 恢复接收
$connection->resumeRecv();
```

## 多 Worker 示例

```php
use Kode\Process\Kode;

$app = Kode::app(['worker_count' => 4]);

// HTTP 服务
$app->listen('http://0.0.0.0:8080')
    ->onMessage(fn($conn, $req) => $conn->send('HTTP Response'));

// WebSocket 服务
$app->listen('websocket://0.0.0.0:8081')
    ->onMessage(fn($conn, $data) => $conn->send($data));

$app->start();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Timer;

Kode::worker('tcp://0.0.0.0:9000', 4)
    ->onWorkerStart(function ($worker) {
        if ($worker->id === 0) {
            Timer::add(60, fn() => print "每分钟执行一次\n");
        }
    })
    ->onConnect(fn($conn) => print "新连接: {$conn->id}\n")
    ->onMessage(fn($conn, $data) => $conn->send("收到: {$data}"))
    ->onClose(fn($conn) => print "连接关闭: {$conn->id}\n")
    ->start();
```

> **推荐**：新项目使用 `Kode::worker()` 统一方法，更简洁易用。
