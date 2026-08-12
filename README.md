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

> **关于「自研 vs 兼容层」**：v5.0.0 起**自研 Native 运行时成为默认形态**，并补齐分布式协调能力（服务发现、分布式锁、Leader 选举、负载均衡、分布式 ID、限流、集群 RPC），
> 同时保留 Swoole / Workerman 作为可插拔的高性能运行时。详见 [docs/runtime.md](docs/runtime.md) 与 [docs/cluster.md](docs/cluster.md)。

> **最低要求 PHP 8.3**。若仍在 PHP 8.1 / 8.2 上运行，请使用旧版 `^2.9`。

## 特性

| 特性 | 说明 |
|------|------|
| 🔌 **一套 API，三种运行时** | 默认自研 Native（纯 PHP、零扩展），Swoole / Workerman 自动择优或显式选用，业务代码零改动 |
| 🧱 **进程编排内核** | 复用宿主的 master-worker 模型、监督重启、平滑重载、优雅停机、信号管理 |
| 🔁 **统一事件循环** | `Kode::loop()` 基于 `ext-event` / `ext-ev` 加速，`stream_select` 零扩展兜底 |
| 🌐 **多协议** | HTTP、WebSocket、TCP、Text、Unix Socket、SSL（UDP 取决于宿主运行时） |
| 🚀 **HTTP/2（h2c）** | Native 内置，默认开启、与 HTTP/1.1 同端口自动协商；多路复用 + HPACK + 流控，业务 handler 零改动 |
| 🗜️ **HTTP gzip 压缩** | 依据 `Accept-Encoding` 透明压缩（阈值 1KB），业务零改动；也支持显式 `gzip()` API |
| 🌊 **HTTP chunked 流式响应** | `Transfer-Encoding: chunked` 跨运行时统一 API；HTTP/2 下由 DATA 帧天然承载 |
| 🛡️ **HTTP/2 DoS 四层防护** | Rapid Reset（CVE-2023-44487）预算抵扣 + CONTINUATION 洪泛 + MAX_HEADER_LIST_SIZE 解压后体积上限 + PING/SETTINGS 控制帧洪泛；流级拒绝不拖垮连接，水位可由 `stats()` 观测 |
| ⚡ **HTTP/2 纯函数缓存提速** | HPACK 字面量编码缓存 5.7× + 响应头整块缓存 ≈2.2× + Huffman 解码缓存 ≈1.93× + 解码主循环内联 ≈1.47×（v5.2.6）+ 整头编码缓存 ≈3.4×（v5.2.7）+ 解码字符串读取内联 ≈1.22×（v5.2.9），线格式完全不变；`Hpack::decode` 较 v5.2.7 基线再快约 21%，请求热路径自 v5.2.3 起累计快约 2.0× |
| 🚀 **HTTP/2 大响应线性发送** | 切帧由平方复杂度改为游标推进 + 待发流索引（v5.2.8）：1MB 响应端到端吞吐 **2.73×**、延迟中位数 **−67%**，`WINDOW_UPDATE` 耗时与并发流数解耦；线格式逐字节不变 |
| 🔐 **协议层严格校验** | HTTP 请求走私（CL/TE 冲突）拒绝 + 响应头 CRLF 注入过滤 + WebSocket 掩码/控制帧/RSV 按 RFC 6455 校验 + HPACK 变长整数与 Huffman 截断收敛为协议错误（不再打死 worker） |
| 🗄️ **共享数据（零安装兜底）** | 同主机多进程共享表，apcu → sysvshm 自动择优；也可复用 Swoole/Workerman 表 |
| 🕸️ **分布式集群** | 服务发现、分布式锁、Leader 选举、负载均衡（5 策略）、分布式 ID(Snowflake)、限流、集群 RPC；可零依赖（包内 GlobalData）或基于 Redis |
| 🧵 **多线程并行** | 真正的 CPU 多线程（需 ZTS + ext-parallel），与协程桥接 |
| 🪶 **协程** | 委托 `kode/fibers`，单线程 I/O 并发 |
| ⏱️ **定时器** | 一次性、周期、Cron |
| 🏃 **常驻进程运行器 (Daemon)** | 基于 `Process::fork()` + `Timer` 的轻量多进程周期任务运行器，自带监督 / 异常重生 / 优雅退出，避开官方 worker 池回调空转陷阱 |
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

## 多进程定时任务的重复执行问题与选型

> ⚠️ **关键认知**：进程内的 `Kode::cron()` / `Timer::cron()` / `Crontab` 是**「每进程一份」的静态注册表**。
> 在 master-worker 多进程（或水平扩展多机）下，每个 worker 都会独立注册并触发同一表达式 ——
> **每个调度时刻会被 N 个 worker 重复执行 N 次**，且某个 worker 崩溃即丢失其定时器。
> **多进程本身并不能解决这个问题，反而把它放大了**：要让定时任务在集群内「至多执行一次」，
> 必须引入协调权威（分布式锁 / Leader 选举），或把活儿交给持久队列。

### 解法一：每任务分布式锁（推荐起步，零改造）

`Kode::cronCluster()` / `ClusterCron::create()` 用 `Cluster::lock()` 给每个表达式加一把互斥锁，
只有抢到锁的 worker 才执行 —— 多进程不再重复。存储后端自动择优（同机 file、跨机 redis），
**同机多进程开箱即可协调，无需任何外部依赖**；跨主机先 `Cluster::make('redis', ...)` 即可。

```php
use Kode\Process\Kode;

// 同机多进程：每个调度时刻全集群只执行一次（file 后端自动协调）
Kode::cronCluster('0 0 * * *', fn() => nightlyReport());

// 跨机集群：先配置 Redis 协调后端
Kode::cluster()->make('redis', ['host' => '127.0.0.1', 'port' => 6379]);
Kode::cronCluster('*/5 * * * *', fn() => syncOrders(), lockTtl: 60.0);
```

协调存储不可用时守卫 fail-soft 退化为本地执行并告警（极端情况可能重复，与无协调同款行为）。
每次触发多出一次锁往返（file 后端约 0.07ms，见 `benchmarks/cluster-cron-bench.php`）。

### 解法二：Leader 选举（长任务 / 强 exactly-once）

任务耗时可能超过锁 TTL、或要求强一致时，用 `Kode::tickCronOnLeader()` 让**整套 cron 只在选举胜出的
Leader 进程**推进，天然 exactly-once：

```php
use Kode\Process\Kode;

Kode::cron('0 2 * * *', fn() => heavyRebuild());   // 照常注册
// 主循环里改用：
Kode::tickCronOnLeader('scheduler', electionTtl: 15.0);   // 仅 Leader 推进
```

### 解法三：用 Redis 队列承接「持久 / 可重试」的活儿

若任务是**持久、可重试、崩溃不丢、需要背压/限速/失败重放**的（发邮件、对账、下发），
**不要**依赖进程内 cron/timer —— 它既不持久、崩溃即丢、多进程还重复。正确做法是让 cron 只负责
「产生消息」，真正的执行交给 `Kode::queue()`（Redis 后端），由队列保证投递语义：

```php
use Kode\Process\Kode;

// cron 只生产
Kode::cronCluster('*/1 * * * *', function () {
    foreach (fetchDueJobs() as $job) {
        Kode::queue()->dispatch('send_email', $job);   // 入队，交给队列保证至少一次 + 重试
    }
});

// 消费侧（可多 worker 并发消费，天然去重由队列 ack 保证）
Kode::queue()->register('send_email', function (array $data) {
    mail($data['to'], $data['subject'], $data['body']);
    return ['status' => 'sent'];
});
```

> 一句话：**定时「触发」用集群锁/选举去重；业务「执行」用 Redis 队列保持久。** 两者互补，而非替代。

## 常驻进程运行器 (Daemon)

> ⚠️ **为什么不直接用 `Kode\Process::start($config, $cb)` 跑周期任务？**
> 官方 worker 池（`WorkerProcess`）的事件循环里 `processTasks()` **从不调用用户回调**——
> 回调只在外部 `assignTask()` 推任务时触发。所以 `Process::start($config, fn()=>...)` 传入的回调在
> worker 里实际是**空转**，并不适合「每进程周期执行自定义任务」。要可靠地常驻跑任务，请用本运行器。

`Kode::daemon()` 只依赖两个原语——**`Process::fork()`（多进程）+ `Timer`（周期/定时调度）**——
自建「监督进程 + N 个 worker 子进程」：worker 在独立事件循环里真正执行你的任务，监督进程负责
信号、回收僵尸、异常退出重生（带上限防 fork bomb）、优雅退出。完全不碰 Master/Worker 池。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::daemon()
    ->task(fn () => file_put_contents('/tmp/tick', date('c') . "\n", FILE_APPEND))
    ->every(5)                 // 每 5 秒；或 ->cron('0 * * * *')
    ->workers(4)              // 4 个 worker 子进程并行跑
    ->daemonize()             // 脱离终端常驻（可选）
    ->pidFile('/var/run/app.pid')
    ->run();
```

daemon 文件（`return` 一个 `Daemon` 实例）配合 CLI 子命令管理生命周期：

```bash
kode process start daemon.php --workers=4 --every=5     # 启动（前台）
kode process start daemon.php --daemon --cron='0 0 * * *' # 脱离终端常驻
kode process status                                     # 查看状态
kode process stop                                       # 优雅停止（TERM → 回收 worker → 清理 PID）
kode process restart daemon.php                         # 平滑重启（detached）
```

> 与「多进程定时任务重复执行」的关系：Daemon 解决的是**单机上 N 个 worker 各自可靠地周期跑任务**；
> 若还要跨进程/跨机「同一时刻只跑一次」，仍用 `Kode::cronCluster()`（集群锁）或
> `Kode::tickCronOnLeader()`（Leader 选举）去重，二者可叠加。详见 [docs/daemon.md](docs/daemon.md)。

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
- [HTTP/2](docs/http2.md)
- [共享数据](docs/global-data.md)
- [并行（多线程）](docs/parallel.md)
- [定时器](docs/timer.md)
- [常驻进程运行器 (Daemon)](docs/daemon.md)
- [队列系统](docs/queue.md)
- [信号管理](docs/signal.md)
- [监控（进程 / 心跳 / 文件）](docs/monitor.md)
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
