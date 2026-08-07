# 快速开始

## 第一个服务器

创建 `http_server.php` 文件：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

// 一行启动 HTTP 服务器
Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($connection, $request) {
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
kode start              # 启动（运行你的服务脚本）
kode stop              # 停止
kode reload            # 平滑重载
kode status            # 查看状态
```

运行中的服务也可用信号直接控制：`SIGTERM` 优雅停机、`SIGUSR1` 平滑重载。

## 多协议支持

```php
use Kode\Process\Kode;

// HTTP 服务器
Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send('HTTP'))
    ->start();

// WebSocket 服务器
Kode::serve('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();

// TCP 服务器
Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', [
    'workers'   => 4,
    'name'      => 'demo-http',
    'reusePort' => true,
])
    ->on('message', function ($connection, $request) {
        $connection->send(json_encode([
            'code'    => 0,
            'message' => 'success',
            'data'    => [
                'path'   => $request['path'] ?? '/',
                'method' => $request['method'] ?? 'GET',
            ],
        ]));
    })
    ->start();
```

## 定时器与编排原语

`Kode` 门面还提供进程编排原语的一行入口，无需分别引入底层类：

```php
use Kode\Process\Kode;

// 定时器：周期 / 一次性 / Cron
Kode::every(2.5, fn() => echo "心跳\n");
Kode::after(10,  fn() => echo "10 秒后执行一次\n");
Kode::cron('0 0 * * *', fn() => echo "每天零点\n");
Kode::tickTimers();   // 自定义主循环中周期推进

// 应用层信号（运行时已托管 SIGTERM / SIGINT 等进程信号）
Kode::signal()->register(SIGUSR1, fn() => echo "重载配置\n");

// 队列（kode/queue 适配层）
Kode::queue()->register('send_email', fn($data) => mail($data['to'], $data['subject'], $data['body']));
Kode::queue()->dispatch('send_email', ['to' => 'a@b.c', 'subject' => 'Hi', 'body' => 'World']);

// 进程内发布/订阅事件
Kode::emitter()->on('task.done', fn($id) => echo "任务 $id 完成\n");
```

`Kode::diagnose()` 可在部署前一键自检运行时、事件循环、共享表后端与并行（ZTS）能力。
