# 运行时兼容层（Runtime）

> 一套 API，两种实现：Swoole / Workerman。

## 为什么是"兼容层"而不是"自研内核"

v4.0.0 之前，本包自带一套网络 I/O 内核（`Kode\Process\Server` + `Compat\Worker`）。
我们用五维硬门槛做了正式判定（完整报告见 [gate-report.md](./gate-report.md)）：

| 门槛 | 要求 | 实测 | 结果 |
|---|---|---|---|
| G1 吞吐 | ≥ Workerman × 1.30 | **1.010×** | ❌ |
| G2 尾延迟 | P99 ≤ Workerman | 持平 | ✅ |
| G3 稳定性 | 3 轮中位数 RSD < 5% | 通过 | ✅ |
| G4 正确性 | 0 错误 | 通过 | ✅ |
| G5 内存 | ≤ Workerman × 1.5 | 通过 | ✅ |

G1 未过，且瓶颈归因显示 **PHP 用户态只占全链路约 13%**，85.9% 的 CPU 在内核态
（`accept` / `read` / `write` 系统调用）。按 Amdahl 定律，即使把 PHP 那 13% 优化到 0，
上限也只有 **+14.9%**——30% 在数学上不可达。

**结论：不重造 I/O 栈，本包也不自带服务器实现。** 网络层交给已经久经生产验证的
Swoole / Workerman，本包只做一层薄适配，让业务代码在两者间零改动切换；
同时专注它们不覆盖的部分（进程编排、共享表、IPC、信号、定时器、统一事件循环）。

> `Workerman` 是纯 PHP 依赖、已写入 `require`，因此本包**开箱即用**；
> 装了 `ext-swoole` 时 `Runtime::auto()` 自动择优到它（优先级更高）。

## 快速开始

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 8])
    ->on('message', fn($conn, $req) => $conn->send('Hello, World!'))
    ->start();
```

`Kode::serve()` 会自动择优：**swoole(100) → workerman(90)**。

显式指定运行时：

```php
use Kode\Process\Runtime;

$rt = Runtime::make('workerman');          // 或 RuntimeType::Workerman
$rt = Runtime::auto([RuntimeType::Workerman]); // 偏好列表，命中第一个可用的
```

## 完整 API

```php
$rt = Runtime::auto();

$rt->listen(string $address, array $options = []): static
$rt->on(string $event, callable $handler): static
$rt->start(): void
$rt->stop(bool $graceful = true): void
$rt->reload(): void
$rt->addTimer(float $interval, callable $cb, bool $periodic = true): int
$rt->delTimer(int $timerId): bool
$rt->supports(Capability $cap): bool
$rt->capabilities(): array
$rt->stats(): array
$rt->isRunning(): bool
```

### 地址格式

| 前缀 | 说明 | Swoole | Workerman |
|---|---|:-:|:-:|
| `tcp://host:port` | 裸 TCP，不分包 | ✅ | ✅ |
| `http://host:port` | HTTP/1.1 | ✅ | ✅ |
| `websocket://` / `ws://` | WebSocket | ✅ | ✅ |
| `text://host:port` | 文本 + 换行分包 | ❌ | ✅ |
| `frame://host:port` | 长度前缀分包 | ❌ | ✅ |
| `udp://host:port` | UDP | ✅ | ✅ |
| `unix:///path.sock` | Unix Domain Socket | ✅ | ✅ |

### listen 选项

```php
$rt->listen('http://0.0.0.0:8080', [
    'workers'    => 8,          // worker 进程数
    'name'       => 'my-app',   // 进程名
    'reusePort'  => true,       // SO_REUSEPORT 内核级负载均衡
    'maxRequest' => 100000,     // 处理满 N 个请求后重启该 worker（对抗内存增长）
    'backlog'    => 65535,      // accept 队列长度
    'ssl'        => ['local_cert' => '/path/server.pem', 'local_pk' => '/path/server.key'],
    'mode'       => 'process',  // 仅 Swoole：切到 SWOOLE_PROCESS（默认 BASE，吞吐高约 8%）
]);
```

> **Linux 性能提示**：Workerman 在 Linux 上用 `ext-event` 事件循环吞吐显著更高
> （Workerman 官方也推荐）。未安装时会由 `Runtime::diagnose()` / `WorkermanRuntime::eventLoopRecommendation()`
> 给出 `pecl install event` 的安装建议；Swoole 自带事件循环不受此影响。

### 事件

| 事件 | 签名 |
|---|---|
| `workerStart` | `fn(int $workerId)` |
| `workerStop` | `fn(int $workerId)` |
| `connect` | `fn(ConnectionInterface $conn)` |
| `message` | `fn(ConnectionInterface $conn, mixed $data)` |
| `close` | `fn(ConnectionInterface $conn)` |
| `error` | `fn(?ConnectionInterface $conn, Throwable $e)` |

> 任一回调抛出的异常都会被收敛到 `error` 处理器，不会打挂整个 worker。

### 连接抽象

两种运行时的"连接"原生表示各不相同（Swoole 是 `int $fd`，Workerman 是
`TcpConnection`），`ConnectionInterface` 把它们收敛为同一套操作：

```php
$conn->id(): int
$conn->send(string $data, bool $raw = false): bool   // $raw=true 跳过协议编码
$conn->close(?string $data = null): void
$conn->remoteAddress(): string
$conn->localAddress(): string
$conn->isAlive(): bool
$conn->native(): mixed                               // 取底层原生对象，用运行时特有能力
$conn->setContext(string $k, mixed $v): void
$conn->getContext(string $k, mixed $default = null): mixed
```

## 能力探测

不同运行时能力集不同，**应通过 `supports()` 做优雅降级，不要假定能力一定存在**：

```php
use Kode\Process\Runtime\Capability;

if ($rt->supports(Capability::Coroutine)) {
    // Swoole 独有：原生协程
}
if ($rt->supports(Capability::HotReload)) {
    $rt->reload();
}
```

| 能力 | Swoole | Workerman |
|---|:-:|:-:|
| `Coroutine` 原生协程 | ✅ | ❌ |
| `TaskWorker` Task 进程 | ✅ | ❌ |
| `AsyncIo` 异步文件/DNS | ✅ | ❌ |
| `UdpServer` | ✅ | ✅ |
| `SharedTable` | ✅ | ✅ |
| `UnixSocket` | ✅ | ✅ |
| `Ssl` | ✅ | ✅ |
| `HotReload` | ✅ | ✅ |
| `ReusePort` | ✅ | ✅ |
| `WebSocket` | ✅ | ✅ |
| `Timer` | ✅ | ✅ |

## 环境自检

```php
use Kode\Process\Runtime;

print_r(Runtime::diagnose());
```

```
[
    'preferred'      => 'swoole',
    'loop'           => ['event' => ['supported'=>true,'priority'=>100,'preferred'=>true], ...],
    'runtimes'       => [
        'swoole'    => ['available'=>true,  'version'=>'6.2.2', 'priority'=>100, 'preferred'=>true],
        'workerman' => ['available'=>true,  'version'=>'5.2.2', 'priority'=>90,  'preferred'=>false],
    ],
    'recommendation' => null,  // Linux 且未装 ext-event 时为安装建议字符串
]
```

## 接入自定义运行时

```php
Runtime::register('roadrunner', MyRoadRunnerRuntime::class);  // 需实现 RuntimeInterface
$rt = Runtime::make('roadrunner');
```

## 从 v3.x 迁移

| v3.x | v4.0.0 |
|---|---|
| `Kode\Process\Compat\Worker` | `Runtime::make('workerman')` 或 `Runtime::auto()` |
| `Kode\Process\Server` | `Runtime::auto()` |
| `Kode\Process\Application` | `Kode::serve()` |
| `Kode::worker($addr, $n)` | `Kode::serve($addr, ['workers' => $n])` |
| `Kode\Process\Compat\Timer` | `Kode\Process\Timer`（顶层，API 不变） |
| `Kode::go()` / `Kode::batch()` | 保留，内部委托 `kode/fibers` |
| `Kode\Process\Coroutine\*` | 用 `kode/fibers`，或 Swoole 原生协程 |
| `Kode\Process\Cluster\*` | 移除（不属于进程内核职责） |
| `Kode\Process\Integration\*` | 移除（框架集成应为独立包） |
| `Kode\Process\Broadcast` / `Channel` | 移除（用网络版 GlobalData 或消息队列） |
