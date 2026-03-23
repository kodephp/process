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

use Kode\Process\Kode;

// 使用统一方法，自动解析协议
Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        // 向浏览器发送 hello world
        $connection->send('hello world');
    })
    ->start();
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

use Kode\Process\Kode;

// 使用统一方法，websocket:// 前缀自动识别为 WebSocket 协议
Kode::worker('websocket://0.0.0.0:2000', 4)
    ->onMessage(function ($connection, $data) {
        // 向客户端发送 hello $data
        $connection->send('hello ' . $data);
    })
    ->start();
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

use Kode\Process\Kode;

// 使用统一方法，tcp:// 前缀自动识别为 TCP 协议
Kode::worker('tcp://0.0.0.0:2347', 4)
    ->onMessage(function ($connection, $data) {
        // 向客户端发送 hello $data
        $connection->send('hello ' . $data);
    })
    ->start();
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

## 示例四、使用 UDP 协议

创建 `udp_test.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// 使用统一方法，udp:// 前缀自动识别为 UDP 协议
Kode::worker('udp://0.0.0.0:9002', 1)
    ->onMessage(function ($connection, $data) {
        echo "收到数据: {$data}\n";
        $connection->send("已收到: {$data}");
    })
    ->start();
```

---

## 示例五、使用协程处理并发请求

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        // 在协程中处理耗时操作
        Kode::go(function () use ($connection) {
            // 模拟耗时操作
            $result = fetchDataFromDatabase();
            $connection->send(json_encode($result));
        });
    })
    ->start();
```

---

## 示例六、批量并发处理

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        $items = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        
        // 批量并发处理，并发数为 3
        $results = Kode::batch($items, function ($item) {
            // 模拟耗时操作
            usleep(100000);
            return $item * 2;
        }, 3);
        
        $connection->send(json_encode($results));
    })
    ->start();
```

---

## 示例七、多端口监听

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Application;

// 创建应用
$app = Kode::app(['worker_count' => 4]);

// 监听 HTTP 端口
$app->listen('http://0.0.0.0:8080')
    ->onMessage(fn($conn, $req) => $conn->send('HTTP Response'));

// 监听 WebSocket 端口
$app->listen('websocket://0.0.0.0:8081')
    ->onMessage(fn($conn, $data) => $conn->send($data));

// 监听 TCP 端口
$app->listen('tcp://0.0.0.0:9000')
    ->onMessage(fn($conn, $data) => $conn->send($data));

// 启动所有监听
$app->start();
```

---

## 示例八、使用 Channel 分布式通讯

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Channel\Client;

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onWorkerStart(function ($worker) {
        // 连接 Channel 服务端
        Client::connect('127.0.0.1', 2206);
        
        // 订阅广播事件
        Client::on('broadcast', function ($data) use ($worker) {
            foreach ($worker->connections as $conn) {
                $conn->send(json_encode($data));
            }
        });
    })
    ->onMessage(function ($connection, $data) {
        // 发布广播事件
        Client::publish('broadcast', [
            'message' => $data,
            'time' => date('H:i:s')
        ]);
    })
    ->start();
```

---

## 示例九、使用队列系统

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Queue\QueueManager;

// 注册任务处理器
QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        // 发送邮件
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    });

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        // 分发任务到队列
        $jobId = QueueManager::getInstance()->dispatch('send_email', [
            'to' => 'user@example.com',
            'subject' => 'Hello',
            'body' => 'World'
        ]);
        
        $connection->send(json_encode([
            'code' => 0,
            'job_id' => $jobId
        ]));
    })
    ->start();
```

---

## 支持的协议前缀

| 前缀 | 协议 | 示例 |
|------|------|------|
| `http://` | HTTP 协议 | `http://0.0.0.0:8080` |
| `https://` | HTTPS 协议 | `https://0.0.0.0:443` |
| `websocket://` | WebSocket 协议 | `websocket://0.0.0.0:8081` |
| `ws://` | WebSocket 协议（简写） | `ws://0.0.0.0:8081` |
| `tcp://` | TCP 原始协议 | `tcp://0.0.0.0:9000` |
| `text://` | 文本+换行符协议 | `text://0.0.0.0:9001` |
| `udp://` | UDP 协议 | `udp://0.0.0.0:9002` |
| `ssl://` | SSL/TLS 协议 | `ssl://0.0.0.0:443` |

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

---

## Workerman 兼容模式

如果你需要兼容 Workerman 代码，可以使用兼容层：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

// 使用兼容层（与 Workerman API 完全一致）
use Kode\Process\Compat\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->onMessage = function ($connection, $request) {
    $connection->send('hello world');
};

Worker::runAll();
```

> **推荐**：新项目建议使用 `Kode::worker()` 统一方法，更简洁易用。
