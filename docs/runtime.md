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

**WebSocket 分片自动重组（RFC 6455 §5.4）**：超过单帧上限的大消息会被拆成多帧（FIN=0 首帧 + 续帧，FIN=1 末帧），三运行时均会在连接层将其**重组为一条完整消息**再派发到 `on('message')`，用户永远不会收到被拆碎的中间帧。重组缓冲挂在 `NativeConnection` 上，受 `WebSocketProtocol::MAX_PAYLOAD_LENGTH`（10 MiB）上限保护；协议错误（续帧无首帧、分片中途插入非续帧、超上限）将直接断开该连接。

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
    'heartbeat'     => 60,      // 空闲连接回收秒数（默认 0 = 关闭！见下方安全提示）
    'keepAlive'     => true,    // HTTP keep-alive，默认开
    'maxSendBuffer' => 8388608, // 单连接发送缓冲上限，超出即断开（背压）
    'stopTimeout'   => 10,      // master 等待 worker 退出的最长秒数（超时 SIGKILL）
    'gracefulShutdownTimeout' => 3, // worker 内等待在途连接优雅收尾的秒数（默认 3）

    // ---- HTTP/2（Native 专有，默认开启）----
    'http2'                       => true,    // 是否在 TLS 监听上协商 HTTP/2
    'http2MaxConcurrentStreams'   => 128,    // 单连接最大并发流数（防单连接占满）
    'http2InitialWindow'          => 1048576,// 流初始窗口字节数
    'http2MaxHeaderListSize'      => 65536,  // 请求头列表最大字节数（防超大头攻击）
    'http2MaxRequestBody'         => 10485760,// 单条流请求体上限字节数（防请求体无限缓冲耗尽内存，与 HTTP/1.1 MAX_LENGTH 对称）

    // ---- Swoole 专有 ----
    'mode'       => 'process',  // 切到 SWOOLE_PROCESS（默认 BASE，吞吐高约 8%）

    // ---- Workerman 专有 ----
    'workermanCli' => true,     // 保留 Workerman 原生 CLI（start/stop/reload/status）
]);
```

> **⚠️ 安全：空闲连接回收 `heartbeat` 默认是 0（关闭）。**
> 不配置时，空闲连接会一直挂着，恶意客户端可借此发起 **慢速攻击（Slowloris）**——开大量半开/空闲连接耗尽 worker 与文件描述符。
> 生产环境建议显式配置，例如 `'heartbeat' => 60`（60 秒无活动即回收）。
> 同理，TLS 监听下 `http2MaxConcurrentStreams` / `http2MaxHeaderListSize` 已设上限以抵御单连接占满与超大头攻击，按需收紧即可。

> **两个停机超时的关系**：`gracefulShutdownTimeout` 是 **worker 内**给在途连接收尾的宽限期，
> `stopTimeout` 是 **master** 等 worker 退出、超时就 `SIGKILL` 的硬上限。
> 若 `stopTimeout <= gracefulShutdownTimeout`，master 会在宽限期还没走完时就把 worker 打死，
> 在途请求被硬截断——优雅停机形同虚设。因此运行时会自动把
> `stopTimeout` 抬到至少 `gracefulShutdownTimeout + 1`，你无需手工保证这个约束，
> 但配置时按「`stopTimeout` 明显大于 `gracefulShutdownTimeout`」来写更清晰。

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
> 定时器回调（`addTimer`）同样做异常隔离：回调抛异常被 `error_log` 记录后继续，
> 绝不穿透事件循环打死 worker（详见下方「进程健壮性」）。

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

// HTTP 流式响应（chunked）：大响应无需全量缓冲进内存
$conn->beginChunked(int $status = 200, array $headers = []): bool
$conn->chunk(string $data): bool
$conn->endChunk(): bool
$conn->isChunkStarted(): bool

// 发送 PSR-7 响应对象（Psr\Http\Message\ResponseInterface），应用层与传输层桥接
$conn->sendResponse(\Psr\Http\Message\ResponseInterface $response, bool $autoGzip = true): bool
```

### HTTP 流式响应（Transfer-Encoding: chunked）

服务端向客户端**边生成边发**大响应，无需把整段 body 先缓冲进内存，首字节更早到达。
三运行时 API 完全一致，业务代码零改动：

```php
Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($conn, $req): void {
        // 自定义状态码与响应头（可选）；不调用 beginChunked 则使用默认 200 + text/html
        $conn->beginChunked(200, ['Content-Type' => 'text/plain']);
        $conn->chunk('part1-');
        $conn->chunk('part2-');
        $conn->chunk('part3');
        // 终止块（0\r\n\r\n）由运行时在 handler 返回后自动补发，无需手动 endChunk()
    })
    ->start();
```

要点：

- **非 HTTP 连接**（`tcp` / `websocket` / `text` 等）调用 `chunk()` 等价 `send()`，语义自动降级，跨协议代码无需 `if` 判断。
- **默认头**：未 `beginChunked` 直接 `chunk()` 时，使用 `200 OK` + `Content-Type: text/html; charset=utf-8`；`Transfer-Encoding: chunked` 始终由运行时注入。
- **自动收尾**：handler 返回后，运行时自动补发终止块。如需长流（如 SSE），可显式调用 `endChunk()` 结束。
- Swoole HTTP 模式底层走 `Swoole\Http\Response::write()`（自动 chunked）；Native / Workerman 走裸 chunked 字节——统一抽象掩盖了差异。

### HTTP 响应 gzip 压缩（Accept-Encoding 自动）

服务端可透明地按客户端能力压缩响应，省带宽、降首屏。默认开启，**业务代码零改动**：

```php
// 业务照常 send()，运行时依据请求的 Accept-Encoding 自动压缩（≥1KB 才压缩）
$conn->send($bigHtml);

// 或在确定要压缩时显式调用（可覆盖状态码/响应头）
$conn->gzip($bigJson, 200, ['Content-Type' => 'application/json']);
```

要点：

- **自动压缩（透明）**：HTTP 请求携带 `Accept-Encoding: gzip` 且响应体 ≥ `HttpProtocol::GZIP_MIN_SIZE`(1 KB) 时，运行时自动以 `Content-Encoding: gzip` 返回；handler 只需普通 `send()`，切换运行时零改动。
- **显式压缩**：`$conn->gzip($data, $status, $headers)` 强制发送压缩响应（即使客户端未声明），适合大 JSON / 静态资源等已知场景。
- **可按需关闭**：serve 选项 `'gzip' => false` 全局禁用自动压缩（默认 `true`）。
- **非 HTTP 连接**（`tcp` / `websocket` / `text`）调用 `gzip()` 等价 `send()`，语义自动降级。
- 实现差异被隐藏：Native / Workerman 拼完整压缩响应报文；Swoole HTTP 模式经 `gzencode` 后由 `Swoole\Http\Response` 发出——统一抽象掩盖了差异。
- 兼容 `Accept-Encoding: gzip, deflate`；`gzip;q=0` 视为拒绝，不压缩。

### 发送 PSR-7 响应（应用层桥接）

若你在上层使用 `kode/http` 等提供 `Psr\Http\Message\ResponseInterface` 的实现（路由、中间件产出
的 HTTP 响应），可直接把它桥接回当前连接，无需手动拆状态码 / 头 / 体：

```php
use Psr\Http\Message\ResponseInterface;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($conn, $req) use ($app): void {
        $psr = $app->handle($req);          // 任意 Psr\Http\Message\ResponseInterface
        $conn->sendResponse($psr);          // 序列化为 HTTP 报文并写回，三运行时零改动
    })
    ->start();
```

要点：

- **零改动跨运行时**：`sendResponse()` 把 PSR-7 响应统一序列化为完整 HTTP 报文——Native /
  Workerman 走裸字节写出，Swoole HTTP 模式走原生 `status/header/end`，HTTP/2 走 HEADERS + DATA
  帧。业务代码在三种运行时下写法完全一致。
- **头部清洗（防响应拆分）**：底层复用 `HttpProtocol::headerLine()`，剔除头名 / 头值中的
  CR / LF / NUL；PSR-7 未提供原因短语时回退到标准原因文本（与 `HttpProtocol::getStatusText` 一致）。
- **同名多值头**：多个 `Set-Cookie` 等逐条输出（HTTP/1.1 多条头行；HTTP/2 多个同名头对），不塌缩。
- **Content-Length 自动补 / 保留**：响应缺失时自动补 `Content-Length`，已显式声明则原样保留。
- **自动 gzip**：`$autoGzip = true`（默认）时，若连接已声明接受 gzip（`isGzipAuto()`）且响应体
  ≥ `GZIP_MIN_SIZE`(1 KB)，自动以 `Content-Encoding: gzip` 返回；响应已带 `Content-Encoding`
  时不二次压缩。传 `false` 可强制原样输出。
- **Swoole 限制**：HTTP 模式响应一次性写出（`responded` 置位后再次 `sendResponse` 返回 `false`），
  且 Swoole 原生 API 不暴露自定义原因短语（使用对应状态码的默认原因短语）。

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
  `SIGUSR1` **与 `SIGHUP`** 均为平滑重载（逐个回收并 refork worker，连接不中断）。
  `bin/kode reload` 发的是 `HUP`——此前 master 未注册该信号，默认动作是终止进程，
  「重载」实际把服务直接杀掉了。
- **HTTP keep-alive**：遵循请求的 `Connection` 头，`Connection: close` 时
  发送完成后再关闭（`closeAfterFlush()`），不会截断响应。
- **运维能力**：`daemonize` 守护化、`pidFile`、进程改名、`user`/`group` 降权、
  `maxRequest` 请求数回收、`heartbeat` 空闲连接回收。
- **协议复用**：HTTP / WebSocket / Text / LengthPrefix / TCP 全部走本包 `Protocol` 协议栈。

### 进程健壮性（运行时 / 进程级加固）

下表聚焦 **Native 运行时的进程级容错与运维加固**（部分能力在 Swoole / Workerman 后端由对应引擎原生提供，业务代码零改动即可享受）。定时器 / 异步 / 协程 / 集群 / 监控等子系统的加固沿革见各自文档（[timer.md](./timer.md)、[parallel.md](./parallel.md)、[cluster.md](./cluster.md)、[monitor.md](./monitor.md)）。

| 项 | 说明 |
|----|------|
| **崩溃退避** | worker 启动即崩时不再无限速 refork。连续异常退出会按次数退避重启，避免 fork 风暴打满 CPU 与 PID |
| **降权失败即报错** | `user`/`group` 降权时逐步校验 `posix_setgid` / `initgroups` / `posix_setuid`，任一步失败直接抛异常退出；此前失败被静默吞掉，**服务继续以 root 运行** |
| **守护化加固** | `daemonize` 现在处理 fork 失败、`chdir('/')`（避免占住挂载点）、并重定向 `STDIN` |
| **重生 worker 重置信号** | 新 fork 的子进程先把继承自 master 的信号处理器与 `pcntl_async_signals` 复位，再注册自己的；此前这段窗口内子进程会以 master 语义向兄弟进程广播信号 |
| **`unix://` + `reusePort`** | Unix socket 监听一律由 master 打开并共享。此前每个 worker 各自 `bind` 同一路径，后启动的会删掉先启动的 socket 文件 |
| **task 管道完整写** | 投递任务时 `fwrite` 的部分写会进缓冲、注册 `onWritable` 续写；此前截断的半帧会让该管道后续所有消息错位 |
| **定时器可在 `start()` 前注册** | `addTimer()` 在服务启动前调用会进待重放队列，`start()` 后统一注册；`delTimer()` 同样能取消尚未生效的定时器 |
| **定时器回调异常隔离（v5.2.18）** | 三运行时的 `addTimer()` 回调统一做异常隔离：回调抛异常被 `error_log` 记录后继续，绝不穿透事件循环打死 worker。Swoole / Workerman 此前裸传回调、异常会致命退出；Native 三 Loop 早已隔离。一次性定时器触发后底层自动移除，本端映射也一并清理，避免陈旧 timer id 残留 |
| **UDP 回包路由（v5.2.19）** | `receiveUdp()` 单次可读事件内**循环排空**所有挂起数据报（`recvfrom` 直到 EAGAIN），边缘触发 loop（ev）下不循环会丢包；缓冲区上限用 `UDP_MAX_PACKET = 65507`（UDP 数据报理论最大值），杜绝大包被 `recvfrom` 静默截断 |
| **TCP 连接异常回收（v5.2.19）** | message handler 抛异常被 error 处理器兜底后，运行时会**主动关闭该 TCP 连接**，而非留在连接表等心跳超时——避免半响应挂住客户端、连接泄漏。底层由 `fireMessage()` 返回 `bool` 告知调用方是否发生异常 |
| **message handler 异常对称回收（v5.2.20）** | Swoole / Workerman 的 message 派发同样消费 `fireMessage()` 返回的 `bool`：HTTP 场景通过 `close()` 干净结束响应（空 200）、TCP/WebSocket 主动关闭底层连接，兜底后不再让半响应挂住客户端（与 Native 一致）。UDP 数据报（`packet` 回调）无连接，其 fd 是 server socket，**绝不做关闭动作** |
| **Master 主循环错误边界（v5.2.21）** | `runEventLoop()` 单次迭代抽成 `tick()`，对 `pcntl_signal_dispatch` / `checkHeartbeat` / `checkMemory` / `checkWorkers` / 自动重启**每一步分别做 try/catch 隔离**——用户 heartbeat 回调或任一检查抛异常只记日志、绝不穿透外层循环崩掉 master（连带杀死所有 worker）。信号回调层此前已由 `SignalHandler::dispatch` 隔离 |
| **子进程回收 / 僵尸处理（v5.2.22）** | `reapChildren()` 退出状态解读修正：先 `pcntl_wifsignaled` 再取 `pcntl_wtermsig`，避免被信号杀死的 worker 误用 `pcntl_wexitstatus` 得到垃圾退出码并触发 PHP warning（原实现缺陷）；结构化记录「正常退出 / 被信号终止(signal 名)」。并新增 **worker 自动重生**：worker 退出时若处于运行中且注入了 spawner（ProcessManager 默认注入 `WorkerPool::addWorker`），按稳定 slot 重生以维持池容量；连续异常退出超过上限（`maxRestartAttempts=5`）则停止重生并告警，防 fork bomb。干净退出（如达 max_requests）直接重生不计入崩溃上限。停止/重启阶段不重生（否则关不掉） |
| **热重载边界（v5.2.23）** | `reload()`（HUP 触发）向 worker 发 USR1 前做边界隔离：仅向 `getState()===RUNNING` 的 worker 投递（`getState()` 是无副作用判活，不调用 `pcntl_waitpid` 干扰 CHLD 回收协议）；并跳过 `pid<=0` 或命中 master 自身 pid 的情况——原实现直接向所有 worker 的 pid 调 `posix_kill`，对已退出未重生的 stale pid 会命中内核回收的其它进程，且 `getPid()` 在 pid 为 0 时回退到 master 自身 pid、会误触发 master 的 USR1 日志轮转。失败记 warning 而非忽略。`posix_kill` 抽成 `deliverReloadSignal()` 便于测试 |
| **SelectLoop 惰性 prune（v5.2.28）** | 默认兜底事件循环（未装 ext-event/ev 的环境走它）旧实现**每次 `select()` 前**都全量 `pruneInvalidStreams()`，即每 tick 一次 O(N) `is_resource` 扫描。实测 `stream_select` 传入已关闭资源会抛 `ValueError`（`@` 无法抑制），故改为**惰性 prune**：正常 tick 零扫描直接 `stream_select`；仅当流被外部 `fclose` 未调 `off*` 抛 `ValueError` 时，才 `catch` 一次、剔除失效流、重建集合并重试。稳态每 tick 用户态开销 O(N)→O(1)；异常路径仍 fail-soft。N=2000、每 10万 tick 省约 **1416ms** 用户态扫描。行为由 `SelectLoopTest::testSelectLazilyPrunesExternallyClosedStream`（不抛 + 失效流被惰性剔除）与 `testSelectKeepsValidStreamsAcrossManyTicks`（多 tick 有效流不误伤）守护 |
| **SelectLoop FD_SETSIZE 状态健壮暴露（v5.2.29）** | `stream_select` 底层 bitset 受 `FD_SETSIZE`（通常 1024）限制，fd≥1024 的流会让 select 失败并退化为每 tick 空转——这是平台限制而非代码缺陷。v5.2.29 起，FD_SETSIZE 状态在**注册期**（`guardFdLimit`）与**运行期 select 失败**两处都能暴露，且经统一 `fdSetSizeWarned` 标志保证全生命周期**只告警一次**，既不让用户无感知地空转，也不刷屏。运行期检测用 `hasFdAtOrAboveSetSize()` 精准判定（集合里确含 ≥1024 的 fd 才告警，避免 EINTR 等误报）。契约由 `SelectLoopTest::testFdSetSizeWarningFiresExactlyOnce` 守护 |
| **PID 文件由 master 写** | 见下方 CLI 小节 |
| **常驻进程运行器 Daemon（v5.2.30）** | 官方 worker 池 `WorkerProcess::processTasks()` 在事件循环里**从不调用用户回调**（仅响应外部 `assignTask()`），导致 `Process::start($config, $cb)` 传入的周期任务实际空转（见 [daemon.md](./daemon.md) 的「为什么不用 `Process::start`」）。v5.2.30 新增 `Kode::daemon()`：只依赖 `Process::fork()` + `Timer` 两个原语，自建「监督进程 + N 个 worker 子进程」——worker 在独立事件循环里真正执行任务，监督进程负责信号、回收僵尸、异常退出重生（带 `maxRestarts` 上限防 fork bomb）、优雅退出；并提供 `kode process start/stop/restart/status` 子命令管理生命周期。契约由 `DaemonTest`（worker 经 fork 由 Timer 执行任务、`stopAllWorkers` 杀子进程、重生预算）与 `BinKodeTest::testProcessStartStopSmoke`（端到端 start→status→stop）守护 |
| **Daemon 重生计数健康窗口（v5.2.31）** | v5.2.30 的重生计数 `restartCount` 是**按槽位永久累计**的，长期运行下历史偶发崩溃会缓慢逼近 `maxRestarts` 上限。v5.2.31 引入 `healthyWindow`（默认 60s）：worker 连续健康存活超过该窗口后，下次异常退出时累计计数**先清零再 +1**，即旧崩溃不再计入。这避免长生命周期下「偶发崩溃 → 逼近上限 → 槽位被放弃」的缓慢退坡。可用 `->healthyWindow(秒)` 调整；契约由 `DaemonTest::testHealthyWindowResetsStaleRestartCount` 守护 |
| **Native 并发 accept 排空（v5.2.33）** | `NativeRuntime::accept()` 在**单次监听 socket 可读事件内循环 `stream_socket_accept` 直到 EAGAIN**，把内核 accept 队列里排队的连接全部接入。旧实现每次可读事件只 accept 一个就返回，在边缘触发事件循环（ext-ev / event）或 macOS SelectLoop 下，突发并发连接除首个外会被「搁置」→ 客户端 connect 被拒或挂起（实测 50 并发仅 48 建连成功、无崩溃、error_log 为空）。改法与 Workerman / Swoole 的 `while-accept-until-EAGAIN` 范式一致；契约由 `NativeRuntimeTest::testNativeConcurrentAcceptDrainsQueue`（单 worker 一次性异步建连 50 个，断言每个都拿到回显）守护 |
| **Swoole keep-alive fd 回收闸门（v5.2.33）** | 并发 keep-alive 下 Swoole 可能已回收底层 fd 对应的 C 层 `Swoole\Http\Response` 对象，此时若仍对其调用 `end()` 会触发 C 层崩溃（worker 静默退出、连接被拒）。v5.2.33 在所有 `$response->end(...)` 调用前加 `server->exists($fd)` 闸门（`SwooleConnection::endResponse()` 私有封装）：fd 已不存在即跳过写出，绝不踩踏已释放对象；无法判定（`server` 无 `exists` 方法）时退回原行为，不引入回归。请求入口额外调用防御性 `reset()` 清空 `$responded` / `$chunkStarted` 状态。契约由 `SwooleConnectionTest::testHttpModeWriteSkippedWhenFdRecycled`（模拟 fd 回收，断言所有写出路径不调用 `end()`）与 `testHttpModeWriteProceedsWhenFdAlive`（正常路径仍正常写出）守护 |
| **HTTP 头部缓冲上限（v5.2.34）** | **Slowloris 防护**：HTTP 请求头累积阶段（未出现 `\r\n\r\n`），`recvBuffer` 一旦超过 `MAX_HEADER_BUFFER`（64KB）立即断开连接。此前该阶段仅受 `HttpProtocol::MAX_LENGTH`（整请求 10MB）约束，单连接可把缓冲撑到 10MB、千连接级联即内存耗尽——`NativeRuntime.php` 旧注释早已标记此隐患（「不含 `\r\n\r\n` 的数据即可无限增长 recvBuffer → 单连接打爆 worker」）。WebSocket 握手首包同源受其约束（原 16KB 内联判断统一收敛到该常量）。该上限**独立于**整请求 10MB：头块定型后由 `HttpProtocol::input()` 常规长度校验接管，合法大 body 不受影响。契约由 `NativeRuntimeTest::testNativeHttpHeaderBufferCap`（发送 70KB 不含完整头块的数据，断言服务器主动断开且未派发到 handler）守护 |
| **WebSocket 升级首帧管道修复（v5.2.34）** | 修复 WebSocket 升级握手与首帧在**同一 TCP 包内合并发送**时的丢帧 bug：旧实现握手成功后整体 `clearBuffer()`，会丢弃紧随其后的首帧（部分客户端把 upgrade 与第一帧合并发送），表现为握手成功但首条消息石沉大海。改为仅截取握手请求部分（`\r\n\r\n` 之前），剩余字节落到帧处理循环立即消费；且握手后**不 `return`**，避免同一次 `fread` 已整段读入的数据因缺少新的可读事件而永久搁置。契约由 `NativeRuntimeTest::testNativeWebSocketHandshakePipelinedFrame`（合并发送握手+首帧，断言服务端正确回显）守护 |
| **慢读超时回收（v5.2.35）** | **滴流型 Slowloris 时间维度防护**：与 `MAX_HEADER_BUFFER`（头部体积上限）互补——体积上限挡「单连接缓冲撑爆」，时间上限挡「极慢滴流长期占连接」。新增 `readTimeout`（秒，默认 0 关闭，零行为变更）：任何「不完整请求滞留超过该时长」的连接由周期定时器 `recycleSlowReaders()` 回收。其关键在标记语义——`NativeConnection::markPartial()` 只在**首次**进入不完整态时落时间戳，后续滴流字节不向前推（否则客户端每 30s 发 1 字节就能永远重置计时）；缓冲清空或请求完成即 `clearPartial()`。与 `heartbeat`（看「有无读写」）正交：滴流字节可让心跳判定「活跃」，但始终凑不齐完整请求，本机制只看滞留时长。适用于暴露在不可信网络的 native 服务，建议显式开启（如 `['readTimeout' => 5]`）。契约由 `NativeRuntimeTest::testNativeReadTimeoutRecyclesSlowReader`（发半截请求头后不再发送字节，断言服务器在超时内回收连接、未派发 handler）守护 |
| **HTTP/2 请求体上限（v5.2.36）** | **请求体无限缓冲防护**：与 HTTP/1.1 `HttpProtocol::MAX_LENGTH`（整请求 10MB）对称——HTTP/2 接收窗口随字节到达而补发 `WINDOW_UPDATE`，对端可借此持续灌入请求体，若不设上限 `$stream['body'] 会被无限累积、单连接即耗尽内存。新增 `Http2Session` 的 `maxRequestBodySize`（默认 10MiB，运行时选项 `http2MaxRequestBody` 可调）：单个 DATA 帧后若 `strlen($stream['body'])` 超限即 `RST_STREAM(ENHANCE_YOUR_CALM)` 掐断该流（不影响连接与其他流），内存上界收敛为 `maxRequestBodySize × maxConcurrentStreams`。默认开启、零行为变更。契约由 `Http2SessionTest::testOversizedRequestBodyIsReset`（灌入 2KB 超过 1KB 上限，断言 RST_STREAM(0xb) 且新流仍可用）与 `testRequestBodyExactlyAtLimitIsAllowed`（恰好等于上限仍正常派发）守护 |

## 环境自检

```php
print_r(Kode\Process\Runtime::diagnose());
```

```
[
    'preferred'      => 'native',
    'loop'           => ['event' => ['supported'=>true,'priority'=>100,'preferred'=>true], ...],
    'runtimes'       => [
        'native'    => ['available'=>true,  'version'=>'5.2.36', 'priority'=>100, 'preferred'=>true],
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
| `kode process start <file>` | 启动常驻运行器（`<file>` 须 `return` 一个 `Daemon` 实例），支持 `--daemon` / `--workers=N` / `--every=N` / `--cron=expr` |
| `kode process stop` | 优雅停止常驻运行器（TERM → 回收 worker → 清理 PID） |
| `kode process restart <file>` | 平滑重启（detached 重启；不传 `file` 沿用上次启动文件） |
| `kode process status` | 查看常驻运行器运行状态 |

> `restart` 内部先向旧 master 发 `SIGTERM` 并等待其退出，再用 `nohup ... &` 以脱离当前进程组的方式
> 启动新进程，因此可在 CI / 部署脚本中安全调用，不会因调用方退出而连累新服务。

### PID 文件语义（v5.2.12 修正）

PID 文件现在由 **master 进程自己写入**，`bin/kode` 只负责把路径通过环境变量传下去。

此前是 `bin/kode` 在 `include` 业务文件**之前**写自己的 `getmypid()`——一旦服务
`daemonize`，那个 PID 属于已经 `exit` 掉的引导进程，`stop` / `reload` 会向一个不存在
（或已被系统复用给无关进程）的 PID 发信号。

配套加固：

- `stop` / `reload` / `status` 在发信号前校验该 PID 仍存活**且确实是本服务的 master**，
  避免 PID 复用后误伤同机其他进程；发现陈旧 PID 文件即清理。
- `restart` 等待旧 master 退出，超时后升级为 `SIGKILL`，**确认死亡后**才清理 PID 文件
  并拉起新进程，不再出现「旧进程仍占端口、新进程 bind 失败」的孤儿态。
- master 正常退出时清理自己写的 PID 文件。


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
