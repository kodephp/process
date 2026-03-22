# Worker 详解

## 创建 Worker

### 方式一：极简风格

```php
use Kode\Process\Kode;

$worker = Kode::http('http://0.0.0.0:8080', 4);
$worker->onMessage(function ($connection, $data) {
    $connection->send('Hello');
});
$worker->start();
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
use Kode\Process\Compat\Worker;

// HTTP 服务
$httpWorker = new Worker('http://0.0.0.0:8080');
$httpWorker->count = 4;
$httpWorker->name = 'HttpWorker';
$httpWorker->onMessage = function ($connection, $request) {
    $connection->send('HTTP Response');
};

// WebSocket 服务
$wsWorker = new Worker('websocket://0.0.0.0:8081');
$wsWorker->count = 2;
$wsWorker->name = 'WebSocketWorker';
$wsWorker->onMessage = function ($connection, $data) {
    $connection->send($data);
};

// 启动所有 Worker
Worker::runAll();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;

// 创建 Worker
$worker = new Worker('tcp://0.0.0.0:9000');
$worker->count = 4;
$worker->name = 'TcpServer';

// 进程启动
$worker->onWorkerStart = function ($worker) {
    // 只在第一个进程执行
    if ($worker->id === 0) {
        Timer::add(60, function () {
            echo "每分钟执行一次\n";
        });
    }
};

// 连接事件
$worker->onConnect = function ($connection) {
    echo "新连接: {$connection->id}\n";
};

// 消息事件
$worker->onMessage = function ($connection, $data) {
    $connection->send("收到: {$data}");
};

// 关闭事件
$worker->onClose = function ($connection) {
    echo "连接关闭: {$connection->id}\n";
};

// 运行
Worker::runAll();
```
