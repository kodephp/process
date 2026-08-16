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

## 运行时架构

一行 `Kode::serve()` 背后是一套**统一抽象 + 三种实现**的运行时。业务代码只面向 `Kode` 门面与 `RuntimeInterface` / `ConnectionInterface` 契约编程，切换底层实现**零改动**。

```
业务代码（Kode 门面 / RuntimeInterface / ConnectionInterface）
        │  零改动切换
        ▼
┌──────────────────────────────────────────────────────────┐
│            运行时抽象层（统一契约）                          │
│  RuntimeInterface · ConnectionInterface · Cluster 门面       │
└──────────────────────────────────────────────────────────┘
        │  Runtime::auto() 自动择优
        │  （优先级 native 100 > swoole 90 > workerman 80）
        ├───────────────┬────────────────┬─────────────────┐
        ▼               ▼                ▼                 ▼
   【Native】       【Swoole】       【Workerman】      【HTTP/2 流】
   自研·零扩展       封装 ext-swoole  封装 workerman      h2c 明文多路复用
   stream_select /   原生协程 +        事件循环            （Http2Stream）
   event loop        AsyncIo
        │
        └─ 默认路径：不依赖 swoole / workerman 即可运行
```

- **自研的是核心**：整套运行时契约 + **Native 零扩展实现**（`ext-pcntl` / `ext-posix` / `ext-sockets`，无需 swoole/workerman）+ HTTP/2 子系统 + 集群 + 常驻进程（Daemon）等，都是框架自带、不依附第三方运行时。
- **Swoole / Workerman 是可插拔后端**：当环境里装了对应扩展/包时，框架把它们**封装进同一套契约**，让业务写法完全统一。注意默认 `auto()` 优先选 Native，即默认路径根本不启用它们。
- 切换示例：同一份 `Kode::serve(...)` 代码，把 `Runtime::auto()` 换成 `Runtime::swoole()` / `Runtime::workerman()` 即可整包迁移，业务回调一行不改。

详细的统一 API、连接抽象与能力差异见 [运行时（Runtime）](runtime.md)。

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
        // $request 是 Kode\Process\Http\Request，三个运行时交付同一个类
        $connection->send(json_encode([
            'code'    => 0,
            'message' => 'success',
            'data'    => [
                'path'   => $request->path(),
                'method' => $request->method(),
            ],
        ]));
    })
    ->start();
```

请求对象的完整 API（查询参数、头部、JSON 体、Cookie、Bearer token 等）
见 [HTTP 请求对象](request.md)。

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

---

## 相关文档

- [运行时（Runtime）](runtime.md)：统一 API、三运行时（Native/Swoole/Workerman）、连接抽象、发送 PSR-7 响应
- [异步（Async）](async.md)：Promise / 定时器 / 并发原语 / EventEmitter / 异步 HTTP 客户端
- [并行（Parallel）](parallel.md)：CPU 并行与协程的结合
- [集群（Cluster）](cluster.md)：分布式锁 / 选举 / 限流 / Snowflake / RPC
- [HTTP 请求对象](request.md) · [响应对象边界](response.md)
- [定时器](timer.md) · [队列](queue.md) · [协议](protocol.md) · [HTTP/2](http2.md)
- [常驻进程（Daemon）](daemon.md) · [监控](monitor.md) · [信号处理](signal.md) · [生产部署](deployment.md)

