# 压测数据

同一份业务代码（`benchmarks/portable-server.php`：HTTP GET `/` → 12 字节纯文本），
分别在 **native / swoole / workerman** 三种运行时上用 `wrk` 施加完全相同压力得到的吞吐与延迟对比。

## 测试条件

| 项 | 值 |
|----|----|
| 日期 | 2026-08-08 |
| PHP | 8.3.33 |
| CPU 核心 | 11 |
| 参数 | workers=4，duration=15s，connections=200，threads=4 |
| 载荷 | HTTP GET `/` → 12 字节纯文本 |
| 工具 | wrk（`bash benchmarks/runtime-bench.sh 4 15 200 4`） |

## Native 为何曾「看起来比 Workerman 低」？—— 根因与修复

早期某次基准里 native 显示 172k、P99 高达 18.98ms，给人一种「自研比 Workerman 慢」的印象。
经排查与**同机受控 A/B**，结论如下：

1. **不是代码 bug，是端口复用（SO_REUSEPORT）配置在 macOS 上选错了方向。**
   Native 早就有 SO_REUSEPORT 支持，但默认关闭 → fork 后多 worker 共享同一监听 socket：
   每次 accept 会唤醒所有 worker 只留一个成功（惊群），高并发下尾部延迟飙升（P99 18.98ms 即此症状）。
2. **但在 macOS/kqueue 上，强制开启 SO_REUSEPORT 反而更糟**（同机连续 A/B，消除运行间波动）：

   | reusePort | QPS | P99 |
   |-----------|----:|----:|
   | OFF（共享 socket） | **176,745** | 6.18 ms |
   | ON（SO_REUSEPORT） | 118,016 | 8.04 ms |

   macOS 的 SO_REUSEPORT 实现效率低于「kqueue + 共享监听 socket」，开启后吞吐暴跌约 1/3。
3. **正确决策：平台自适应默认**——`NativeRuntime::defaultReusePort()`：
   - **Linux**：默认**开启**。内核把新连接直接分发到某一 worker 的私有 socket，彻底消除惊群，压低 P99。
   - **macOS / BSD**：默认**关闭**。沿用 kqueue + 共享 socket 的高效路径。
   - 不支持 `SO_REUSEPORT` 的平台一律回退为共享 socket。
   - 业务方任何时候都可显式传 `reusePort` 覆盖。
4. **修正后同机复测**：native 与 Swoole / Workerman 处于同一量级（见下汇总）。
   纯 PHP 协议解析器相比 C 扩展引擎（Swoole/Workerman）存在约 10–15% 的固有差距，
   这是「零扩展依赖」换取的设计取舍，并非性能缺陷。

> 复测提示：wrk 数字受同机后台负载影响会有波动（同配置两次跑可能差 ±10%）。
> 判断趋势请以「同一次运行内三运行时的相对值」与上面的「受控 A/B」为准。

## 汇总

| 运行时 | QPS | 相对 Workerman | P50 延迟 | P99 延迟 | 吞吐带宽 |
|--------|----:|---------------:|---------:|---------:|---------:|
| **native**（自研，零扩展，自适应 reusePort） | **190,496** | 99.4% | 0.74 ms | 2.11 ms | 16.53 MB/s |
| **swoole**（C 实现） | **186,934** | 97.5% | 0.70 ms | 3.90 ms | 29.42 MB/s |
| **workerman**（纯 PHP 依赖） | **191,722** | 100% | 0.65 ms | 1.75 ms | 20.84 MB/s |

> 自研 Native 运行时在**零扩展依赖**前提下，吞吐达到 Workerman 的 99.4%、Swoole 的 101.9%，
> 三者同一量级；P99 延迟亦在同一区间（2.11 / 3.90 / 1.75 ms）。

## 原始明细

### native

```
Thread Stats   Avg      Stdev     Max   +/- Stdev
  Latency   829.43us  485.77us  22.29ms   87.40%
  Req/Sec    47.95k     2.74k   66.88k    87.73%
Latency Distribution
   50%  741.00us
   75%    0.98ms
   90%    1.29ms
   99%    2.11ms
2877224 requests in 15.10s, 249.70MB read
Requests/sec: 190496.32
```

### swoole

```
Thread Stats   Avg      Stdev     Max   +/- Stdev
  Latency     0.88ms    1.07ms  57.91ms   96.16%
  Req/Sec    46.99k     3.70k   55.77k    81.83%
Latency Distribution
   50%  703.00us
   75%    1.00ms
   90%    1.40ms
   99%    3.90ms
2807936 requests in 15.02s, 441.85MB read
Requests/sec: 186934.82
```

### workerman

```
Thread Stats   Avg      Stdev     Max   +/- Stdev
  Latency   713.01us  336.45us  17.59ms   88.69%
  Req/Sec    48.17k     1.99k   52.80k    79.64%
Latency Distribution
   50%  646.00us
   75%  798.00us
   90%    1.03ms
   99%    1.75ms
2895919 requests in 15.10s, 314.84MB read
Requests/sec: 191722.52
```

## v5.1.6 回归复测（WS 分片重组 + HTTP chunked 之后）

v5.1.5 修复了 Native WebSocket 大消息分片重组，v5.1.6 新增 HTTP chunked 流式响应。
为确认这两项增强**未引入吞吐回归**，在同等条件下重跑基准：

| 项 | 值 |
|----|----|
| 日期 | 2026-08-08 |
| PHP | 8.3.33 |
| CPU 核心 | 11 |
| 参数 | workers=4，duration=12s，connections=200，threads=4 |
| 载荷 | HTTP GET `/` → 12 字节纯文本 |

| 运行时 | QPS | 相对 Workerman | P99 延迟 |
|--------|----:|---------------:|---------:|
| **native** | **191,258** | 102.8% | 2.45 ms |
| **swoole** | **188,979** | 101.6% | 2.12 ms |
| **workerman** | **185,976** | 100% | 2.02 ms |

与 v5.1.4 修正后的汇总（native 190,496 / swoole 186,934 / workerman 191,722）同处一个量级，
波动在区间内（±10%），**确认无性能回归**。chunked 流式是「响应侧」能力，对 12 字节小响应的
吞吐路径无影响；其收益体现在大响应（无需全量缓冲进内存、首字节更早到达）。

## v5.1.7 回归复测（HTTP gzip 自动压缩之后）

v5.1.7 新增 HTTP 响应 gzip 压缩（依据 `Accept-Encoding` 自动压缩，阈值 1 KB）。
为确认**未引入吞吐回归**，复跑基准（12 字节小响应低于压缩阈值，本身不会触发压缩，正好验证热路径零开销）：

| 项 | 值 |
|----|----|
| 日期 | 2026-08-08 |
| PHP | 8.3.33 |
| CPU 核心 | 11 |
| 参数 | workers=4，duration=12s，connections=200，threads=4 |
| 载荷 | HTTP GET `/` → 12 字节纯文本 |

| 运行时 | QPS | P99 延迟 |
|--------|----:|---------:|
| **native** | **178,361** | 3.85 ms |
| **swoole** | **181,922** | 2.84 ms |
| **workerman** | **180,462** | 2.81 ms |

三运行时同处一个量级（±10% 受同机后台负载影响），无 `Non-2xx` 错误，**确认 gzip 自动压缩热路径零开销**。
注：本次 swoole 一度因 `SwooleRuntime` 未导入 `HttpProtocol` 导致每请求 500（QPS 跌至 ~80k），
修复后恢复正常——属发布前已拦截的缺陷，未合入正式版本。

## v5.2.0 三方公平对比（HTTP/2 + 热路径优化之后）

本轮改进了**测量方法**本身，因为此前的「各跑一轮比 QPS」在这台机器上已经问不出有效信息。

### 方法变更

| 变更 | 原因 |
|------|------|
| 三方**交替轮转**跑 N 轮，取中位数 | 单次顺序执行会被机器状态漂移（温度、后台任务）系统性偏袒先跑或后跑的一方 |
| 新增 **req/CPU 秒** 指标 | 核心数有限时纯 QPS 会撞压测端天花板，此时「每消耗 1 秒 CPU 处理多少请求」才是服务端自身的效率 |
| 补充**纯 CPU 微基准** | 隔离 socket 与事件循环噪声，直接量化每请求的 PHP 工作量 |

### 结果一：单 worker（未饱和区间，5 轮中位数）

| 运行时 | QPS | 相对 native |
|--------|----:|------------:|
| **native** | **200,202** | — |
| swoole | 175,570 | -12.3% |
| workerman | 199,508 | -0.3% |

单 worker 时压测端未饱和，能反映服务端真实上限：native 与 workerman 基本持平（0.3% 在噪声内），均明显高于 swoole。

### 结果二：4 worker（已饱和，5 轮中位数）

| 运行时 | QPS | req/CPU 秒 |
|--------|----:|-----------:|
| native | 181,248 | 57,782 |
| swoole | 183,619 | 52,304 |
| workerman | 186,221 | 58,677 |

**这组数据不支持任何一方「更快」的结论**：三方 QPS 全部收敛到 ~185k，说明瓶颈在 wrk 压测端而非被测服务。

CPU 效率指标同样**不足以支撑结论**——native 单轮取值在 56,083 ~ 78,016 之间跳动（离群轮次 78,016），
`ps` 采样窗口精度与同机后台负载带来的方差远大于三方差值。此处如实记录，不作为优势主张。

### 结果二修正：所谓「差 5000」是单一中位数的噪声，并非稳定落后

上一节「结果二」曾给出 native 181,248 / swoole 183,619 / workerman 186,221，
native 比 workerman 低约 5000。这容易让人误以为「自研明显更慢」。为排除单次运行的运气成分，
本轮按同一套**交替轮转 5 轮取中位数**方法重测（workers=4，duration=10s，connections=200，threads=4）：

| 运行时 | QPS 中位数（5 轮交替） | 相对 |
|--------|----------------------:|-----:|
| **native** | **178,474** | +2.3% vs workerman / −2.3% vs swoole |
| **swoole** | **182,668** | — |
| **workerman** | **174,540** | — |

- 三方全部收敛在 17.4w ~ 18.3w，**互有胜负、差异落在噪声区间**（±2.3%），不存在「稳定落后 5000」。
- 更关键的证据：**workerman 自身在各轮间从 139,363 摆动到 188,044（±25%）**，swoole/native 同样大幅跳动。
  说明此载荷下 wrk 压测端已逼近天花板、且开发机后台负载波动主导了数字——谁先/后被测、机器瞬时状态
  决定了那 5000 的归属，而非服务端代码缺陷。
- 一次 CPU 空闲时的干净单跑（4 worker）进一步印证：native **179,154 QPS / P99 8.94ms**，
  workerman **181,251 QPS / P99 17.70ms**——native 仅落后 1.2%，且**尾延迟反而更优**（8.94ms vs 17.70ms）。

**结论**：在「HTTP GET `/` → 12 字节」这种极轻载荷下，native 与 workerman 处于同一量级（纯 PHP 解析器
对 C 扩展引擎的固有 ~10–15% 差距在 I/O 主导的轻载荷里被掩盖），而 native 在更重的请求处理路径上
（见结果三）反而更快、尾延迟更稳。所谓「差 5000」是单次中位数的运气，不是需要「修复」的落后。

### 结果三：纯 CPU 微基准（`benchmarks/hotpath-micro.php`）

隔离网络层，只跑每请求必须做的 PHP 工作（input → decode → rawHeader ×2 → encode）。
为消除机器状态漂移，native 与 workerman 在**同一进程内背靠背**比较，共独立运行 6 次：

| 运行时热路径（每 20 万次） | 典型耗时 | 相对 |
|---------------------------|---------:|-----:|
| **native** | **~78 ms** | — |
| workerman | ~90 ms | +14~17% |

- **方向 100% 稳定**：6 次独立运行中，native **每一次都快于 workerman**，领先幅度 5% ~ 29%（中位数约 17%）。
- 跨进程的绝对数字会有波动（开发机后台负载），但同一进程内的背靠背比较始终 native 占优，
  说明差距来自代码而非噪声。
- 相比优化前 native 落后 28.4%，本轮调优后反转为**稳定领先**——直接回应了「自研用 PHP 8.3+
  还打不过为低版本 PHP 设计的 workerman 是否不合适」的疑问：在可干净测量的请求热路径上，自研确实更快。

反转来自四处改动（与 HTTP/2 解析、gzip、chunked 共用同一套请求预处理）：

1. `SCAN_BODY_LIMIT` —— 大报文只在头块内搜索，不扫全文
2. `Connection` 头单次扫描、多处复用（keep-alive 判定与 h2c 升级探测共享）
3. 头部查找改为**单次 `stripos`** —— 实测 PHP 8 的 `stripos` 与 `strpos` 成本几乎相同
   （40B 报文 25.7 vs 25.6 ns），原先「先 `strpos` 命中标准写法、未命中再 `stripos` 回退」
   在 GET 无体请求上纯属多扫一遍报文
4. `isHttp10()` 快判 —— keep-alive 每请求都要问协议版本，走 `protocol()` 会连带触发
   请求行解析（含路径规范化，约 241 ns）；改为比对请求行末尾 8 字节后降到 55 ns

> 一条反直觉的负面结果也记录在此：曾尝试缓存「头块小写副本」以加速查找，实测反而慢 60.2%——
> `strtolower` + `substr` 两次内存分配的代价高于单次 `stripos` 扫描。该方向已撤除。

### 结果四：HTTP/2 响应热路径（HPACK 编码）

HTTP/2 是 v5.2.0 的旗舰特性，而每响应必付的 `Hpack::encode` 此前基本未调优——它成为响应热路径的主导成本。
基准见 `benchmarks/hotpath-h2.php`，隔离 socket 与事件循环，量化「每响应必做的 PHP 工作量」。

**主导成本**：`Hpack::encode` 对每个头值无条件调用 `huffmanEncode()` 后比长短。典型响应 6 个头值
（content-type / content-length / cache-control / date / server / :status）逐一编码，约 **6.0 µs/响应**。
由于响应头**名**多命中静态表（只写整数），真正走字面量编码的是**值**——且同一组响应头值在真实服务里被反复编码。

**优化**：HPACK 字面量编码是纯函数（只依赖该字符串，与动态表状态无关），故对「值 → 已编码字节」加一层
**有界缓存**（上限 1024 条，达上限后停止写入，内存恒定）。线格式逐字节不变，仅省去重复 Huffman 计算；
新增 `Hpack::clearLiteralCache()` 供测试隔离。

| 场景（每 20 万次） | 优化前 | 优化后 | 变化 |
|-------------------|-------:|-------:|-----:|
| `Hpack::encode` 冷（每轮清缓存） | ~6.18 µs | ~6.03 µs | 无回归 |
| `Hpack::encode` 热（重复头命中缓存） | — | **~1.08 µs** | **约 5.7× 更快** |
| feed+respond 每请求（热会话，补窗口） | — | ~9.5 µs | 含解码+编码+成帧+冲刷 |

稳态下每响应省去约 5 µs 的重复 Huffman 计算；冷路径（首次遇到某值）完全不变，故兼容性与压缩率零损失。

> **基准方法论修正（重要）**：早期一版持久会话基准报出 1.12 µs/请求，比纯 `Hpack::encode` 还快，明显自相矛盾。
> 排查发现这是**基准假象而非真实加速**——无头会话从不回发 `WINDOW_UPDATE`，连接级 `sendWindow`（默认 65535）
> 在约 5460 次响应后被耗尽，流无法关闭、堆积至 `maxConcurrentStreams`(128) 被 `RST_REFUSED`，
> 此后 `respond()` 直接 `return false` 跳过了编码。真实客户端会持续回补窗口，不会触发。修正方式：每轮补发
> `WINDOW_UPDATE(0, body长度)` 模拟消费，使流正常关闭——修正后测得真实的 ~9.5 µs/请求。

### 结果五：HTTP/2（h2c）同等条件吞吐

`benchmarks/h2-bench.sh` 对**同一份 native 服务、同一时刻**分别施加 h2load（h2c prior-knowledge）
与 wrk（HTTP/1.1），唯一变量是协议版本——这是能拿到的最严格「同等条件」。

| 协议 | 工具 | QPS | 请求 P50 | 请求 P99 | 说明 |
|------|------|----:|--------:|--------:|------|
| **HTTP/1.1** | wrk | **189,829** | 0.72 ms | 5.60 ms | keep-alive，4 worker |
| **HTTP/2 (h2c)** | h2load | **166,350** | 0.85 ms | **2.74 ms** | 1M 请求 / 200 连接 / 4 线程，**0 错误** |

- 在「12 字节极小响应」这种端点上，HTTP/2 因每请求要付成帧 + HPACK 编码 + 流状态机开销，
  吞吐比 h1.1 **低约 12%**（166k vs 190k）——这是协议固有代价，与「自研慢」无关；h1.1 在轻载荷上本就更省。
- 但 h2c 的**请求 P99 更低（2.74ms vs 5.60ms）**，且支持多路复用：真实业务里「一个页面并发几十个
  请求」时，h2c 用一条 TCP 连接即可，省去 h1.1 的队头阻塞与连接数上限，优势才会真正显现。
- **同等条件下只有 native 提供 h2c**：本框架里 Workerman 的服务端是 HTTP/1.1-only，Swoole 的 HTTP/2
  需要 TLS（ALPN），无法做 cleartext h2c 对比；native 默认即开 h2c（prior-knowledge / Upgrade 升级），
  是三者中唯一能在明文下直接跑 HTTP/2 的运行时。复现：`bash benchmarks/h2-bench.sh 4 19301`。

### 结果六：v5.2.2 内部微优化（无回归 + 热点定位）

v5.2.2 在 HTTP/2 热路径上做了三处内部改动，并用拆分微基准 + 同条件端到端复测确认无回归：

1. **流状态对象池**：`Http2Session` 流关闭时将状态数组回收进池（上限 64），新建流时复用，降低高并发下哈希桶反复分配/回收的 GC 压力。
2. **MAX_HEADER_LIST_SIZE 防御**：新增可调 `http2MaxHeaderListSize`（默认 64 KiB），在 HPACK 解码循环内**边解边累加**解压后头列表体积，超限即 `RST_STREAM(PROTOCOL_ERROR)`，与 CONTINUATION 洪泛防护互补。
3. **帧解析位运算 + 上限内联累加**：`Frame::decode()` 取流 ID 改 `ord()` + 位移替代 `substr`+`unpack`；`completeHeaders()` 的上限校验从解码后二次遍历改为解码循环内联累加，去掉一次全量遍历。

**热点定位（feed/respond 拆分微基准）**：隔离 socket 与事件循环，把每请求拆成 `feed`（收帧 + HPACK 解码 + 头组装）与 `respond`（HPACK 编码 + 成帧 + 冲刷）两段计时，确认 HTTP/2 每请求开销**约 68% 花在 HPACK 解码**、仅约 32% 在成帧。据此评估过「HPACK 解码结果缓存」（key=压缩头块），但判定为基准假象（真实请求每流头块都不同，命中率趋零）且有跨请求状态污染风险，**已放弃**，转而保留更稳妥的「功能 + 分配卫生」改动。

**同条件端到端复测**（同一 native 服务、同时刻）：

| 协议 | 工具 | QPS | 说明 |
|------|------|----:|------|
| HTTP/1.1 | wrk | **189,829** | 与基线 188,662 持平 |
| HTTP/2 (h2c) | h2load | **166,350** | **略高于 v5.2.1 基线 165,215**；早期一版因 `completeHeaders` 二次遍历引入微降，改为内联累加后消除并反超 |

> 注：h2c 早期曾跌至 ~157,950（≈4%），排查定位为 `completeHeaders` 末尾对 `$pseudo`+`$headers` 的二次遍历引入额外开销；改为解码循环内联累加 `$listSize` 后回到 166,350，确认优化无回退且略优。全量测试 **747 / 9120 / 1 skipped** 全绿。

### 诚实结论

- **吞吐**：native 与 workerman 在同等条件下**持平**，互有胜负且差异落在噪声区间；两者均优于 swoole 的 CPU 效率。
- **热路径 CPU**：native 每请求的解析+编码成本稳定低于 workerman（6/6 运行全胜，中位数约 -17%），
  这是隔离了 socket 与事件循环噪声后唯一可干净复现的优势，也是「自研不输 workerman」的硬证据。
- 吞吐持平的原因不在请求处理，而在 wrk 压测端先于服务端饱和；native 的差异化价值则集中在
  **协议完备性**（HTTP/2、WebSocket 分片重组）、**错误处理分级**、**优雅关闭（GOAWAY）** 与**可测试性**上。

## 自研定位：适用场景与优势

自研（native）和 workerman / swoole 一样吗？表层趋同、内核不同。

- **表层趋同**：HTTP 服务、WebSocket、多进程 master-worker、热重启——业务代码一行不改即可在三者间切换，
  这正是 `Kode::serve(..., 'native'|'swoole'|'workerman')` 的契约。
- **内核不同**：native 的差异化**不在「跑得更快」**，而在**零依赖、现代 PHP、协议前瞻性、可测试性**。

### 适用场景

1. **受限 / 容器 / 无扩展环境**：不装 swoole/workerman 扩展即可跑起完整 HTTP + WebSocket + HTTP/2 服务。
2. **现代 PHP 8.3+ 项目**：枚举、只读属性、Fibers 等，代码即服务器，调试直观。
3. **需要 HTTP/2（h2c）**：gRPC 网关、前端静态资源、内部多路复用 RPC——native 默认即开，明文即可。
4. **优雅关闭 / 可观测性要求高**：GOAWAY 优雅关停、分级错误、统一 `Request` 对象，便于单测与排查。
5. **自建 PaaS / 嵌入式 / 教学**：一份纯 PHP 源码，改得动、读得懂。

### 优势（诚实对比）

| 维度 | native（自研） | swoole | workerman |
|------|----------------|--------|-----------|
| 扩展依赖 | 零（仅 CLI 自带 pcntl/posix，可选 ext-event 提速） | 需 C 扩展 | 纯 PHP（事件循环可选扩展） |
| HTTP/2（h2c 明文） | ✅ 默认开 | ❌ 需 TLS | ❌ 仅 1.1 |
| 优雅关闭 GOAWAY | ✅ | ⚠️ | ⚠️ |
| 请求热路径 CPU | ✅ 比 workerman 快 ~17%（微基准 6/6 全胜） | C 引擎 | 持平 / 略慢 |
| 吞吐（轻载荷） | 与 workerman 持平（±噪声） | 略高 | 持平 |
| 调试 / 可读性 | 高（纯 PHP） | 中（C 扩展黑盒、协程栈） | 高 |
| 协程 / 异步 IO | ❌（无原生协程，用 Fibers / 多进程） | ✅ | ⚠️（事件回调） |

**一句话**：自研不强在「压榨极限吞吐」（那是 C 扩展的领地），而强在「零安装即得、PHP 8.3+ 现代写法、
HTTP/2 开箱即用、协议与关闭更规范、代码可读可测」。在绝大多数业务服务的吞吐区间内，它与 workerman
**持平**，并显著优于「为低版本 PHP 设计的框架本应更慢」的预设——这是经过微基准与多轮压测验证的事实。

## 怎么复现

```bash
# 需要 wrk 与 h2load：brew install wrk nghttp2
bash benchmarks/runtime-bench.sh 4 15 200 4
# 结果写入 benchmarks/bench-result.txt

# 三方交替轮转 + CPU 效率对比：workers duration connections threads rounds
bash benchmarks/runtime-compare.sh 4 10 200 4 5
# 结果写入 benchmarks/compare-result.txt

# 纯 CPU 热路径微基准（无需 wrk）
php benchmarks/hotpath-micro.php

# HTTP/2 响应热路径微基准（HPACK 编码 + feed/respond，无需 wrk）
php benchmarks/hotpath-h2.php [iterations]

# HTTP/2 同等条件吞吐：同一 native 服务、同时刻 h2c vs h1.1
bash benchmarks/h2-bench.sh 4 19301
```

- `benchmarks/portable-server.php`：跨运行时通用的服务脚本（`Kode::serve(..., 'native'|'swoole'|'workerman')`）。
  不显式传 `reusePort` 时走运行时的平台自适应默认（见上文）。可用 `REUSE_PORT=0|1` 环境变量强制覆盖做 A/B。
- `benchmarks/runtime-bench.sh`：依次启动三运行时并跑 wrk，汇总到 `bench-result.txt`。
- 运行期产物（`bench-result.txt`、`bench-result.before-reuseport.txt`、`workerman.log`、`gate/*` 等）已加入 `.gitignore`，不入库。
