# 压测数据

同一份业务代码（`benchmarks/portable-server.php`：HTTP GET `/` → 12 字节纯文本），在 **native / swoole / workerman** 三种运行时与 **HTTP/1.1 / HTTP/2（h2c）** 两种协议下的实测吞吐与延迟对比。

> 所有数字均在 **最新版（v5.2.5）** 下测得，同一台机器、同一份服务、同一份载荷，唯一变量是运行时 / 协议。
> 复现方法见文末「怎么复现」。wrk 在 4 worker 下会逼近压测端天花板，横向 QPS 差异落在噪声区间；
> 可干净复现的优势只在「同进程背靠背纯 CPU 微基准」，文中已单独列出。

## 测试条件

| 项 | 值 |
|----|----|
| 版本 | v5.2.5（PHP 8.3.33，11 核） |
| 参数 | workers=4，duration=12s，connections=200，threads=4 |
| 载荷 | HTTP GET `/` → 12 字节纯文本 |

## 一、三运行时吞吐对比（HTTP/1.1，wrk）

同一条件连跑 **两轮**，两轮都如实列出——单轮结果会被压测端抖动主导，只报一轮容易得出错误结论：

| 运行时 | 第 1 轮 QPS | 第 2 轮 QPS | 第 1 轮 P99 | 第 2 轮 P99 |
|--------|-----------:|-----------:|-----------:|-----------:|
| **native**（自研，零扩展，自适应 reusePort） | 176,119 | **184,564** | 15.36 ms | **2.48 ms** |
| **swoole**（C 实现） | **178,562** | 176,273 | 7.13 ms | 6.15 ms |
| **workerman**（纯 PHP 依赖） | 171,577 | 173,713 | 6.84 ms | 7.42 ms |

- 三者始终处于**同一量级**（172k–185k），轮次间差异（native 176k↔185k）大于运行时之间的差异，
  说明宏观 QPS 已被压测端天花板与调度抖动主导，**不足以支撑任何一方"更快"的结论**。
- native 第 1 轮的 P99 15.36 ms 是离群噪声：第 2 轮同一条件下降到 **2.48 ms，为三者最低**。
  这正是"单轮数据不可信"的实证，也是这里坚持并列两轮的原因。
- 要看真实代码差距，请直接看第三节的**纯 CPU 微基准**——那里隔离了 socket 与事件循环，方向稳定可复现。

## 二、HTTP/2（h2c）vs HTTP/1.1（同一 native 服务、同时刻）

`benchmarks/h2-bench.sh` 对**同一份 native 服务、同一时刻**分别施加 h2load（h2c prior-knowledge）与 wrk（HTTP/1.1），唯一变量是协议版本。

| 协议 | 工具 | QPS | 请求 P50 | 请求 P99 | 说明 |
|------|------|----:|--------:|--------:|------|
| **HTTP/1.1** | wrk | **174,031** | 0.78 ms | 5.37 ms | keep-alive，4 worker |
| **HTTP/2 (h2c)** | h2load | **162,155** | 0.84 ms | **2.58 ms** | **100 万请求** / 200 连接 / 4 线程，**0 错误 0 超时** |

- 在「12 字节极小响应」这种端点上，HTTP/2 每请求要额外付成帧 + HPACK 编解码 + 流状态机开销，
  吞吐比 h1.1 **低约 6.8%**（162k vs 174k）——这是协议固有代价，与「自研慢」无关；h1.1 在轻载荷上本就更省。
  该差距在 v5.2.4（响应整块缓存）与 v5.2.5（Huffman 解码缓存）连续优化后，已从早期的约 13% **收窄到 6.8%**。
- 而 h2c 的**请求 P99 明显更低（2.58 ms vs 5.37 ms，低 52%）**，且支持多路复用：真实业务里「一个页面并发几十个
  请求」时，h2c 用一条 TCP 连接即可，省去 h1.1 的队头阻塞与连接数上限，优势才会真正显现。
- 100 万请求全部 2xx、**0 失败 0 超时**，说明流状态机与流控在长时间高压下稳定。
- **h2c 仅 native 提供**：本框架里 Workerman 的服务端是 HTTP/1.1-only，Swoole 的 HTTP/2 需要 TLS（ALPN），
  无法做 cleartext h2c 对比；native 默认即开 h2c（prior-knowledge / Upgrade 升级），是三者中唯一能在明文下直接跑 HTTP/2 的运行时。

## 三、纯 CPU 微基准（隔离 socket 与事件循环）

### 3.1 请求热路径（每请求必做的 PHP 工作量）

隔离网络层，只跑每请求必须做的 PHP 工作（input → decode → rawHeader ×2 → encode）。
native 与 workerman 在**同一进程内背靠背**比较，共独立运行 6 次：

| 运行时热路径（每 20 万次） | 典型耗时 | 相对 |
|---------------------------|---------:|-----:|
| **native** | **~78 ms** | — |
| workerman | ~90 ms | +14~17% |

**方向 100% 稳定**：6 次独立运行中 native **每一次都快于 workerman**（中位数约 -17%）。
跨进程绝对数字会波动，但同进程背靠背比较始终 native 占优，说明差距来自代码而非噪声。

### 3.2 HTTP/2 请求热路径（当前版本实测）

宏观 QPS 被压测端天花板掩盖，但 HTTP/2 每请求要做的 PHP 工作量是可以精确称量的。
`benchmarks/h2-split.php`（每 10 万次完整请求）与 `benchmarks/h2-components.php`
（每 50 万次纯函数调用）在 v5.2.5 上的实测：

| 阶段（每 10 万次请求） | 耗时 | 吞吐 |
|------------------------|-----:|-----:|
| **feed**（帧解码 + HPACK 解码 + 收齐头） | **333.78 ms** | 299,602 ops/s |
| **respond**（HPACK 编码 + 成帧） | **122.53 ms** | 816,104 ops/s |
| 合计（每请求必做的全部 PHP 工作） | **≈456 ms** | — |

| 纯函数组件（每 50 万次） | 耗时 | 吞吐 |
|--------------------------|-----:|-----:|
| `Frame::decode` | 95.78 ms | 5,220,435 ops/s |
| `Frame::encode` | 65.56 ms | 7,626,849 ops/s |
| `Hpack::decode`（请求头块） | 742.51 ms | 673,388 ops/s |
| `Hpack::encode`（响应头列表） | 557.12 ms | 897,473 ops/s |

### 3.3 三层纯函数缓存（提速来源）

上面的数字之所以能压到这个量级，靠的是三层**纯函数缓存**——它们缓存的都是
「输入唯一决定输出」的计算结果，**线格式与未缓存时逐字节一致**，不改变任何协议行为：

| 缓存 | 方向 | 依据 | 收益 |
|------|------|------|-----:|
| `Hpack::$literalCache` | 明文 → 已编码字节 | 字面量编码只依赖该字符串本身 | 编码热路径 **≈5.7×** |
| `Http2Session::$responseBlockCache` | `(status, headers)` → 整个头块 | 服务端编码器恒走「不索引」表示、**动态表恒空**，整块编码即纯函数 | respond **≈2.2×** |
| `Hpack::$huffmanCache` | 已编码字节 → 明文 | Huffman 解码只依赖输入字节，与动态表无关 | `Hpack::decode` **≈1.93×** |

三者形成闭环：响应侧（前两层）与请求侧（第三层）**都已被覆盖**，请求热路径累计快约 **1.7×**
（≈783 ms → ≈456 ms / 10 万次），端到端 h2c 与 h1.1 的吞吐差距也随之从约 13% 收窄到 **6.8%**。

安全性上三层缓存均有边界：条目数上限（1024 / 256 / 1024）防止无限增长；Huffman 缓存
额外限制单条 ≤ 512 字节，且**只缓存解码成功的结果**——非法输入抛异常且不入缓存，
攻击者无法用畸形数据占位。缓存均可用 `clearLiteralCache()` / `clearResponseBlockCache()` /
`clearHuffmanCache()` 清空，便于测试隔离与基准冷启动。

## 四、安全：HTTP/2 DoS 防护的性能代价

提速不能以牺牲安全为代价。native 的 HTTP/2 内建四道防线，且**全部为 O(1) 计数**，
不引入额外遍历或分配，对上面的热路径数字无可测量影响：

| 防线 | 覆盖攻击面 | 触发动作 | 开销 |
|------|-----------|---------|------|
| `MAX_HEADER_BLOCK_SIZE`（64 KiB） | CONTINUATION 分片堆积 | `RST_STREAM` 该流 | 累加比较 |
| `MAX_HEADER_LIST_SIZE`（64 KiB） | 高压缩比头膨胀 | `RST_STREAM` 该流 | 解码循环内边解边累加 |
| RST_STREAM 预算（默认上限 512） | **Rapid Reset / CVE-2023-44487** | `GOAWAY(ENHANCE_YOUR_CALM)` | 自增 / 自减 |
| 控制帧排队上限（1000） | PING / SETTINGS 洪泛 | `GOAWAY(ENHANCE_YOUR_CALM)` | 自增，`drain()` 归零 |

Rapid Reset 防护采用「**预算 + 抵扣**」：收到对端 `RST_STREAM` 预算 +1，每有一条流
**正常完成响应**预算 −1。正常客户端的完成数远多于取消数，预算稳定在 0 附近，不会误伤；
而只重置不完成的攻击流量预算只增不减，很快触顶被掐断。该设计不依赖时钟，行为确定可测试。

四道防线各有回归用例守护，`stats()` 亦暴露 `reset_budget` / `reset_limit` / `queued_control`
三个实时水位便于接入监控。详见 [HTTP/2 文档](http2.md)。

## 优劣势对比

| 维度 | native（自研） | swoole | workerman |
|------|----------------|--------|-----------|
| 扩展依赖 | 零（仅 CLI 自带 pcntl/posix，可选 ext-event 提速） | 需 C 扩展 | 纯 PHP（事件循环可选扩展） |
| HTTP/2（h2c 明文） | ✅ 默认开 | ❌ 需 TLS | ❌ 仅 1.1 |
| 优雅关闭 GOAWAY | ✅ | ⚠️ | ⚠️ |
| 请求热路径 CPU | ✅ 比 workerman 快 ~17%（微基准 6/6 全胜） | C 引擎 | 持平 / 略慢 |
| 轻载荷吞吐（wrk） | 与 workerman 持平（±2% 噪声） | 略高（C 引擎） | 持平 |
| HPACK 编/解码 | 编码 5.7×（字面量缓存）+ 响应整块缓存 2.2×（v5.2.4）+ 解码 1.93×（v5.2.5 Huffman 缓存） | C 引擎 | PHP 实现 |
| HTTP/2 DoS 防护 | ✅ Rapid Reset(CVE-2023-44487) / CONTINUATION 洪泛 / 控制帧洪泛 / 头列表体积，四层内建 | 依赖扩展版本 | 不适用（无 HTTP/2） |
| 调试 / 可读性 | 高（纯 PHP） | 中（C 扩展黑盒、协程栈） | 高 |
| 协程 / 异步 IO | ❌（用 Fibers / 多进程） | ✅ | ⚠️（事件回调） |
| 分布式集群 / 共享表 | ✅ 内建 | 复用宿主 | 复用宿主 |

## 使用方向（选型建议）

1. **受限 / 容器 / 无扩展环境**：不装 swoole/workerman 扩展即可跑起完整 HTTP + WebSocket + HTTP/2 服务——选 **native**。
2. **现代 PHP 8.3+ 项目**：枚举、只读属性、Fibers 等，代码即服务器，调试直观——选 **native**。
3. **需要 HTTP/2（h2c）**：gRPC 网关、前端静态资源、内部多路复用 RPC——**只有 native 默认即开**，明文即可。
4. **极致单连接吞吐 / 协程**：高并发 I/O 密集且能装 C 扩展——选 **swoole**。
5. **已深度使用 Workerman 生态**：业务已绑定 Workerman 组件——选 **workerman**（native 可零改动切换做对标）。
6. **自建 PaaS / 嵌入式 / 教学**：一份纯 PHP 源码，改得动、读得懂——选 **native**。

> 一句话：自研不强在「压榨极限吞吐」（那是 C 扩展的领地），而强在「零安装即得、PHP 8.3+ 现代写法、
> HTTP/2 开箱即用且自带 DoS 防护、协议与关闭更规范、代码可读可测」。在绝大多数业务服务的吞吐区间内，
> 它与 swoole / workerman **同量级**，并在请求热路径 CPU 上稳定领先（微基准 6/6 全胜）；
> HTTP/2 请求热路径经 v5.2.4（响应整块缓存 ≈2.2×）与 v5.2.5（Huffman 解码缓存 ≈1.93×）两轮优化，
> 累计再快约 **1.7×**，h2c 与 h1.1 的吞吐差距同步从约 13% 收窄到 **6.8%**。

## 怎么复现

```bash
# 需要 wrk 与 h2load：brew install wrk nghttp2

# 三运行时吞吐对比（HTTP/1.1，wrk）
bash benchmarks/runtime-bench.sh 4 12 200 4

# HTTP/2 同等条件吞吐：同一 native 服务、同时刻 h2c vs h1.1
bash benchmarks/h2-bench.sh 4 19301

# 纯 CPU 热路径微基准（无需 wrk）
php benchmarks/hotpath-micro.php

# HTTP/2 响应热路径微基准（HPACK 编码 + feed/respond）
php benchmarks/hotpath-h2.php

# HTTP/2 解码热点拆分（Huffman 占比定位）
php benchmarks/h2-decode-bench.php

# feed/respond 拆分（解码 vs 编码耗时占比）
php benchmarks/h2-split.php

# 纯函数组件耗时拆解（Frame 编解码 / HPACK 编解码各自占比）
php benchmarks/h2-components.php
```

- `benchmarks/portable-server.php`：跨运行时通用的服务脚本（`Kode::serve(..., 'native'|'swoole'|'workerman')`）。
  不显式传 `reusePort` 时走运行时的平台自适应默认。可用 `REUSE_PORT=0|1` 环境变量覆盖做 A/B。
- 运行期产物（`bench-result.txt`、`compare-result.txt`、`workerman.log` 等）已加入 `.gitignore`，不入库。
