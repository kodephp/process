# HTTP/2

Native 运行时内置 HTTP/2（h2c）支持，**默认开启，无需任何配置**，与 HTTP/1.1 共用同一个端口自动协商。
客户端不支持 h2 时走原有 HTTP/1.1 路径，既不改握手也不增加解析开销。

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($conn, $req) {
        // 这段 handler 在 HTTP/1.1 与 HTTP/2 下完全一致
        $conn->send('hello ' . $req->protocol());
    })
    ->start();
```

验证：

```bash
# h2c 直连（prior knowledge）
curl --http2-prior-knowledge http://127.0.0.1:8080/

# h2c 升级（先发 HTTP/1.1 再 Upgrade: h2c）
curl --http2 http://127.0.0.1:8080/
```

## 两条进入 HTTP/2 的路径

| 方式 | 触发条件 | 典型客户端 |
|------|----------|------------|
| **prior knowledge** | 首包直接是连接前奏 `PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n` | `curl --http2-prior-knowledge`、gRPC |
| **h2c Upgrade** | HTTP/1.1 请求带 `Connection: Upgrade, HTTP2-Settings` + `Upgrade: h2c` | `curl --http2`、部分代理 |

两条路径都在协议未定型的**首包**判定；一旦判定为 HTTP/1.1，后续读取不再做任何 h2 探测
（布尔短路跳过），因此对纯 HTTP/1.1 流量是零成本的。

走 Upgrade 时，触发升级的那条请求会按 RFC 7540 §3.2 被接管为**流 1**，业务侧感知不到差异。

## 业务侧写法

HTTP/2 一条 TCP 连接上并行跑多条流，而 `on('message')` 的契约是「一个连接对象 + 一个请求」。
框架把**每条流**包装成 `Http2Stream`（实现 `ConnectionInterface`）交给 handler，于是同一份代码两种协议通用：

```php
$server->on('message', function ($conn, $req) {
    // $conn 在 HTTP/2 下是 Http2Stream，在 HTTP/1.1 下是 NativeConnection
    $conn->send('hello');           // 1.1 → 响应报文；2 → HEADERS + DATA 帧
});
```

需要区分时：

```php
if ($req->protocol() === 'HTTP/2') {
    $streamId = $conn->streamId();  // 当前流 ID（奇数）
}
```

### 响应头：同名多值

HTTP 响应头允许重复（多个 `Set-Cookie` 是常见需求），单纯的 `name => value` 表达不了，
因此响应头额外接受两种写法：

```php
// 1) 单值
['content-type' => 'application/json']

// 2) 同名多值
['set-cookie' => ['sid=abc; HttpOnly', 'csrf=xyz']]

// 3) 显式有序对（顺序完全由你决定）
[['set-cookie', 'sid=abc; HttpOnly'], ['set-cookie', 'csrf=xyz']]
```

三种可混用，头名大小写不敏感（内部统一转小写，HTTP/2 要求）。

### 流式响应

HTTP/2 没有 chunked 传输编码——分块语义天然由 DATA 帧承载，因此 `beginChunked()` 只发响应头，
后续 `chunk()` 直接追加 DATA 帧：

```php
$conn->beginChunked(200, ['content-type' => 'text/event-stream']);
$conn->chunk("data: tick\n\n");
$conn->chunk("data: tock\n\n");
$conn->endChunk();
```

## 配置项

传给 `Kode::serve()` 的第二个参数：

| 选项 | 默认值 | 说明 |
|------|--------|------|
| `http2` | `true` | 是否启用 h2c；设为 `false` 后完全按 HTTP/1.1 处理 |
| `http2MaxConcurrentStreams` | `128` | 单连接并发流上限，超出的流回 `RST_STREAM(REFUSED_STREAM)`，连接本身不受影响 |
| `http2InitialWindow` | `1048576`（1 MiB） | 本端通告的初始接收窗口，下限为协议默认的 64 KiB |

```php
Kode::serve('http://0.0.0.0:8080', [
    'workers'                   => 4,
    'http2MaxConcurrentStreams' => 256,
    'http2InitialWindow'        => 2 * 1024 * 1024,
]);
```

## 错误处理分级

按 RFC 7540 §5.4 区分两类错误，避免「一条流出问题拖垮整个连接」：

| 级别 | 处理 | 例子 |
|------|------|------|
| **流级** | 只发 `RST_STREAM` 重置该流，连接与其它流照常服务 | 超出并发流上限、单流上的畸形头 |
| **连接级** | 发 `GOAWAY` 后断开 | 无效连接前奏、非法流 ID、孤立的 CONTINUATION 帧 |

## 安全加固（CONTINUATION 洪泛防护）

HTTP/2 的头块由 `HEADERS` + 任意多个 `CONTINUATION` 帧拼成，框架只在看到
`END_HEADERS` 时才做 HPACK 解码。若对端只发帧、不置 `END_HEADERS`，许多实现会
把分片**无上限地**攒在内存里却不解码——这正是 2024 年的 CONTINUATION 洪泛
（CVE-2024-27316 同类）拒绝服务漏洞。

自研实现在拼接阶段即设了两道硬上限（见 `Http2Session`）：

- **头块体积上限** `MAX_HEADER_BLOCK_SIZE = 64 KiB`：累计压缩字节超过即视为攻击，
  立即 `RST_STREAM(PROTOCOL_ERROR)` 该流并丢弃，**不进入 HPACK 解码、不在内存中累积**。
- **CONTINUATION 帧数上限** `MAX_CONTINUATION_FRAMES = 16`：单条头块序列的帧数超过即拒绝，
  防止「每帧都很小、但数量巨大」的逐帧堆积。

两道上限均为**流级**处理：被攻击的流被重置，连接与其余多路复用流照常服务，攻击者可
继续发新流（同样会被拒），但不会拖垮进程。该行为有 `Http2SessionTest` 的
`testOversizedHeaderBlockIsRejectedWithoutAccumulating` / `testContinuationFloodIsRejected`
两个用例守护，并已证明洪泛后连接仍能正常服务新流。

## 优雅关闭（GOAWAY）

进程收到 `SIGTERM`（`bin/kode stop` / `restart` 也是经由此信号）时，Native 运行时
**不会直接掐断 HTTP/2 连接**，而是先给每条 h2 连接发 `GOAWAY`，再继续服务在途请求，
直到满足「所有连接都自然关闭」或「达到宽限期」二者之一才退出：

1. 停止接收新连接（移除监听套接字的可读监听），新连接由 LB / 客户端重试其它实例。
2. 对每条还在用的 HTTP/2 连接发 `GOAWAY`（`NO_ERROR`），告知对端「我即将离开，
   不要再发起新流，已在途的流可以继续完成」——对端据此干净地 drained 而非被 `RST` 硬切断。
3. 进入宽限期（默认 `3s`，可用 `gracefulShutdownTimeout` 调整）：在途请求/流照常处理，
   连接自然关闭；宽限期到点或所有连接都关闭后，进程退出。

宽限期内正常的 HTTP/1.1 keep-alive 连接也会随空闲回收自然退出，在途请求不受影响。
普通（非 h2）连接关闭等价于原来的直接 `close()`。这一机制让 `bin/kode restart` 滚动重启
时，正在进行的多路复用请求不会整批失败。

```php
Kode::serve('http://127.0.0.1:8080', [
    'workers' => 4,
    'gracefulShutdownTimeout' => 5,   // 宽限期（秒），默认 3
], 'native')
    ->on('message', $handler)
    ->start();
```

## 已知限制

| 限制 | 说明 |
|------|------|
| **仅 h2c（明文）** | 未实现 TLS ALPN 协商，因此不支持 `https://` 上的 h2。需要 TLS + HTTP/2 时建议前置 Nginx 终止 TLS，回源用 h2c 或 HTTP/1.1 |
| **不支持 Server Push** | `PUSH_PROMISE` 未实现（该特性已被主流浏览器废弃） |
| **不支持 trailers** | 已建立的流再收 HEADERS 会按协议错误处理 |
| **不做优先级调度** | `PRIORITY` 帧被接受但忽略，不参与调度 |
| **仅 Native 运行时** | Swoole / Workerman 下 HTTP/2 取决于宿主自身能力 |

## 内部结构

| 类 | 职责 |
|----|------|
| `Protocol\Http2\Frame` | 单帧的字节级编解码与常量，无连接状态 |
| `Protocol\Http2\Hpack` | RFC 7541 头压缩（静态表 + 动态表 + Huffman） |
| `Protocol\Http2\Http2Session` | 连接级状态机：流生命周期、流控、HPACK 上下文、SETTINGS 协商 |
| `Runtime\Driver\Http2Stream` | 单条流的 `ConnectionInterface` 视图，业务实际拿到的 `$conn` |

编码器刻意保持**无状态**（不写入动态表）：服务端响应头组合高度固定，索引收益有限，
而无状态编码可完全规避动态表同步风险，也让热路径没有额外分配。

## 相关文档

- [协议系统](protocol.md)
- [请求对象](request.md)
- [响应与边界](response.md)
- [压测数据](benchmark.md)
