# Workerman 兼容

`kode/process` 提供完整的 Workerman 兼容层，可以无缝迁移。

## 快速迁移

### 安装

```bash
composer require kode/process
```

### 修改引入

```php
// 原来的
// require __DIR__ . '/vendor/workerman/workerman/Autoloader.php';

// 改为
require __DIR__ . '/vendor/autoload.php';
```

### 命名空间映射

| Workerman | Kode Process |
|-----------|--------------|
| Workerman\Worker | Kode\Process\Compat\Worker |
| Workerman\Timer | Kode\Process\Compat\Timer |
| Workerman\Connection\TcpConnection | Kode\Process\Compat\StreamConnection |
| Workerman\Protocols\Http | Kode\Process\Protocol\HttpProtocol |

## 基本用法

### 创建 Worker

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

### 定时器

```php
use Kode\Process\Compat\Timer;

// 永久定时器
Timer::add(1, function () {
    echo "每秒执行\n";
});

// 一次性定时器
Timer::add(5, function () {
    echo "5秒后执行\n";
}, [], false);

// 删除定时器
Timer::del($timerId);
```

### HTTP 服务

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('http://0.0.0.0:8080');

$worker->onMessage = function ($connection, $request) {
    $path = $request['path'];
    $method = $request['method'];
    
    $connection->send(json_encode([
        'code' => 0,
        'data' => [
            'path' => $path,
            'method' => $method
        ]
    ]));
};

Worker::runAll();
```

### WebSocket 服务

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('websocket://0.0.0.0:8081');

$worker->onConnect = function ($connection) {
    echo "WebSocket 连接: {$connection->id}\n";
};

$worker->onMessage = function ($connection, $data) {
    $connection->send("收到: {$data}");
};

$worker->onClose = function ($connection) {
    echo "WebSocket 关闭: {$connection->id}\n";
};

Worker::runAll();
```

## 兼容性说明

### 完全兼容

- ✅ Worker 类
- ✅ Timer 定时器
- ✅ 协议系统
- ✅ 连接对象
- ✅ 事件回调
- ✅ 命令行参数

### 增强功能

- ✅ Fiber 协程支持
- ✅ 队列系统
- ✅ Channel 分布式
- ✅ GlobalData 全局数据
- ✅ 更好的性能

### 差异说明

| 功能 | Workerman | Kode Process |
|------|-----------|--------------|
| 协程 | 需要安装 | 内置支持 |
| 队列 | 需要安装 | 内置支持 |
| 分布式 | 需要安装 | 内置支持 |
| HTTP/2 | 不支持 | 支持 |

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;

// HTTP 服务
$http = new Worker('http://0.0.0.0:8080');
$http->count = 4;
$http->name = 'HttpServer';

$http->onWorkerStart = function ($worker) {
    // 只在第一个进程设置定时器
    if ($worker->id === 0) {
        Timer::add(60, function () {
            echo "每分钟清理\n";
        });
    }
};

$http->onMessage = function ($connection, $request) {
    $path = $request['path'] ?? '/';
    
    switch ($path) {
        case '/':
            $connection->send(json_encode(['code' => 0, 'msg' => 'ok']));
            break;
        case '/api/users':
            $connection->send(json_encode(['code' => 0, 'data' => []]));
            break;
        default:
            $connection->send(json_encode(['code' => 404, 'msg' => 'Not Found']));
    }
};

// WebSocket 服务
$ws = new Worker('websocket://0.0.0.0:8081');
$ws->count = 2;
$ws->name = 'WebSocketServer';

$ws->onMessage = function ($connection, $data) {
    $connection->send($data);
};

Worker::runAll();
```

## 迁移检查清单

- [ ] 修改 `require` 引入
- [ ] 检查命名空间
- [ ] 测试所有功能
- [ ] 检查自定义协议
- [ ] 检查定时器
- [ ] 检查事件回调
