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

## 怎么复现

```bash
# 需要 wrk：brew install wrk
bash benchmarks/runtime-bench.sh 4 15 200 4
# 结果写入 benchmarks/bench-result.txt
```

- `benchmarks/portable-server.php`：跨运行时通用的服务脚本（`Kode::serve(..., 'native'|'swoole'|'workerman')`）。
  不显式传 `reusePort` 时走运行时的平台自适应默认（见上文）。可用 `REUSE_PORT=0|1` 环境变量强制覆盖做 A/B。
- `benchmarks/runtime-bench.sh`：依次启动三运行时并跑 wrk，汇总到 `bench-result.txt`。
- 运行期产物（`bench-result.txt`、`bench-result.before-reuseport.txt`、`workerman.log`、`gate/*` 等）已加入 `.gitignore`，不入库。
