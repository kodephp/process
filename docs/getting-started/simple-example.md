# 简单的开发示例

## 安装

在一个空目录中运行：

```bash
composer require kode/process
```

## 示例一、使用 HTTP 协议对外提供 Web 服务

创建 `http_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($connection, $request) {
        $connection->send('hello world');
    })
    ->start();
```

### 命令行运行

```bash
php http_server.php
```

### 信号控制

| 操作 | 命令 |
|------|------|
| 启动 | `php http_server.php` |
| 停止 | `kill -TERM $PID` 或 `Ctrl+C` |
| 重载 | `kill -USR1 $PID` |

---

## 示例二、使用 WebSocket 协议对外提供服务

创建 `websocket_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', function ($connection, $data) {
        $connection->send('hello ' . $data);
    })
    ->start();
```

### 命令行运行

```bash
php websocket_server.php
```

### 测试

使用浏览器控制台或 JavaScript：

```javascript
ws = new WebSocket('ws://127.0.0.1:8081');
ws.onopen = function() {
    alert('连接成功');
    ws.send('tom');
};
ws.onmessage = function(e) {
    alert('收到消息：' + e.data);
};
```

---

## 示例三、直接使用 TCP 传输数据

创建 `tcp_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('message', function ($connection, $data) {
        $connection->send('hello ' . $data);
    })
    ->start();
```

### 命令行运行

```bash
php tcp_server.php
```

### 测试

```bash
telnet 127.0.0.1 9000
# 输入: tom
# 输出: hello tom
```

---

## 示例四、使用 UDP 协议

> UDP 仅 Swoole / Workerman 运行时支持，需显式指定运行时。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('udp://0.0.0.0:9002', ['workers' => 1], 'swoole')
    ->on('message', function ($connection, $data) {
        echo "收到数据: {$data}\n";
        $connection->send("已收到: {$data}");
    })
    ->start();
```

### 命令行运行

```bash
php udp_server.php
```

---

## 示例五、使用协程处理并发请求

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($connection, $request) {
        Kode::go(function () use ($connection) {
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

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($connection, $request) {
        $items = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

        $results = Kode::batch($items, function ($item) {
            usleep(100000);
            return $item * 2;
        }, 3);

        $connection->send(json_encode($results));
    })
    ->start();
```

---

## 示例七、多端口监听

`Kode::serve()` 一次只监听一个地址；多端口请创建多个运行时，或一个运行时多次 `listen`：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

$rt = Kode::runtime();

$rt->listen('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', fn($conn, $req) => $conn->send('HTTP Response'));

$rt->listen('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data));

$rt->listen('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data));

$rt->start();
```

---

## 示例八、worker 启动钩子

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('websocket://0.0.0.0:8080', ['workers' => 4])
    ->on('workerStart', function (int $workerId) {
        echo "worker #{$workerId} 已启动\n";
    })
    ->on('message', function ($connection, $data) {
        $connection->send($data);
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

QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    });

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($connection, $request) {
        $jobId = QueueManager::getInstance()->dispatch('send_email', [
            'to'      => 'user@example.com',
            'subject' => 'Hello',
            'body'    => 'World',
        ]);

        $connection->send(json_encode([
            'code'   => 0,
            'job_id' => $jobId,
        ]));
    })
    ->start();
```

---

## 示例十、守护进程与 PID 文件

运行时默认前台运行；守护化可借助 `nohup` / 进程管理器（systemd、supervisor）。
进程信号仍由运行时处理：`SIGTERM` 优雅停机、`SIGUSR1` 平滑重载。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', [
    'workers'   => 4,
    'name'      => 'demo-http',
    'reusePort' => true,
])
    ->on('message', fn($conn, $req) => $conn->send('Hello'))
    ->start();
```

### 信号控制

| 操作 | 命令 |
|------|------|
| 启动 | `php demo.php`（建议配合 nohup / systemd） |
| 停止 | `kill -TERM $PID` |
| 重载 | `kill -USR1 $PID` |

---

## 支持的协议前缀

| 前缀 | 协议 | 运行时支持 |
|------|------|-----------|
| `http://` | HTTP 协议 | 全部 |
| `https://` | HTTPS 协议 | 全部（需 ext-openssl） |
| `websocket://` | WebSocket 协议 | 全部 |
| `ws://` | WebSocket 协议（简写） | 全部 |
| `tcp://` | TCP 原始协议 | 全部 |
| `text://` | 文本+换行符协议 | 全部 |
| `frame://` | 自定义长度前缀 | 全部 |
| `unix://` | Unix Domain Socket | 全部 |
| `udp://` | UDP 协议 | 仅 Swoole / Workerman |
| `ssl://` | SSL/TLS 协议 | 全部（需 ext-openssl） |

---

## 信号控制

| 信号 | 说明 | 操作 |
|------|------|------|
| SIGTERM | 优雅停止 | `kill -TERM $PID` |
| SIGINT | 优雅停止 | `Ctrl+C` |
| SIGQUIT | 停机 | `kill -QUIT $PID` |
| SIGUSR1 | 平滑重载（不中断连接） | `kill -USR1 $PID` |

> Swoole / Workerman 运行时可能使用各自框架定义的额外信号；上表为两类运行时的通用行为。

---

## 兼容 Workerman

若项目已基于 Workerman，可显式指定运行时复用其成熟能力，应用代码几乎不变：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// 强制复用 Workerman（需 composer require workerman/workerman ^5.0）
Kode::serve('http://0.0.0.0:8080', ['workers' => 4], 'workerman')
    ->on('message', fn($conn, $req) => $conn->send('hello world'))
    ->start();
```

> **推荐**：新项目直接用 `Kode::serve()`，由环境自动择优运行时，部署更灵活。
