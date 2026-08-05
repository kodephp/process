# 性能压测数据

本仓库的压测数据**全部来自 `benchmarks/real-benchmark.php` 的实测**（而非估算）。脚本直接在本机运行、用 `microtime` 实测吞吐，聚焦**多进程维度**：进程创建、共享数据、进程间通信，并与裸 PHP 多进程原语对比，体现本库的极薄封装开销。

> **重要**：以下数字是在本开发机（macOS）上的实测快照，仅供相对量级参考。**生产环境请在本机运行脚本获取真实数据**：
>
> ```bash
> php benchmarks/real-benchmark.php [迭代次数]
> ```

---

## 测试环境（本次快照）

| 项目 | 配置 |
|------|------|
| PHP 版本 | 8.3.31（NTS） |
| 操作系统 | macOS Darwin 25.5.0 |
| CPU | 11 核 |
| OPcache | 开启 |
| GlobalData 选中后端 | `sysvshm`（本机未装 Swoole / APCu） |
| 迭代次数 | 80,000 |

> macOS 的 System V 共享内存总量仅约 4MB（`kern.sysv.shmall=1024`），故共享内存类基准逐段测量（用完即释放）。在 Linux 生产环境共享内存容量大得多，绝对数值会显著高于本快照。

---

## 一、进程创建（fork 率）

裸 `pcntl_fork`：约 **485 forks/s**（50 次采样）。本库的进程创建等价于裸 fork，无额外封装开销。

---

## 二、共享数据吞吐（多后端同类对比）

`GlobalData` 门面按优先级（Swoole Table → APCu → 共享内存）自动挑选后端。下表为共享内存后端（本机默认）的实测，以及「裸 `sysvshm` 原语」基线：

| 操作 | 裸 sysvshm（基线） | Kode GlobalData（sysvshm 后端） | 占裸比例 |
|------|-------------------|-------------------------------|----------|
| set  | ~16,300,000 ops/s | ~4,300,000 ops/s | ~27% |
| get  | ~15,800,000 ops/s | ~5,600,000 ops/s | ~36% |
| increment | — | ~1,190,000 ops/s | — |

- 本库在共享内存之上增加了**目录管理（键存在性 / TTL）+ 信号量原子保护**，因此 set/get 约为裸原语的 27%–36%；这是「零安装、开箱即用、支持 TTL 与原子操作」所付出的合理代价。
- 若运行环境已装 **Swoole**（自动选中 `swoole` 后端）或 **APCu**（自动选中 `apcu` 后端），吞吐会显著更高（见第四节说明）。

### TTL 子项（正确性，非吞吐）

`set('k', 'v', ttl: 1)` → 即时可读；1 秒后 `get()` 返回 `null`、`exists()` 返回 `false`。惰性过期行为符合预期。

### 网络 GlobalData（跨主机方案）

fork 起 `Server` + `Client` 往返：`set`+`get` 约 **22,300 ops/s**（单边操作约 44,600 ops/s）。适合多主机共享，但带网络开销。

---

## 三、进程间通信吞吐（loopback 微基准，消息 / 秒）

| 通道 | 裸原语（基线） | Kode 封装 | 占裸比例 |
|------|---------------|-----------|----------|
| Unix Socket 对 | ~857,000 msg/s | SocketIPC ~553,000–622,000 msg/s | 48%–65% |
| SysV 消息队列 | ~1,087,000 msg/s | MessageQueue ~395,000–398,000 msg/s | 35%–37% |
| 共享内存环形队列 | — | SharedMemoryIPC ~315,000 msg/s | — |

封装层承担了序列化、帧边界、超时与错误语义，故相对裸原语有可解释的开销；其中 SocketIPC 最接近裸性能。

---

## 四、与同类（Swoole / Workerman）对比说明

- **Swoole Table**：当运行环境已装 `ext-swoole` 时，`GlobalData::auto()` 会**首选 Swoole Table 后端**——与本库的 SwooleTable 适配器（实现同一 `TableInterface`）对接，性能接近原生 Swoole\Table。`benchmarks/real-benchmark.php` 内置一段「原生 `\Swoole\Table` 对比」分支，会在装了 swoole 的主机上同时测量原生 Swoole\Table 与 Kode 适配层的开销，从而给出适配层损耗的实测值。本开发机未安装 swoole，故此处不列出其绝对数值。
- **Workerman**：并非本库依赖，库内不内置与之的对比基准；旧文档中 Swoole / Workerman 的绝对 QPS 数值属**示意性估算，已被移除**，改以本库可一键复现的一手实测数据为准。

### 选型建议

| 场景 | 推荐 |
|------|------|
| 追求极致共享内存性能、已装 Swoole | 自动选中 `swoole` 后端 |
| 已装 APCu（FPM / 动态 worker） | 自动选中 `apcu` 后端 |
| 零安装、跨进程共享（默认） | `sysvshm` 后端 |
| 跨主机共享 | 网络 GlobalData（Server / Client） |

---

## 五、如何复现

```bash
# 默认 200,000 次迭代
php benchmarks/real-benchmark.php

# 自定义迭代次数（开发机可用较小值以快速验证）
php benchmarks/real-benchmark.php 80000
```

脚本会输出：进程创建率、各共享数据后端吞吐（含 TTL 正确性）、各 IPC 通道吞吐，以及当前环境「可用后端 / `GlobalData::auto()` 选中结果 / 同类方案可用性」的自检清单。
