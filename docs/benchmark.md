# 压测数据

同一份业务代码（`benchmarks/portable-server.php`：HTTP GET `/` → 12 字节纯文本），
分别在 **native / swoole / workerman** 三种运行时上用 `wrk` 施加完全相同压力得到的吞吐与延迟对比。

## 测试条件

| 项 | 值 |
|----|----|
| 日期 | 2026-08-07 |
| PHP | 8.3.33 |
| CPU 核心 | 11 |
| 参数 | workers=4，duration=15s，connections=200，threads=4 |
| 载荷 | HTTP GET `/` → 12 字节纯文本 |
| 工具 | wrk（`bash benchmarks/runtime-bench.sh 4 15 200 4`） |

## 汇总

| 运行时 | QPS | 相对 Workerman | P50 延迟 | P99 延迟 | 吞吐带宽 |
|--------|----:|---------------:|---------:|---------:|---------:|
| **native**（自研，零扩展） | **172,350** | 95.3% | 0.80 ms | 18.98 ms | 14.96 MB/s |
| **swoole**（C 实现） | **188,156** | 100% | 0.73 ms | 2.12 ms | 29.61 MB/s |
| **workerman**（纯 PHP 依赖） | **180,808** | 100% | 0.72 ms | 11.84 ms | 19.66 MB/s |

> 自研 Native 运行时在**零扩展依赖**的前提下，吞吐达到 Workerman 的 95.3%、Swoole 的 91.6%，
> 三者处于同一量级。早期「相对 Workerman 仅 1.010×」是另一套更薄的网络 I/O 内核的实测值，
> 与 v5.0.0 默认形态（完整 master-worker 多进程运行时）不可直接比较；详见 `src/Runtime`。

## 原始明细

### native

```
Thread Stats   Avg      Stdev     Max   +/- Stdev
  Latency     1.42ms    4.52ms 150.58ms   98.06%
  Req/Sec    43.51k     7.33k   69.85k    91.15%
Latency Distribution
   50%  798.00us
   75%    1.16ms
   90%    1.68ms
   99%   18.98ms
2598079 requests in 15.07s, 225.47MB read
Requests/sec: 172349.80
```

### swoole

```
Thread Stats   Avg      Stdev     Max   +/- Stdev
  Latency   815.04us  427.58us  13.30ms   79.65%
  Req/Sec    47.35k     2.40k   67.63k    78.28%
Latency Distribution
   50%  733.00us
   75%    0.99ms
   90%    1.31ms
   99%    2.12ms
2841305 requests in 15.10s, 447.10MB read
Requests/sec: 188155.58
```

### workerman

```
Thread Stats   Avg      Stdev     Max   +/- Stdev
  Latency     1.17ms    3.94ms  98.89ms   98.26%
  Req/Sec    45.51k     7.73k   53.38k    94.83%
Latency Distribution
   50%  715.00us
   75%    0.93ms
   90%    1.24ms
   99%   11.84ms
2717166 requests in 15.03s, 295.41MB read
Requests/sec: 180807.69
```

## 怎么复现

```bash
# 需要 wrk：brew install wrk
bash benchmarks/runtime-bench.sh 4 15 200 4
# 结果写入 benchmarks/bench-result.txt
```

- `benchmarks/portable-server.php`：跨运行时通用的服务脚本（`Kode::serve(..., 'native'|'swoole'|'workerman')`）。
- `benchmarks/runtime-bench.sh`：依次启动三运行时并跑 wrk，汇总到 `bench-result.txt`。
- 运行期产物（`bench-result.txt`、`workerman.log`、`gate/*` 等）已加入 `.gitignore`，不入库。
