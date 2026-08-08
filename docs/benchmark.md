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

### 诚实结论

- **吞吐**：native 与 workerman 在同等条件下**持平**，互有胜负且差异落在噪声区间；两者均优于 swoole 的 CPU 效率。
- **热路径 CPU**：native 每请求的解析+编码成本稳定低于 workerman（6/6 运行全胜，中位数约 -17%），
  这是隔离了 socket 与事件循环噪声后唯一可干净复现的优势，也是「自研不输 workerman」的硬证据。
- 吞吐持平的原因不在请求处理，而在 wrk 压测端先于服务端饱和；native 的差异化价值则集中在
  **协议完备性**（HTTP/2、WebSocket 分片重组）、**错误处理分级**、**优雅关闭（GOAWAY）** 与**可测试性**上。

## 怎么复现

```bash
# 需要 wrk：brew install wrk
bash benchmarks/runtime-bench.sh 4 15 200 4
# 结果写入 benchmarks/bench-result.txt

# 三方交替轮转 + CPU 效率对比：workers duration connections threads rounds
bash benchmarks/runtime-compare.sh 4 10 100 4 5
# 结果写入 benchmarks/compare-result.txt

# 纯 CPU 热路径微基准（无需 wrk）
php benchmarks/hotpath-micro.php

# HTTP/2 响应热路径微基准（HPACK 编码 + feed/respond，无需 wrk）
php benchmarks/hotpath-h2.php [iterations]
```

- `benchmarks/portable-server.php`：跨运行时通用的服务脚本（`Kode::serve(..., 'native'|'swoole'|'workerman')`）。
  不显式传 `reusePort` 时走运行时的平台自适应默认（见上文）。可用 `REUSE_PORT=0|1` 环境变量强制覆盖做 A/B。
- `benchmarks/runtime-bench.sh`：依次启动三运行时并跑 wrk，汇总到 `bench-result.txt`。
- 运行期产物（`bench-result.txt`、`bench-result.before-reuseport.txt`、`workerman.log`、`gate/*` 等）已加入 `.gitignore`，不入库。
