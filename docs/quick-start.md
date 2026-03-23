# 快速开始

## 第一个服务器

创建 `http_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// 一行启动 HTTP 服务器
Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        $connection->send('Hello World!');
    })
    ->start();
```

运行：

```bash
php http_server.php
```

访问 `http://localhost:8080` 即可看到 "Hello World!"。

## 信号控制

```bash
kode start              # 启动
kode stop              # 停止
kode reload            # 平滑重载
kode status            # 查看状态
```

## 多协议支持

```php
use Kode\Process\Kode;

// HTTP 服务器
Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => $conn->send('HTTP'))
    ->start();

// WebSocket 服务器
Kode::worker('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();

// TCP 服务器
Kode::worker('tcp://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::app([
    'worker_count' => 4,
    'daemonize' => false,
])
->listen('http://0.0.0.0:8080')
->onMessage(function ($connection, $request) {
    $connection->send(json_encode([
        'code' => 0,
        'message' => 'success',
        'data' => [
            'path' => $request['path'] ?? '/',
            'method' => $request['method'] ?? 'GET',
        ]
    ]));
})
->start();
```
