# 快速开始

## 第一个服务器

创建 `server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// 一行启动 HTTP 服务器
Kode::http('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        $connection->send('Hello World!');
    })
    ->start();
```

运行：

```bash
php server.php start
```

访问 `http://localhost:8080` 即可看到 "Hello World!"。

## 命令行参数

```bash
php server.php start      # 启动
php server.php start -d   # 守护进程模式启动
php server.php stop       # 停止
php server.php restart    # 重启
php server.php reload     # 平滑重载
php server.php status     # 查看状态
php server.php connections # 查看连接
```

## 多协议支持

```php
use Kode\Process\Kode;

// HTTP 服务器
Kode::http('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => $conn->send('HTTP'))
    ->start();

// WebSocket 服务器
Kode::websocket('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();

// TCP 服务器
Kode::tcp('tcp://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();

// Text 协议（文本+换行符）
Kode::text('text://0.0.0.0:9001', 4)
    ->onMessage(fn($conn, $data) => $conn->send("收到: {$data}\n"))
    ->start();
```

## 自动检测协议

```php
use Kode\Process\Kode;

// 根据地址自动检测协议
Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => handleRequest($data))
    ->start();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Response;

// 创建 HTTP 服务器
$app = Kode::http('http://0.0.0.0:8080', 4);

// 连接事件
$app->onConnect(function ($connection) {
    echo "新连接: {$connection->id}\n";
});

// 消息事件
$app->onMessage(function ($connection, $request) {
    // 解析请求
    $path = $request['path'] ?? '/';
    $method = $request['method'] ?? 'GET';
    
    // 路由处理
    switch ($path) {
        case '/':
            $response = Response::ok(['message' => 'Welcome']);
            break;
        case '/api/users':
            $response = Response::ok(['users' => []]);
            break;
        default:
            $response = Response::error('Not Found', 404);
    }
    
    $connection->send($response->toJson());
});

// 关闭事件
$app->onClose(function ($connection) {
    echo "连接关闭: {$connection->id}\n";
});

// 启动服务器
$app->start();
```

## 下一步

- [Worker 详解](worker.md) - 了解更多配置选项
- [协议系统](protocol.md) - 自定义协议
- [定时器](timer.md) - 定时任务
