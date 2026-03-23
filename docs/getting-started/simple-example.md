# 简单的开发示例

## 安装

在一个空目录中运行：

```bash
composer require kode/process
```

## 示例一、使用 HTTP 协议对外提供 Web 服务

创建 `start.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;

// 创建一个 Worker 监听 8080 端口，使用 HTTP 协议通讯
$httpWorker = new Worker('http://0.0.0.0:8080');

// 启动 4 个进程对外提供服务
$httpWorker->count = 4;

// 接收到浏览器发送的数据时回复 hello world
$httpWorker->onMessage = function ($connection, $request) {
    // 向浏览器发送 hello world
    $connection->send('hello world');
};

// 运行 Worker
Worker::runAll();
```

### 命令行运行

```bash
php start.php start
```

### 测试

在浏览器中访问 `http://127.0.0.1:8080`，即可看到 "hello world"。

> **注意**：
> 1. 如果出现无法访问的情况，请检查防火墙设置
> 2. 服务端是 HTTP 协议，只能用 HTTP 协议通讯

---

## 示例二、使用 WebSocket 协议对外提供服务

创建 `ws_test.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;

// 注意：这里使用的是 websocket 协议
$wsWorker = new Worker('websocket://0.0.0.0:2000');

// 启动 4 个进程对外提供服务
$wsWorker->count = 4;

// 当收到客户端发来的数据后返回 hello $data 给客户端
$wsWorker->onMessage = function ($connection, $data) {
    // 向客户端发送 hello $data
    $connection->send('hello ' . $data);
};

// 运行 Worker
Worker::runAll();
```

### 命令行运行

```bash
php ws_test.php start
```

### 测试

打开 Chrome 浏览器，按 F12 打开调试控制台，在 Console 一栏输入：

```javascript
// 假设服务端 IP 为 127.0.0.1
ws = new WebSocket('ws://127.0.0.1:2000');
ws.onopen = function() {
    alert('连接成功');
    ws.send('tom');
    alert('给服务端发送一个字符串：tom');
};
ws.onmessage = function(e) {
    alert('收到服务端的消息：' + e.data);
};
```

> **注意**：
> 1. 服务端是 WebSocket 协议，只能用 WebSocket 协议通讯
> 2. 不能用 HTTP 协议直接访问

---

## 示例三、直接使用 TCP 传输数据

创建 `tcp_test.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;

// 创建一个 Worker 监听 2347 端口，不使用任何应用层协议
$tcpWorker = new Worker('tcp://0.0.0.0:2347');

// 启动 4 个进程对外提供服务
$tcpWorker->count = 4;

// 当客户端发来数据时
$tcpWorker->onMessage = function ($connection, $data) {
    // 向客户端发送 hello $data
    $connection->send('hello ' . $data);
};

// 运行 Worker
Worker::runAll();
```

### 命令行运行

```bash
php tcp_test.php start
```

### 测试

使用 telnet 测试：

```bash
telnet 127.0.0.1 2347
# 输入: tom
# 输出: hello tom
```

> **注意**：
> 1. 服务端是裸 TCP 协议，用 WebSocket、HTTP 等其他协议无法直接通讯

---

## 示例四、使用极简 API

Kode Process 提供极简 API，一行代码启动服务器：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// HTTP 服务器
Kode::http('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $req) => $conn->send('Hello'))
    ->start();

// WebSocket 服务器
Kode::websocket('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();

// TCP 服务器
Kode::tcp('tcp://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

---

## 示例五、使用协程处理并发请求

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Coroutine\CoroutineManager;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;

$worker->onMessage = function ($connection, $request) {
    $manager = CoroutineManager::getInstance();
    
    // 在协程中处理耗时操作
    $manager->go(function () use ($connection) {
        // 模拟耗时操作
        $result = fetchDataFromDatabase();
        $connection->send(json_encode($result));
    });
};

Worker::runAll();
```

---

## 常用命令

| 命令 | 说明 |
|------|------|
| `php start.php start` | 启动服务 |
| `php start.php start -d` | 守护进程模式启动 |
| `php start.php stop` | 停止服务 |
| `php start.php restart` | 重启服务 |
| `php start.php reload` | 平滑重载 |
| `php start.php status` | 查看状态 |
| `php start.php connections` | 查看连接 |
