# 压测数据

同一份业务代码（`benchmarks/portable-server.php`：HTTP GET `/` → 12 字节纯文本），在 **native / swoole / workerman** 三种运行时与 **HTTP/1.1 / HTTP/2（h2c）** 两种协议下的实测吞吐与延迟对比。

> 所有数字均在 **最新版（v5.2.4）** 下测得，同一台机器、同一份服务、同一份载荷，唯一变量是运行时 / 协议。
> 复现方法见文末「怎么复现」。wrk 在 4 worker 下会逼近压测端天花板，横向 QPS 差异落在噪声区间；
> 可干净复现的优势只在「同进程背靠背纯 CPU 微基准」，文中已单独列出。

## 测试条件

| 项 | 值 |
|----|----|
| 版本 | v5.2.4（PHP 8.3.33，11 核） |
| 参数 | workers=4，duration=12s，connections=200，threads=4 |
| 载荷 | HTTP GET `/` → 12 字节纯文本 |

## 一、三运行时吞吐对比（HTTP/1.1，wrk）

| 运行时 | QPS | 相对 native | P99 延迟 |
|--------|----:|------------:|---------:|
| **native**（自研，零扩展，自适应 reusePort） | **185,945** | 100% | 4.04 ms |
| **swoole**（C 实现） | **190,004** | 102.2% | 2.60 ms |
| **workerman**（纯 PHP 依赖） | **187,994** | 101.1% | 2.70 ms |

三者处于同一量级（±2% 噪声），不存在「自研落后」的结论。Swoole 的 P99 略低源于 C 引擎的事件循环实现。

## 二、HTTP/2（h2c）vs HTTP/1.1（同一 native 服务、同时刻）

`benchmarks/h2-bench.sh` 对**同一份 native 服务、同一时刻**分别施加 h2load（h2c prior-knowledge）与 wrk（HTTP/1.1），唯一变量是协议版本。

| 协议 | 工具 | QPS | 请求 P50 | 请求 P99 | 说明 |
|------|------|----:|--------:|--------:|------|
| **HTTP/1.1** | wrk | **190,162** | 0.67 ms | 2.40 ms | keep-alive，4 worker |
| **HTTP/2 (h2c)** | h2load | **165,760** | 0.79 ms | **2.32 ms** | 500k 请求 / 200 连接 / 4 线程，**0 错误** |

- 在「12 字节极小响应」这种端点上，HTTP/2 因每请求要付成帧 + HPACK 编解码 + 流状态机开销，
  吞吐比 h1.1 **低约 13%**（166k vs 190k）——这是协议固有代价，与「自研慢」无关；h1.1 在轻载荷上本就更省。
- 但 h2c 的**请求 P99 更低（2.32ms vs 2.40ms）**，且支持多路复用：真实业务里「一个页面并发几十个
  请求」时，h2c 用一条 TCP 连接即可，省去 h1.1 的队头阻塞与连接数上限，优势才会真正显现。
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

### 3.2 HTTP/2 HPACK 编码（响应热路径）

响应头**名**多命中静态表（只写整数），仅头**值**走字面量编码，故 `Hpack::encode` 的成本集中在值编码。
`Hpack::$literalCache` 缓存「值 → 已编码字节」（上限 1024，线格式不变）：

| 场景（每 20 万次） | 优化前 | 优化后 | 变化 |
|-------------------|-------:|-------:|-----:|
| `Hpack::encode` 冷（每轮清缓存） | ~6.03 µs | ~6.03 µs | 无回归 |
| `Hpack::encode` 热（重复头命中缓存） | — | **~1.08 µs** | **约 5.7× 更快** |

### 3.3 HTTP/2 HPACK 解码（请求热路径，v5.2.3 优化）

解码是每请求最贵的一段（占 feed 阶段约 91%），瓶颈在 Huffman 字面量解码。
v5.2.3 将 `huffmanDecode` 的热路径从「每符号三元窗口计算」改为「`curBits ≥ 8` 直接 8 位查表」，
长码回退不变，线格式与正确性完全一致：

| 场景（每 30 万次头块） | 优化前 | 优化后 | 变化 |
|------------------------|-------:|-------:|-----:|
| 含 Huffman 字面量请求头块 | 4.22 µs/op | **2.71 µs/op** | **约 36% 更快** |
| 纯索引请求头块（无 Huffman） | 0.38 µs/op | 0.33 µs/op | 略有下降 |

### 3.4 HTTP/2 响应整块缓存（响应热路径，v5.2.4 优化）

服务端 HPACK 编码器严格走「不索引 / 从不索引」表示，动态表恒为空，因此同一
`(status, headers)` 编码出的字节块是**确定且可复现的纯函数**。v5.2.4 据此缓存整块
（键 `serialize([status, headers])`，上限 256，由 {@see Http2Session} 持有），稳态下除首个请求外
几乎全部命中，直接跳过 `normalizeHeaders` + `HPACK encode` 两项开销——与 `Hpack::$literalCache`
同源（缓存纯函数结果，线格式不变）。`benchmarks/h2-split.php` 的 feed/respond 拆分（每 10 万次）：

| 路径（每 10 万次请求） | v5.2.3 | v5.2.4 | 变化 |
|------------------------|-------:|-------:|-----:|
| feed（decode + 收齐头） | 465.88 ms | 469.73 ms | 持平（解码未改） |
| respond（encode + 成帧） | 317.11 ms | **142.93 ms** | **约 55% 更快（≈2.2×）** |

真实服务的响应头组合高度固定（同一 status + 同一组头反复出现），该缓存使其在「每请求必做」的
响应热路径上几乎零 HPACK 开销，是本轮最显著的单点提速；端到端 h2c 吞吐由帧处理 / 网络主导，
故宏观 QPS 不受显著影响（微基准才是干净信号，同 3.1 节说明）。

## 优劣势对比

| 维度 | native（自研） | swoole | workerman |
|------|----------------|--------|-----------|
| 扩展依赖 | 零（仅 CLI 自带 pcntl/posix，可选 ext-event 提速） | 需 C 扩展 | 纯 PHP（事件循环可选扩展） |
| HTTP/2（h2c 明文） | ✅ 默认开 | ❌ 需 TLS | ❌ 仅 1.1 |
| 优雅关闭 GOAWAY | ✅ | ⚠️ | ⚠️ |
| 请求热路径 CPU | ✅ 比 workerman 快 ~17%（微基准 6/6 全胜） | C 引擎 | 持平 / 略慢 |
| 轻载荷吞吐（wrk） | 与 workerman 持平（±2% 噪声） | 略高（C 引擎） | 持平 |
| HPACK 编/解码 | 字面量缓存 5.7× + 解码 -36%（v5.2.3）+ 响应整块缓存 2.2×（v5.2.4） | C 引擎 | PHP 实现 |
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
> HTTP/2 开箱即用、协议与关闭更规范、代码可读可测」。在绝大多数业务服务的吞吐区间内，它与 workerman
> **持平**，并在请求热路径 CPU 上稳定领先（微基准 6/6 全胜）；HTTP/2 解码经 v5.2.3 优化再降约 36%，
> 响应热路径经 v5.2.4 整块缓存再降约 55%（≈2.2×）。

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
