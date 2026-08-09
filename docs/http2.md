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
| `http2MaxHeaderListSize` | `65536`（64 KiB） | 解压后（展开）头列表的最大体积（RFC 7540 §6.5.2）。超过即 `RST_STREAM(PROTOCOL_ERROR)` 该流，用于防御「压缩后很小、解压后极大」的头膨胀攻击，与 CONTINUATION 洪泛防护互补 |

```php
Kode::serve('http://0.0.0.0:8080', [
    'workers'                   => 4,
    'http2MaxConcurrentStreams' => 256,
    'http2InitialWindow'        => 2 * 1024 * 1024,
    'http2MaxHeaderListSize'    => 65536,
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

## 解压后头列表体积上限（MAX_HEADER_LIST_SIZE）

CONTINUATION 洪泛防护限的是**压缩后**的头块体积（`MAX_HEADER_BLOCK_SIZE`）。但 HPACK
的压缩比在理论上可非常高——一个很小的压缩块解压后可能膨胀成巨大的头列表（即
「压缩后很小、解压后极大」的头膨胀攻击）。为此 RFC 7540 §6.5.2 规定端点应声明自己
能接受的最大**解压后**头列表体积，并在超出时拒绝该流。

自研实现默认按 `DEFAULT_MAX_HEADER_LIST_SIZE = 65536`（64 KiB）向客户端通告
`SETTINGS_MAX_HEADER_LIST_SIZE`，并在解码循环内**边解边累加**展开体积，一旦超过即
`RST_STREAM(PROTOCOL_ERROR)` 该流并丢弃，不进入业务分发：

- **累加发生在 HPACK 解码循环内**：每个伪头 / 普通头首次出现计 `strlen(name)+strlen(value)+32`、
  同名多值合并计追加部分，省去解码后二次遍历，零额外开销。
- **与 CONTINUATION 洪泛防护互补**：前者限「压缩后」体积，后者限「解压后」体积，
  两道独立防线覆盖「分片堆积」与「高压缩比膨胀」两类攻击面。
- **可调**：通过 `http2MaxHeaderListSize` 选项调整上限，或设为 `0` 关闭该检查（不推荐）。

该行为由 `Http2SessionTest` 的 `testOversizedHeaderListIsRejected`（头列表超上限即被 RST，
活跃流归零）/ `testHeaderListBelowLimitIsAccepted`（未超上限正常组装）/
`testMaxHeaderListSizeAnnouncedInSettings`（SETTINGS 帧携带正确的上限值）三个用例守护。

## Rapid Reset 防护（CVE-2023-44487）

前两道防线管的是「单条头块有多大」，但还有一类攻击**每条请求都完全合法**：客户端
不断「发 `HEADERS` → 立刻发 `RST_STREAM`」。因为流被立即销毁，并发数永远触不到
`SETTINGS_MAX_CONCURRENT_STREAMS`，却已迫使服务端完成 HPACK 解码、分配流、派发请求——
单条连接就能打满 CPU。这就是 2023 年击垮多家大厂的 **HTTP/2 Rapid Reset**。

自研实现采用「**预算 + 抵扣**」而非时间窗口：

- 每收到一个对端 `RST_STREAM`，预算 **+1**；
- 每有一条流**正常完成响应**，预算 **−1**（不低于 0）；
- 预算超过 `max(100, maxConcurrentStreams × 4)`（默认并发 128 → 上限 512）时，
  立即 `GOAWAY(ENHANCE_YOUR_CALM)` 并关闭连接。

这样设计的好处：

- **不误伤正常客户端**。浏览器取消请求（用户点停止、图片中断）本就会发 `RST_STREAM`，
  但正常连接的「完成数」远多于「取消数」，预算稳定在 0 附近。
- **不依赖时钟**。行为完全确定、可复现、可测试，也不会因机器负载导致窗口漂移。
- **攻击一定触顶**。Rapid Reset 只重置不完成，预算只增不减，很快触线。

## 控制帧洪泛防护（PING / SETTINGS）

对端还可以「只发不读」：疯狂灌 `PING` / `SETTINGS`，迫使本端把 ACK 无限堆进发送缓冲，
造成内存放大。框架为此统计**两次 `drain()` 之间**排队的 ACK 数，超过 `1000` 即
`GOAWAY(ENHANCE_YOUR_CALM)`。

`drain()` 表示缓冲已交给传输层，计数随之归零——因此正常的心跳 PING（发一次、读一次）
永远不会触发该防护，只有「堆积」才会。

### 安全水位可观测

四道防线的实时水位可通过 `Http2Session::stats()` 读取，便于接监控告警：

```php
$s = $session->stats();
$s['reset_budget'];    // 当前 RST_STREAM 洪泛预算占用
$s['reset_limit'];     // 触发 GOAWAY 的上限
$s['queued_control'];  // 两次 drain 之间排队的控制帧 ACK 数
```

持续上涨即说明对端正在打 Rapid Reset 或控制帧洪泛。

> 四道防线小结：`MAX_HEADER_BLOCK_SIZE`（压缩后体积）、`MAX_HEADER_LIST_SIZE`（解压后体积）、
> RST_STREAM 预算（Rapid Reset）、控制帧排队上限（PING/SETTINGS 洪泛），分别覆盖
> 「分片堆积」「高压缩比膨胀」「合法请求高频重置」「ACK 堆积」四类攻击面。

## 解析层健壮性（v5.2.12）

HPACK 与流状态机针对畸形输入做了五处加固，全部由回归测试覆盖：

| 问题 | 原行为 | 现行为 |
|------|--------|--------|
| HPACK 变长整数超长 | 位移涨过 63 位后 `$value` 整数溢出为负，`STATIC_TABLE[负数]` 取到 `null`，**静默产出 `[NULL, NULL]` 头部并正常返回** | 位移超过 28 位或续读越界 → `Http2Exception`(COMPRESSION_ERROR) |
| Huffman 码字截断 | 最短长码为 10 位，`curBits` 为 8/9 时收尾循环算出 `1 << -1` 抛 `ArithmeticError`——不是 `Http2Exception`，穿透会话层 catch，**2 字节 `\x07\xfd` 即可打死 worker** | 抛 `Http2Exception`(COMPRESSION_ERROR)，降级为流/连接级错误 |
| HPACK 解压炸弹 | 头列表体积在**解完之后**才校验，62KB 全索引头块先展开成数百 MB 再被拒 | 解码循环内按 RFC 7541 §4.1 逐项累计（`name + value + 32`），超限即停止累积 |
| DATA 帧不校验流状态 | 对已发过 `END_STREAM` 的流继续发 DATA 会追加进 body，第二个 `END_STREAM` 让**同一请求被二次派发**给业务 handler | 非 `OPEN` 状态收到 DATA → `RST_STREAM(STREAM_CLOSED)`，不派发 |
| 被拒流跳过 HPACK 解码 | 触发并发上限被拒的流直接跳过解码，而 HPACK 是**连接级有状态**的，动态表就此与对端失步，后续所有流的头部解码全部错乱 | 始终解完头块维持上下文，之后才发 `RST_STREAM(REFUSED_STREAM)` |

> 前两条的共同性质是「解压缩阶段的异常没有被收敛成协议错误」——HPACK 解码在
> 业务 handler 之前执行，任何逃逸出去的 `Error` 都会直接带走整个 worker 进程。
> 现在 Huffman 解码器对全部 2 字节输入穷举验证，非 `Http2Exception` 逃逸为 0。

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

## HPACK 性能优化设计（四层纯函数缓存）

HTTP/2 每请求都强制走 HPACK 编解码，编码 / 解码是请求热路径里最重的纯函数。自 v5.2.0 起，
服务端编码器刻意保持**无状态**（不写动态表），这让几乎所有 HPACK 计算都变成
「输入唯一决定输出」的纯函数——于是可以用缓存彻底消去重复计算，且**线格式与未缓存时逐字节一致**，
不改变任何协议行为。

四层纯函数缓存组成闭环（详细压测见 [压测数据](benchmark.md) §3.3 / §3.0）：

| 缓存 | 方向 | 依据 | 收益 |
|------|------|------|-----:|
| `Hpack::$literalCache` | 明文 → 已编码字节 | 字面量编码只依赖该字符串本身 | 编码热路径 **≈5.7×** |
| `Http2Session::$responseBlockCache` | `(status, headers)` → 整个头块 | 服务端编码器恒走「不索引」表示、动态表恒空，整块编码即纯函数 | respond **≈2.2×** |
| `Hpack::$huffmanCache` | 已编码字节 → 明文 | Huffman 解码只依赖输入字节，与动态表无关 | `Hpack::decode` **≈1.93×** |
| `Hpack::$headerCache` | `name + value` → 整段已编码字节 | 整头编码只依赖该 `name + value` 组合，稳态响应头组合高度固定 | `Hpack::encode` **≈3.4×** |

要点：

- **编码侧闭环**：字面量缓存（值级）命中省去 Huffman 编码；整头缓存（整段）命中进一步省去静态表查对、
  整数编码与多次拼接。两者叠加，`Hpack::encode` 自 v5.2.0 的 537 ms 降到 v5.2.7 的约 144 ms
  （每 50 万次，≈3.4×）。
- **解码侧闭环**：Huffman 缓存把「编码字节 → 明文」直接查表；主循环内联（v5.2.6）消去 `readInteger`
  方法分发，v5.2.9 再把 `readStringInline`（字面量头名 / 头值的字符串读取）也展开进主循环——
  在 CLI JIT 关闭下引擎不会自动内联私有方法，这一步实测把字面量块解码再快约 1.22×。`Hpack::decode`
  自 v5.2.5 的 742 ms 降到 v5.2.9 的约 368 ms（每 50 万次，叠加内联 + Huffman 缓存 ≈1.93× +
  字符串读取内联 ≈1.22×，较 v5.2.7 基线再快约 21%）。
- **响应侧整块缓存**：稳态真实响应经 `responseBlockCache` 已跳过整段编码与 `normalizeHeaders`，
  因此 v5.2.7 的整头缓存主要惠及**缓存未命中的变体响应**与**主动编码场景**，不重复优化已快路径。
- **安全边界**：四层均有条目上限（1024 / 256 / 1024 / 1024）防止无限增长；Huffman 缓存额外限制
  单条 ≤ 512 字节且**只缓存解码成功的结果**，畸形输入抛异常不入缓存，攻击者无法用畸形数据占位。
  各缓存均提供 `clearLiteralCache()` / `clearResponseBlockCache()` / `clearHuffmanCache()` /
  `clearHeaderCache()` 便于测试隔离与基准冷启动。

`Hpack::$headerCache` 的键为嵌套数组 `(name => value => bytes)`（非拼接字符串），
**同名不同值的头不会串**；达到上限后停止写入（`storeHeader`），不增长内存。正确性由
「RFC 7541 附录 C 全部向量 + 增量索引 + 4000 组随机头 fuzz + 同名异值不串」全量验证，
全量 768 测试 / 9327 断言 / 1 skipped 全绿。

## 大响应发送路径设计（线性切帧 + 待发流索引）

头部编解码优化到 v5.2.7 已趋收敛，但真实服务的大头（几百 KB 的 JSON / 静态资源）在**响应体发送**侧。
`Http2Session::flushPending()` 负责把响应体按对端 `SETTINGS_MAX_FRAME_SIZE` 切成 DATA 帧，
并受连接级 + 流级双层发送窗口约束。这条路径上曾有两处复杂度陷阱，v5.2.8 一并消除：

**1. 逐帧重切 → 平方复杂度。** 早期写法每发一帧就 `$pending = substr($pending, $n)`，
等于**每帧都复制一整份剩余响应体**。1MB 响应按 16KB 切要发 64 帧，累计复制 ≈32MB，
耗时随响应体积平方增长。现改为在原串上推进整数游标 `$offset`：

```php
$pending = $this->streams[$id]['pending'];
$total   = strlen($pending);
$offset  = 0;

while ($offset < $total) {
    $allowed = min($this->sendWindow, $stream['sendWindow'], $this->peerMaxFrameSize, $total - $offset);
    if ($allowed <= 0) {
        break;              // 窗口耗尽，留待 WINDOW_UPDATE 后续发
    }
    $piece   = substr($pending, $offset, $allowed);   // 只复制真正要发的那一片
    $offset += $allowed;
    // ... 编码 DATA 帧
}

// 整轮只回写一次
if ($offset > 0) {
    $this->streams[$id]['pending'] = $offset >= $total ? '' : substr($pending, $offset);
}
```

**2. 全流扫描 → 随并发流数线性上涨。** `flushPending()` 由 `WINDOW_UPDATE` 高频触发，
而它原先遍历全部活跃流。128 条流时每次 `WINDOW_UPDATE` 要花 2.5µs，且绝大多数流无事可做。
现引入 `$pendingStreams`（`array<int, true>`）只索引「还有字节没发完」的流：

- **不变式**：`$id ∈ pendingStreams` ⟺ 该流 `pending !== ''` 或仍欠一个 `END_STREAM`。
- **写入点**只有 `respond()` / `writeData()`；**摘除点**只有 `flushPending()` 收尾与 `freeStream()`。
  RST_STREAM、GOAWAY、正常关闭等所有销毁路径都收敛于 `freeStream()`，索引不会泄漏。
- 空闲连接上 `flushPending()` 退化为一次空循环，耗时与活跃流数**无关**（恒 0.5µs）。

`stats()` 新增 `pending_streams` 字段暴露被发送窗口卡住的流数——持续不降即说明对端不回补窗口。

收益（详见 [压测数据](benchmark.md) §3.6 / §5.3）：微基准上 `respond(1MB)` **4.15×**、
`WINDOW_UPDATE @128 流` **5.4×**；真实 h2c 压测下 1MB 响应端到端吞吐 **2.73×**、
延迟中位数 **−67%**，256KB 响应 **1.33×**，两者内存峰值增长均为 0。

正确性保证与 HPACK 缓存同源——**线格式逐字节不变**：`benchmarks/h2-flush-equiv.php`
覆盖窗口耗尽续发、流式分片、多流交错、发送中途 RST、对端不同 `MAX_FRAME_SIZE` 等
247 组场景，新旧实现 drain 出的字节 SHA-256 全部相同；另有 5 个单测锁死索引不变式，
并经变异测试（人为破坏摘除 / 写入点）验证用例确实能捕获回归。

## 相关文档

- [协议系统](protocol.md)
- [请求对象](request.md)
- [响应与边界](response.md)
- [压测数据](benchmark.md)
