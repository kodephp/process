# Kode Process

**PHP 8.3+ 多进程编排内核 · 自研 Native 默认运行时 · Swoole / Workerman 可插拔 · 内置分布式集群能力**

[![PHP Version](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache%202.0-green?style=flat-square)](LICENSE)

## 简介

Kode Process 是一个「进程编排内核 + 多运行时兼容层」：你只写一套 API，底层运行时由环境自动择优，也可显式切换，**业务代码零改动**——

- **默认自研 Native 运行时**（纯 PHP、零扩展依赖、master-worker 多进程），装好即用，无需任何 C 扩展
- 装了 **Swoole** → 可显式接入（最快，全 C 实现，原生协程），业务代码一行不改
- 装了 **Workerman** → 纯 PHP 依赖，已写入 `require`，**开箱即用**，Linux 上 `ext-event` 加速

切换运行时（`native` / `swoole` / `workerman`）只需改一个参数，协议、连接 API、编排原语全部一致。

> **关于「自研 vs 兼容层」**：早期曾以「相对 Workerman 吞吐提升 30%」作为自研内核的硬门槛，实测仅 **1.010×**。
> 但 PHP 用户态只占全链路约 13%，Amdahl 上限仅 +14.9%，该门槛在数学上不可达，故结论已撤回。
> v5.0.0 起**自研 Native 运行时成为默认形态**，并补齐分布式协调能力（服务发现、分布式锁、Leader 选举、负载均衡、分布式 ID、限流、集群 RPC），
> 同时保留 Swoole / Workerman 作为可插拔的高性能运行时。详见 [docs/runtime.md](docs/runtime.md) 与 [docs/cluster.md](docs/cluster.md)。

> **最低要求 PHP 8.3**。若仍在 PHP 8.1 / 8.2 上运行，请使用旧版 `^2.9`。

## 特性

| 特性 | 说明 |
|------|------|
| 🔌 **一套 API，三种运行时** | 默认自研 Native（纯 PHP、零扩展），Swoole / Workerman 自动择优或显式选用，业务代码零改动 |
| 🧱 **进程编排内核** | 复用宿主的 master-worker 模型、监督重启、平滑重载、优雅停机、信号管理 |
| 🔁 **统一事件循环** | `Kode::loop()` 基于 `ext-event` / `ext-ev` 加速，`stream_select` 零扩展兜底 |
| 🌐 **多协议** | HTTP、WebSocket、TCP、Text、Unix Socket、SSL（UDP 取决于宿主运行时） |
| 🗄️ **共享数据（零安装兜底）** | 同主机多进程共享表，apcu → sysvshm 自动择优；也可复用 Swoole/Workerman 表 |
| 🕸️ **分布式集群** | 服务发现、分布式锁、Leader 选举、负载均衡（5 策略）、分布式 ID(Snowflake)、限流、集群 RPC；可零依赖（包内 GlobalData）或基于 Redis |
| 🧵 **多线程并行** | 真正的 CPU 多线程（需 ZTS + ext-parallel），与协程桥接 |
| 🪶 **协程** | 委托 `kode/fibers`，单线程 I/O 并发 |
| ⏱️ **定时器** | 一次性、周期、Cron |
| 📨 **队列** | 委托 `kode/queue`，内存 / 同步 / Redis / 数据库多后端 |
| 🔒 **SSL/TLS** | 通过监听选项配置，随宿主运行时生效 |
| 🩺 **部署自检** | `Kode::diagnose()` 一键列出可用运行时 / 事件循环 / 共享表后端 / 并行(ZTS) / 集群后端 能力 |

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

$rt = Runtime::auto();                       // 自动择优（native → swoole → workerman，native 优先）
$rt = Runtime::make('workerman');            // 显式指定
$rt = Runtime::make('native');               // 自研运行时（纯 PHP 零扩展）
$rt = Runtime::make(\Kode\Process\Runtime\RuntimeType::Swoole);

Runtime::available();                        // 当前环境按权重降序的可用运行时
Runtime::preferred();                        // 最优运行时类型
Runtime::isSupported('workerman');           // 该运行时是否可用

print_r(Kode::diagnose());                   // 部署前一键自检（含 Linux 上 ext-event 安装建议）
```

`Kode::diagnose()` 返回可用运行时、各自版本与优先级、当前事件循环驱动、共享表后端、并行能力
（是否 ZTS、是否可用、后端类型），以及集群后端（可用协调存储、已加入节点）；
在 Linux 且未安装 `ext-event` / `ext-ev` 时会给出安装建议
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

## 分布式集群

v5.0.0 内置轻量分布式协调能力，覆盖单主机到多主机场景。所有原语通过统一的协调存储抽象（`StoreInterface`）驱动，
可**零依赖**使用包内 GlobalData 后端，或对接 **Redis** 实现跨主机协调：

```php
use Kode\Process\Kode;

// 服务注册与发现
$node = Kode::join(['address' => 'http://10.0.0.1:8080', 'weight' => 100]);
$nodes = Kode::cluster()->nodes();          // 当前在线节点
$peers = Kode::cluster()->peers();          // 排除自身的其他节点

// 分布式锁
$lock = Kode::lock('order:' . $id, ttl: 30.0);
if ($lock->acquire()) {
    try { /* 临界区 */ } finally { $lock->release(); }
}

// Leader 选举（集群内只有一个节点 isLeader() 为 true）
$elect = Kode::election('scheduler');
$elect->tick();                             // 周期性调用，自动竞选 / 续租 / 让位
if ($elect->isLeader()) { runScheduler(); }

// 负载均衡（round-robin / weighted / least-conn / consistent-hash / random）
$balancer = Kode::balancer('least-conn', $nodes, service: 'api');
$target = $balancer->next();

// 分布式 ID（Snowflake，含 WorkerId 自动分配）
$id = Kode::snowflake()->id();

// 限流（令牌桶，跨进程/跨主机共享计数）
Kode::limiter()->consume('api:ip:' . $ip, 1, limit: 100, window: 60);
```

集群后端如需跨主机复用 Redis：

```php
use Kode\Process\Cluster;
use Kode\Process\Cluster\Store\RedisStore;

Cluster::useStore(new RedisStore(['host' => '127.0.0.1', 'port' => 6379]));
```

详见 [docs/cluster.md](docs/cluster.md)。

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
- [分布式集群](docs/cluster.md)
- [压测数据](docs/benchmark.md)

## 项目结构

```
src/
├── Kode.php                  # 静态门面：一行起服务 + 集群/并行/定时器/信号等编排原语
├── Runtime.php               # 运行时门面：一套 API 三种实现（Native / Swoole / Workerman）
├── Cluster.php               # 集群门面：注册发现/锁/选举/负载/ID/限流/RPC 统一入口
├── Version.php               # 版本信息
├── Timer.php                 # 定时器（顶层类）
├── Runtime/                  # 运行时兼容层
│   ├── RuntimeInterface.php
│   ├── ConnectionInterface.php
│   ├── Capability.php        # 能力枚举
│   ├── RuntimeType.php       # 运行时类型（含优先级）
│   └── Driver/
│       ├── NativeRuntime.php     # 自研纯 PHP master-worker（默认，零扩展）
│       ├── SwooleRuntime.php     # 宿主 Swoole 适配器
│       └── WorkermanRuntime.php  # 宿主 Workerman 适配器
├── Cluster/                  # 分布式协调子系统
│   ├── Store/                # 统一协调存储抽象（Redis / GlobalData / File 三后端）
│   ├── Registry/             # 服务注册与发现
│   ├── Lock/                 # 分布式锁
│   ├── Election/             # Leader 选举
│   ├── Balancer/             # 负载均衡（5 策略）
│   ├── Snowflake.php         # 分布式 ID
│   ├── RateLimiter.php       # 限流
│   └── Rpc/                  # 集群 RPC（帧编解码 + Server/Client）
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
