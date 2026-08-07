# 运行时（Runtime）

> 一套 API，三种实现：**Native（自研，默认）** / Swoole / Workerman。
> 业务代码只面向 `RuntimeInterface`，**切换底层无需改动任何一行代码**。

## 默认自研，可选接入

本包**默认使用自研的 Native 运行时**——纯 PHP 的 master-worker 多进程服务器，
零扩展依赖（仅需 CLI 自带的 `ext-pcntl` / `ext-posix`）即可跑起生产级服务。

Swoole / Workerman 作为**可选接入**保留：已有相应技术栈的项目可以一行切过去，
直接复用宿主生态（Swoole 协程、Workerman 组件），而业务代码完全不用改。

```php
use Kode\Process\Kode;

// 默认走自研 Native
Kode::serve('http://0.0.0.0:8080', ['workers' => 8])
    ->on('message', fn($conn, $req) => $conn->send('Hello, World!'))
    ->start();
```

切换底层只改第三个参数，**上面的业务回调一字不动**：

```php
Kode::serve('http://0.0.0.0:8080', ['workers' => 8], 'swoole')    // 接入 Swoole
Kode::serve('http://0.0.0.0:8080', ['workers' => 8], 'workerman') // 接入 Workerman
```

择优权重：**native(100) → swoole(90) → workerman(80)**。
`Runtime::auto()` 默认选中 Native；传偏好列表可覆盖：

```php
use Kode\Process\Runtime;
use Kode\Process\Runtime\RuntimeType;

$rt = Runtime::auto();                          // → native
$rt = Runtime::auto(['swoole', 'workerman']);   // 命中第一个可用的
$rt = Runtime::make(RuntimeType::Workerman);    // 显式指定
```

> **为什么默认自研？** 零依赖、能力可控、与 `Kode` 门面（共享表 / IPC / 信号 / 定时器）
> 无缝衔接，且吞吐与 Workerman 同一量级（见 [benchmark.md](./benchmark.md)）。
> 在这个负载区间，端到端吞吐主要受内核网络栈约束，PHP 用户态只占全链路的小头，
> 因此"自研 vs 成熟框架"的差距远小于直觉——真正的选型依据是生态与能力，而非 QPS。

## 统一 API

```php
$rt = Runtime::auto();

// 生命周期
$rt->listen(string $address, array $options = []): static
$rt->on(string $event, callable $handler): static
$rt->start(): void
$rt->stop(bool $graceful = true): void
$rt->reload(): void

// 定时器
$rt->addTimer(float $interval, callable $cb, bool $periodic = true): int
$rt->delTimer(int $timerId): bool

// 进程与连接（三运行时统一，v5.0.0 新增）
$rt->workerId(): int                                  // 当前 worker 序号
$rt->connections(): array                             // 本 worker 的活跃连接
$rt->broadcast(string $data, bool $raw = false): int  // 广播，返回送达数
$rt->task(mixed $data): bool                          // 投递异步任务

// 自省
$rt->supports(Capability $cap): bool
$rt->capabilities(): array
$rt->stats(): array
$rt->isRunning(): bool
```

### 地址格式

| 前缀 | 说明 | Native | Swoole | Workerman |
|---|---|:-:|:-:|:-:|
| `tcp://host:port` | 裸 TCP，不分包 | ✅ | ✅ | ✅ |
| `http://host:port` | HTTP/1.1（含 keep-alive） | ✅ | ✅ | ✅ |
| `websocket://` / `ws://` | WebSocket | ✅ | ✅ | ✅ |
| `text://host:port` | 文本 + 换行分包 | ✅ | ❌ | ✅ |
| `frame://host:port` | 长度前缀分包 | ✅ | ❌ | ✅ |
| `udp://host:port` | UDP | ✅ | ✅ | ✅ |
| `unix:///path.sock` | Unix Domain Socket | ✅ | ✅ | ✅ |
| `ssl://host:port` | TLS | ✅ | ✅ | ✅ |

> Native 下 `http` / `websocket` / `text` / `frame` 均跑在 TCP 之上，
> 由本包 `Protocol` 协议栈在连接层解析，`message` 事件语义与 Swoole / Workerman 一致。

**WebSocket 控制帧自动处理**：three 运行时行为一致——对端 `ping` 由运行时**自动回 `pong`**，对端 `pong` **静默忽略**，用户 `on('message')` 只收到应用消息（`text` / `binary` / `close`），无需自行处理保活。该逻辑在 `NativeRuntime::handleClientRead` 内统一处理；Swoole / Workerman 由各自引擎层自动完成，因此切换运行时时业务代码零改动。

## 自定义协议（一等公民）

除内置 `tcp` / `http` / `websocket` / `text` / `frame` / `udp` / `unix` / `ssl` 外，你可以用 `ProtocolFactory::register()` 注册任意协议，然后像内置协议一样直接 `Kode::serve('yourproto://..')`。注册后即可作为 `address` scheme 使用，**无需改运行时选择、无需改业务代码**——与 Workerman / Swoole 的「自定义协议」能力对齐。

自定义协议需实现 `Kode\Process\Protocol\ProtocolInterface`：

```php
interface ProtocolInterface
{
    public static function getName(): string;
    public static function input(string $buffer, mixed $connection = null): int;   // 0=需更多数据, -1=协议错误, >0=整帧长度
    public static function decode(string $buffer, mixed $connection = null): mixed;
    public static function encode(mixed $data, mixed $connection = null): string;
}
```

`NativeRuntime` 在收包时循环调用 `input()` 做分包，再用 `decode()` 还原消息、`encode()` 编码出站数据（连接 `send()` 时自动调用）。因此粘包 / 半包由协议自己负责，与内置协议完全一致。

```php
use Kode\Process\Kode;
use Kode\Process\Protocol\ProtocolFactory;

// 以类字符串注册（推荐）
ProtocolFactory::register('echo', EchoProtocol::class);

// 之后即可当作 scheme 使用：
Kode::serve('echo://0.0.0.0:9000')
    ->on('message', fn ($conn, $msg) => $conn->send(strtoupper($msg)))
    ->start();
```

> 说明：注册的协议会进入 `NativeRuntime::supportedSchemes()`，并在 `protocolClassFor()` 中回退解析，
> 所以 `Kode::serve` 的 scheme 校验与协议类选择都会认得它。Swoole / Workerman 运行时下自定义协议由各自引擎
> （如 Workerman 的 `Worker->protocol`、Swoole 的裸 TCP + 手动分包）承接，业务层 `on('message')` 语义不变。

### listen 选项

```php
$rt->listen('http://0.0.0.0:8080', [
    // ---- 三运行时通用 ----
    'workers'    => 8,          // worker 进程数
    'name'       => 'my-app',   // 进程名
    'reusePort'  => true,       // SO_REUSEPORT 内核级负载均衡
    'maxRequest' => 100000,     // 处理满 N 个请求后回收该 worker（对抗内存增长）
    'backlog'    => 65535,      // accept 队列长度
    'ssl'        => ['local_cert' => '/path/server.pem', 'local_pk' => '/path/server.key'],
    'taskWorkers' => 4,         // Task 工作进程数（Native / Swoole）

    // ---- Native 专有 ----
    'loop'          => 'event', // 事件循环：event / ev / select（默认自动择优）
    'daemonize'     => true,    // 守护进程（double-fork + setsid）
    'pidFile'       => '/var/run/app.pid',
    'logFile'       => '/var/log/app.log',
    'user'          => 'www',   // 降权运行
    'group'         => 'www',
    'heartbeat'     => 60,      // 空闲连接回收秒数，0 关闭
    'keepAlive'     => true,    // HTTP keep-alive，默认开
    'maxSendBuffer' => 8388608, // 单连接发送缓冲上限，超出即断开（背压）
    'stopTimeout'   => 10,      // 优雅停机最长等待秒数

    // ---- Swoole 专有 ----
    'mode'       => 'process',  // 切到 SWOOLE_PROCESS（默认 BASE，吞吐高约 8%）

    // ---- Workerman 专有 ----
    'workermanCli' => true,     // 保留 Workerman 原生 CLI（start/stop/reload/status）
]);
```

> **事件循环**：Native 通过 `LoopFactory` 自动择优 `ext-event` → `ext-ev` → `stream_select`。
> 装了 `ext-event` 就自动走 C 层多路复用，高连接数下是数量级差异。
> Workerman 同理（官方也推荐 `pecl install event`），未安装时
> `Runtime::diagnose()` 会给出安装建议。

> **Workerman 的 argv 约定已被屏蔽**：Workerman 原生会把 `$argv[1]` 解析成
> `start`/`stop`/`reload` 子命令，参数不合法就打印用法并退出。这会破坏"切换运行时
> 不改代码"的承诺（Native / Swoole 都不解析 argv），因此本包默认注入合成 argv。
> 确实需要 Workerman 原生 CLI 时传 `'workermanCli' => true`。

### 事件

| 事件 | 签名 | 说明 |
|---|---|---|
| `workerStart` | `fn(int $workerId)` | worker 启动 |
| `workerStop` | `fn(int $workerId)` | worker 退出 |
| `connect` | `fn(ConnectionInterface $conn)` | 新连接建立 |
| `message` | `fn(ConnectionInterface $conn, mixed $data)` | 收到完整报文 |
| `close` | `fn(ConnectionInterface $conn)` | 连接关闭 |
| `error` | `fn(?ConnectionInterface $conn, Throwable $e)` | 异常收敛点 |
| `task` | `fn(mixed $data, int $fromWorkerId): mixed` | 在 task 进程中执行 |
| `finish` | `fn(mixed $result)` | task 结果回到投递方 |

> 任一回调抛出的异常都会被收敛到 `error` 处理器，不会打挂整个 worker。

### 连接抽象

三种运行时的"连接"原生表示各不相同（Swoole 是 `int $fd`，Workerman 是
`TcpConnection`，Native 是流套接字资源），`ConnectionInterface` 把它们收敛为同一套操作：

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

## 异步任务（Task）

把耗时操作甩给独立的 task 进程，避免阻塞 I/O 循环：

```php
$rt = Kode::serve('http://0.0.0.0:8080', [
    'workers'     => 4,
    'taskWorkers' => 4,
]);

$rt->on('message', function ($conn, $req) use ($rt) {
    $rt->task(['type' => 'report', 'uid' => 42]);   // 立即返回，不阻塞
    $conn->send('accepted');
});

$rt->on('task', function (mixed $data, int $fromWorkerId): string {
    return heavyJob($data);      // 跑在 task 进程里
});

$rt->on('finish', function (mixed $result): void {
    // 回到原 worker 进程
});
```

> **优雅降级**：未配置 `taskWorkers` 的运行时（如 Workerman）或未启动时，
> `task()` 会**就地同步执行**并照常触发 `finish`，而不是抛异常。
> 这样同一份业务代码切到任何运行时都能跑通，只是并发模型不同。

## 能力探测

不同运行时能力集不同，**应通过 `supports()` 做优雅降级，不要假定能力一定存在**：

```php
use Kode\Process\Runtime\Capability;

if ($rt->supports(Capability::Coroutine)) {
    // Swoole 独有：原生协程
}
```

| 能力 | Native | Swoole | Workerman |
|---|:-:|:-:|:-:|
| `Coroutine` 原生协程 | ❌ | ✅ | ❌ |
| `TaskWorker` Task 工作进程 | ✅ | ✅ | ❌ |
| `AsyncIo` 异步 I/O | ❌ | ✅ | ❌ |
| `UdpServer` | ✅ | ✅ | ✅ |
| `Ssl` | ✅ | ✅ | ✅ |
| `SharedTable` | ✅ | ✅ | ✅ |
| `UnixSocket` | ✅ | ✅ | ✅ |
| `HotReload` 平滑重载 | ✅ | ✅ | ✅ |
| `ReusePort` | ✅ | ✅ | ✅ |
| `WebSocket` | ✅ | ✅ | ✅ |
| `Timer` | ✅ | ✅ | ✅ |

> Native 不提供**原生协程**与**原生异步 I/O**——二者都需要 C 层支持，纯 PHP 事件循环做不到
> （Native 的 SelectLoop 只做非阻塞套接字调度，并非 OS 级异步 I/O）。
> 需要协程时用 `kode/fibers`（Fiber 协作式调度），需要原生异步 I/O（如异步文件 / DNS）时直接接入 Swoole。

## 自研 Native 运行时详解

- **master-worker（prefork）**：master fork 出 N 个 worker + M 个 task 进程，
  `pcntl_wait(WNOHANG)` 循环守护，子进程异常退出立即 refork。
- **可插拔事件循环**：`ext-event` → `ext-ev` → `stream_select` 自动择优，也可用
  `'loop' => 'select'` 强制指定。
- **异步发送缓冲 + 背压**：`send()` 未写完的字节进缓冲区，注册 `onWritable` 续写；
  堆积超过 `maxSendBuffer` 即断开该连接，防止慢客户端拖垮内存。
- **信号语义**：`SIGTERM`/`SIGINT` 优雅停机（受 `stopTimeout` 约束），
  `SIGUSR1` 平滑重载（逐个回收并 refork worker，连接不中断）。
- **HTTP keep-alive**：遵循请求的 `Connection` 头，`Connection: close` 时
  发送完成后再关闭（`closeAfterFlush()`），不会截断响应。
- **运维能力**：`daemonize` 守护化、`pidFile`、进程改名、`user`/`group` 降权、
  `maxRequest` 请求数回收、`heartbeat` 空闲连接回收。
- **协议复用**：HTTP / WebSocket / Text / LengthPrefix / TCP 全部走本包 `Protocol` 协议栈。

## 环境自检

```php
print_r(Kode\Process\Runtime::diagnose());
```

```
[
    'preferred'      => 'native',
    'loop'           => ['event' => ['supported'=>true,'priority'=>100,'preferred'=>true], ...],
    'runtimes'       => [
        'native'    => ['available'=>true,  'version'=>'5.0.0', 'priority'=>100, 'preferred'=>true],
        'swoole'    => ['available'=>true,  'version'=>'6.2.2', 'priority'=>90,  'preferred'=>false],
        'workerman' => ['available'=>true,  'version'=>'5.2.2', 'priority'=>80,  'preferred'=>false],
    ],
    'recommendation' => null,  // Linux 且未装 ext-event 时为安装建议字符串
]
```

### 扩展依赖检测（未启用则提示）

`Runtime::requirements()` 报告每个运行时所需的 PHP 扩展 / Composer 包，并标出当前环境缺失了哪些。
当某运行时不可用（如未装 `ext-swoole`）时，`Runtime::make('swoole')` 抛出的异常会**精确列出缺失的扩展名与安装命令**，而不是笼统报错：

```php
print_r(Kode\Process\Runtime::requirements());
// native    => ['extensions'=>['pcntl','posix'], 'missing_extensions'=>[], ...]
// swoole    => ['extensions'=>['swoole'],        'missing_extensions'=>[], ...]
// workerman => ['extensions'=>['pcntl','posix'], 'package'=>'workerman/workerman', ...]
```

命令行 `kode check` 直接打印这份自检表，部署前一眼看清缺了哪个扩展：

```bash
php bin/kode check
# Kode Process 运行时依赖自检
#   native     [OK  ]  ext: pcntl, posix
#   swoole     [OK  ]  ext: swoole
#   workerman  [OK  ]  ext: pcntl, posix
```

## 进程管理命令（bin/kode）

`bin/kode` 提供完整的服务生命周期管理，`start` 会自写 PID / 命令文件，使 stop / reload / status / restart 自洽：

| 命令 | 作用 |
|------|------|
| `kode start <file>` | 启动服务（默认 `server.php`） |
| `kode stop` | 停止服务 |
| `kode restart <file>` | 平滑重启：停止旧进程后以 detached 新进程启动；不传 `file` 时沿用上次启动文件 |
| `kode reload` | 平滑重载（信号 `HUP`） |
| `kode status` | 查看运行状态 |
| `kode check` | 运行时依赖自检（各运行时所需扩展 / 缺失提示） |
| `kode info` | 版本信息 |

> `restart` 内部先向旧 master 发 `SIGTERM` 并等待其退出，再用 `nohup ... &` 以脱离当前进程组的方式
> 启动新进程，因此可在 CI / 部署脚本中安全调用，不会因调用方退出而连累新服务。



## 接入自定义运行时

```php
Runtime::register('roadrunner', MyRoadRunnerRuntime::class);  // 需实现 RuntimeInterface
$rt = Runtime::make('roadrunner');
```

## 迁移说明

### v4.x → v5.0.0

| 变化 | 说明 |
|---|---|
| 默认运行时 | `auto()` 由 swoole 优先改为 **native 优先** |
| 保持旧行为 | 显式写 `Runtime::auto(['swoole', 'workerman'])` |
| 新增统一 API | `workerId()` / `connections()` / `broadcast()` / `task()` |
| 新增事件 | `task` / `finish` |
| HTTP 响应 | `$conn->send('文本')` 现在会自动补全状态行与 `Content-Length`（此前直接裸发） |
| Workerman argv | 默认不再解析 `$argv` 子命令，需要时传 `'workermanCli' => true` |

### v3.x → v4.x

| v3.x | v4.x |
|---|---|
| `Kode\Process\Compat\Worker` | `Runtime::make('workerman')` 或 `Runtime::auto()` |
| `Kode\Process\Server` | `Runtime::auto()` |
| `Kode\Process\Application` | `Kode::serve()` |
| `Kode::worker($addr, $n)` | `Kode::serve($addr, ['workers' => $n])` |
| `Kode\Process\Compat\Timer` | `Kode\Process\Timer`（顶层，API 不变） |
| `Kode\Process\Coroutine\*` | 用 `kode/fibers`，或 Swoole 原生协程 |
| `Kode\Process\Cluster\*` | v4.x 临时移除；**v5.0.0 重新引入**为 `Kode\Process\Cluster`（分布式协调子系统：服务发现 / 分布式锁 / Leader 选举 / 负载均衡 / 分布式 ID / 限流 / 集群 RPC），详见 [cluster.md](./cluster.md) |
| `Kode\Process\Broadcast` / `Channel` | v4.x 移除；v5.0.0 以 `Cluster::broadcast()`（集群 RPC 广播）与网络版 GlobalData 替代 |
