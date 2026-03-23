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

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        $connection->send('hello world');
    })
    ->start();
```

### 命令行运行

```bash
php http_server.php
```

### 测试

在浏览器中访问 `http://127.0.0.1:8080`，即可看到 "hello world"。

> **信号控制**：
> - `kill -TERM $PID` 或 `Ctrl+C` - 停止服务
> - `kill -HUP $PID` - 平滑重载

---

## 示例二、使用 WebSocket 协议对外提供服务

创建 `websocket_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('websocket://0.0.0.0:8081', 4)
    ->onMessage(function ($connection, $data) {
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

Kode::worker('tcp://0.0.0.0:9000', 4)
    ->onMessage(function ($connection, $data) {
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

创建 `udp_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('udp://0.0.0.0:9002', 1)
    ->onMessage(function ($connection, $data) {
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

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
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

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
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

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

$app = Kode::app(['worker_count' => 4]);

$app->listen('http://0.0.0.0:8080')
    ->onMessage(fn($conn, $req) => $conn->send('HTTP Response'));

$app->listen('websocket://0.0.0.0:8081')
    ->onMessage(fn($conn, $data) => $conn->send($data));

$app->listen('tcp://0.0.0.0:9000')
    ->onMessage(fn($conn, $data) => $conn->send($data));

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
        Client::connect('127.0.0.1', 2206);

        Client::on('broadcast', function ($data) use ($worker) {
            foreach ($worker->connections as $conn) {
                $conn->send(json_encode($data));
            }
        });
    })
    ->onMessage(function ($connection, $data) {
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

QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    });

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
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

## 示例十、守护进程模式

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::app([
    'worker_count' => 4,
    'daemonize' => true,
    'pid_file' => '/var/run/kode-process.pid',
    'log_file' => '/var/log/kode-process.log',
])
->listen('http://0.0.0.0:8080')
->onMessage(fn($conn, $req) => $conn->send('Hello'))
->start();
```

### 命令

| 操作 | 命令 |
|------|------|
| 启动 | `php server.php` |
| 停止 | `kill -TERM $PID` |
| 重载 | `kill -HUP $PID` |
| 查看状态 | `kill -USR2 $PID` |

---

## 示例十一、使用 WorkerPool 进程池

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Worker\WorkerPool;
use Kode\Process\Worker\WorkerFactory;

$pool = new WorkerPool(4);

$pool->setWorkerCallback(function ($taskId, $data) {
    return ['result' => 'processed: ' . $data];
});

$pool->start();

for ($i = 0; $i < 10; $i++) {
    $worker = $pool->selectWorker();
    if ($worker) {
        echo "分配任务到 Worker {$worker->getId()}\n";
    }
}

$pool->stop();
```

---

## 示例十二、自动扩缩容

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Worker\WorkerPool;

$pool = new WorkerPool(4);

$pool->setWorkerCallback(function ($taskId, $data) {
    return ['result' => $data];
});

$pool->start();

$pool->scale(8);
echo "扩容到 8 个 Worker\n";

$pool->scale(2);
echo "缩容到 2 个 Worker\n";

$pool->stop();
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

## 信号控制

| 信号 | 说明 | 操作 |
|------|------|------|
| SIGTERM | 优雅停止 | `kill -TERM $PID` |
| SIGINT | 优雅停止 | `Ctrl+C` |
| SIGQUIT | 强制停止 | `kill -QUIT $PID` |
| SIGHUP | 平滑重载 | `kill -HUP $PID` |
| SIGUSR1 | 平滑重载 | `kill -USR1 $PID` |
| SIGUSR2 | 打印状态 | `kill -USR2 $PID` |

---

## Workerman 兼容模式

如果你需要兼容 Workerman 代码，可以使用兼容层：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->onMessage = function ($connection, $request) {
    $connection->send('hello world');
};

Worker::runAll();
```

> **推荐**：新项目建议使用 `Kode::worker()` 统一方法，更简洁易用。
