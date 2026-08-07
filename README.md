# Kode Process

**PHP 8.3+ 多进程编排内核 · Swoole / Workerman / Native 运行时兼容层**

[![PHP Version](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache%202.0-green?style=flat-square)](LICENSE)

## 简介

Kode Process 是一个「进程编排内核 + 运行时兼容层」：你只写一套 API，底层运行时由环境自动择优——

- 装了 **Swoole** → 用 Swoole 跑（最快，全 C 实现，自动择优时最高优先级）
- 否则用 **Workerman** 跑（纯 PHP 依赖，已写入 `require`，**开箱即用**，Linux 上 `ext-event` 加速）

> **为什么当前默认是「兼容层」？**
> 我们通过五维硬门槛实测过自研网络 I/O 内核：相对 Workerman 吞吐比仅 **1.010×**
> （PHP 用户态只占全链路 ~13%，Amdahl 上限 +14.9%，原定 30% 门槛在数学上不可达，已撤销）。
> 因此本包**默认不内置服务器实现**，只做 Swoole / Workerman 的兼容适配层：应用面向 `RuntimeInterface` 编程，
> 即可在两者间无缝切换。自研内核在「兼容 Workerman / Swoole 多进程 + 功能更广更健壮」前提下仍是**可选方向**，
> 可在现有框架内作为可插拔的第三种 `Runtime` 实现接入。详见 [docs/gate-report.md](docs/gate-report.md) 与 [docs/runtime.md](docs/runtime.md)。

> **最低要求 PHP 8.3**。若仍在 PHP 8.1 / 8.2 上运行，请使用旧版 `^2.9`。

## 特性

| 特性 | 说明 |
|------|------|
| 🔌 **一套 API，三种运行时** | Swoole / Workerman 自动择优，亦可显式选用自研 Native（纯 PHP、零扩展） |
| 🧱 **进程编排内核** | 复用宿主的 master-worker 模型、监督重启、平滑重载、优雅停机、信号管理 |
| 🔁 **统一事件循环** | `Kode::loop()` 基于 `ext-event` / `ext-ev` 加速，`stream_select` 零扩展兜底 |
| 🌐 **多协议** | HTTP、WebSocket、TCP、Text、Unix Socket、SSL（UDP 取决于宿主运行时） |
| 🗄️ **共享数据（零安装兜底）** | 同主机多进程共享表，apcu → sysvshm 自动择优；也可复用 Swoole/Workerman 表 |
| 🧵 **多线程并行** | 真正的 CPU 多线程（需 ZTS + ext-parallel），与协程桥接 |
| 🪶 **协程** | 委托 `kode/fibers`，单线程 I/O 并发 |
| ⏱️ **定时器** | 一次性、周期、Cron |
| 📨 **队列** | 委托 `kode/queue`，内存 / 同步 / Redis / 数据库多后端 |
| 🔒 **SSL/TLS** | 通过监听选项配置，随宿主运行时生效 |
| 🩺 **部署自检** | `Kode::diagnose()` 一键列出可用运行时 / 事件循环 / 共享表后端 / 并行(ZTS) 能力 |

## 安装

```bash
composer require kode/process
```

`workerman/workerman` 是纯 PHP 依赖、已写入 `require`，安装后即开箱可用；需要更高吞吐时再装 `ext-swoole` 即可自动择优到它。

## 快速开始

### HTTP 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 8])
    ->on('message', fn($conn, $req) => $conn->send('Hello World!'))
    ->start();
```

### WebSocket 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```

### TCP 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send('Echo: ' . $data))
    ->start();
```

`Kode::serve()` 内部调用 `Runtime::auto()` 选择最优运行时。若想显式指定：

```php
// 强制用某个运行时（swoole | workerman | native）
Kode::serve('http://0.0.0.0:8080', ['workers' => 8], 'swoole')
    ->on('message', fn($conn) => $conn->send('Hello'))
    ->start();

// 显式选用自研 Native 运行时（纯 PHP、零扩展依赖、master-worker 多进程）
Kode::serve('http://0.0.0.0:8080', ['workers' => 8], 'native')
    ->on('message', fn($conn) => $conn->send('Hello'))
    ->start();
```

## 事件与连接

运行时通过事件回调驱动业务，连接对象统一为 `Kode\Process\Runtime\ConnectionInterface`：

| 事件 | 回调签名 | 说明 |
|------|----------|------|
| `workerStart` | `(int $workerId)` | worker 就绪 |
| `workerStop` | `(int $workerId)` | worker 退出 |
| `connect` | `(ConnectionInterface $conn)` | 新连接建立 |
| `message` | `(ConnectionInterface $conn, mixed $data)` | 收到一条完整报文 |
| `close` | `(ConnectionInterface $conn)` | 连接关闭 |
| `error` | `(?ConnectionInterface $conn, \Throwable $e)` | 连接或运行时错误 |

`ConnectionInterface` 提供与底层无关的操作：

```php
$conn->send(string $data, bool $raw = false): bool;   // 发送；raw=true 跳过协议编码
$conn->close(?string $data = null): void;              // 关闭（可带最后一段数据）
$conn->id(): int;                                       // 本 worker 内唯一连接 ID
$conn->remoteAddress(): string;                        // 对端 ip:port
$conn->localAddress(): string;                         // 本端 ip:port
$conn->isAlive(): bool;                                // 是否仍可用
$conn->native(): mixed;                                // 原生对象（Swoole=int fd / Workerman=TcpConnection）
$conn->setContext(string $key, mixed $v): void;        // 关联会话上下文
$conn->getContext(string $key, mixed $default = null): mixed;
```

### 能力探测

不同运行时的能力不同，请用 `supports()` 做优雅降级：

```php
$rt = Kode::runtime();

if ($rt->supports(\Kode\Process\Runtime\Capability::HotReload)) {
    $rt->reload();           // 平滑重载（Workerman / Swoole 支持）
}
```

能力枚举见 `Kode\Process\Runtime\Capability`：协程、共享表、Task 工作进程、UDP、Unix Socket、
SSL、平滑重载、SO_REUSEPORT、WebSocket、定时器、异步 I/O。

## 运行时择优与自检

```php
use Kode\Process\Runtime;

$rt = Runtime::auto();                       // 自动择优（swoole → workerman → native）
$rt = Runtime::make('workerman');            // 显式指定
$rt = Runtime::make('native');               // 自研运行时（纯 PHP 零扩展）
$rt = Runtime::make(\Kode\Process\Runtime\RuntimeType::Swoole);

Runtime::available();                        // 当前环境按权重降序的可用运行时
Runtime::preferred();                        // 最优运行时类型
Runtime::isSupported('workerman');           // 该运行时是否可用

print_r(Kode::diagnose());                   // 部署前一键自检（含 Linux 上 ext-event 安装建议）
```

`Kode::diagnose()` 返回可用运行时、各自版本与优先级、当前事件循环驱动、共享表后端，以及并行能力
（是否 ZTS、是否可用、后端类型）；在 Linux 且未安装 `ext-event` / `ext-ev` 时会给出安装建议
（Workerman 官方也推荐 Linux 用 event 循环）。

## 共享数据（零安装兜底）

跨进程共享数据，无需引入 Swoole / Workerman：

```php
use Kode\Process\Kode;

$table = Kode::table();          // 自动择优：apcu → sysvshm（零安装）
$table->set('online', 0);
$table->increment('online', 1);

echo $table->get('online');
```

若应用已运行在 Swoole / Workerman 之上，可复用其共享表做兼容共存：

```php
use Kode\Process\SharedTable;

$table = SharedTable::make('swoole');    // 直接复用 Swoole\Table
$table = SharedTable::make('workerman'); // 直接复用 Workerman\Table
```

> **选型建议**：能上 Swoole / Workerman 就优先用它们的表（成熟稳定、免维护）。
> 本库 `SharedTable::auto()` 是「不引入这两者时」的**零安装、零依赖兜底**。
> 详见 [docs/global-data.md](docs/global-data.md)。

## 并发原语

```php
use Kode\Process\Kode;

// 协程（单线程 I/O 并发，委托 kode/fibers）
Kode::go(function () {
    $result = fetchDataFromApi();
    echo json_encode($result);
});

$results = Kode::batch([1, 2, 3, 4, 5], fn($i) => $i * 2, 3);

// 多线程并行（CPU 密集，需 ZTS + ext-parallel）
if (Kode::supportsParallel()) {
    $future = Kode::parallel(fn($x) => heavyCompute($x), 42);
    $result = Kode::awaitParallel($future);
}
```

## 定时器

通过 `Kode` 门面一行注册定时器，底层复用 `Kode\Process\Timer`：

```php
use Kode\Process\Kode;

Kode::every(2.5, fn() => echo "每 2.5 秒执行\n");      // 周期定时器
Kode::after(10,  fn() => echo "10 秒后执行一次\n");     // 一次性定时器
Kode::cron('* * * * *', fn() => echo "每分钟执行\n");  // Cron 定时器

$id = Kode::after(5, fn() => null);
Kode::clearTimer($id);   // 取消定时器
Kode::tickTimers();      // 在自定义主循环中周期推进
```

也支持 `setTimeout` / `setInterval`（JS 风格别名）与 `pause` / `resume` / `getStatus` 等，
详见 [docs/timer.md](docs/timer.md)。

## 队列系统

```php
use Kode\Process\Kode;

Kode::queue()
    ->register('send_email', function (array $data) {
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    });

Kode::queue()->dispatch('send_email', [
    'to'      => 'user@example.com',
    'subject' => 'Hello',
    'body'    => 'World',
]);
```

队列由 `kode/queue` 提供，支持内存 / 同步 / Redis / 数据库多后端。详见 [docs/queue.md](docs/queue.md)。

## 信号、监控与进程内事件

```php
use Kode\Process\Kode;

// 应用层信号（运行时已自行管理 SIGTERM / SIGINT 等进程信号）
Kode::signal()->register(SIGUSR1, function () {
    echo "收到 SIGUSR1，重载配置\n";
});

// 运行状态监控（写入 status / pid 文件，便于运维排查）
$monitor = Kode::monitor();
$monitor->init(getmypid());

// 进程内发布/订阅事件
Kode::emitter()->on('task.done', fn($id) => echo "任务 $id 完成\n");
Kode::emitter()->emit('task.done', 123);
```

信号处理器为单例，详见 [docs/signal.md](docs/signal.md)。

## SSL/TLS

通过监听选项配置，随宿主运行时生效（需 `ext-openssl`）：

```php
use Kode\Process\Kode;

Kode::serve('ssl://0.0.0.0:443', [
    'workers' => 4,
    'ssl'     => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk'   => '/path/to/key.pem',
    ],
])
->on('message', fn($conn, $data) => $conn->send('Secure response'))
->start();
```

## 命令行工具

`bin/kode` 是便捷启动器，运行你提供的服务脚本（脚本内调用 `Kode::serve(...)->start()`）：

```bash
kode start             # 启动 server.php
kode start app.php     # 启动指定脚本
kode stop              # 停止（读取 PID 文件）
kode reload            # 平滑重载
kode status            # 查看状态
kode info              # 版本信息
```

运行中的服务也可直接用信号控制：

| 信号 | 作用 |
|------|------|
| `SIGTERM` | 优雅停机 |
| `SIGUSR1` | 平滑重载（Workerman / Swoole，不中断连接） |
| `SIGINT` / `SIGQUIT` | 停机 |

## 文档

- [安装](docs/install.md)
- [快速开始](docs/quick-start.md)
- [运行时兼容层（架构与 API）](docs/runtime.md)
- [事件循环（Reactor）](docs/reactor.md)
- [协议系统](docs/protocol.md)
- [共享数据](docs/global-data.md)
- [并行（多线程）](docs/parallel.md)
- [定时器](docs/timer.md)
- [队列系统](docs/queue.md)
- [信号管理](docs/signal.md)
- [生产部署](docs/deployment.md)
- [五维硬门槛判定报告](docs/gate-report.md)

## 项目结构

```
src/
├── Kode.php                  # 静态门面：一行起服务
├── Runtime.php               # 运行时门面：一套 API 三种实现（Swoole / Workerman / Native）
├── Version.php               # 版本信息
├── Timer.php                 # 定时器（顶层类）
├── Runtime/                  # 运行时兼容层
│   ├── RuntimeInterface.php
│   ├── ConnectionInterface.php
│   ├── Capability.php        # 能力枚举
│   ├── RuntimeType.php       # 运行时类型（含优先级）
│   └── Driver/
│       ├── SwooleRuntime.php     # 宿主 Swoole 适配器
│       └── WorkermanRuntime.php  # 宿主 Workerman 适配器
├── Reactor/                  # 统一事件循环层（Kode::loop()）
│   ├── LoopFactory.php       # 自动择优
│   ├── LoopInterface.php
│   ├── EventLoop.php         # ext-event
│   ├── EvLoop.php            # ext-ev
│   └── SelectLoop.php        # stream_select 兜底
├── SharedTable.php           # 多后端共享表门面
├── GlobalData/               # 网络 GlobalData（兼容 Workerman 概念）
├── Parallel/                 # 多线程并行（ZTS + ext-parallel）
├── Protocol/                 # 协议编解码
├── Signal/                   # 信号管理
└── ...                       # 进程管理、IPC、监控等编排内核
```

## 测试

```bash
./vendor/bin/phpunit
```

## 许可证

Apache License 2.0
