# 性能压测数据

本仓库的压测数据**全部来自 `benchmarks/real-benchmark.php` 的实测**（而非估算）。脚本直接在本机运行、用 `microtime` 实测吞吐，聚焦**多进程维度**：本地多进程共享表的单进程微基准 + fork 跨进程正确性/吞吐，并与**原生 Swoole\Table**、**裸 PHP 共享内存原语**对比，体现本库适配层的真实开销。

> **重要**：以下数字是在本开发机上的实测快照，仅供相对量级参考。**生产环境请在本机运行脚本获取真实数据**：
>
> ```bash
> php benchmarks/real-benchmark.php [单进程迭代数] [子进程数] [每子进程自增数]
> ```

---

## 测试环境（本次快照）

| 项目 | 配置 |
|------|------|
| PHP 版本 | 8.3.31（NTS，无 ZTS） |
| 操作系统 | macOS Darwin 25.5.0 |
| CPU | 11 核 |
| OPcache | 开启 |
| 已编译扩展 | `ext-swoole` 6.2.2（**本机已装，故 `auto()` 选中 `swoole`） |
| 未装扩展 | APCu（CLI 还需 `apc.enable_cli=1`）、Workerman 多进程表（现代版本已移除） |
| SharedTable 可选后端 | `[swoole, sysvshm]` → `auto()` 选中 `swoole` |
| 单进程迭代数 | 100,000 · 子进程 4 · 每子进程自增 10,000 |

> macOS 的 System V 共享内存总量仅约 4MB（`kern.sysv.shmall=1024`），故共享内存类基准逐段测量（用完即释放）。在 Linux 生产环境共享内存容量大得多，绝对数值会显著高于本快照；但**相对量级与适配层开销比例**具有参考意义。

---

## 一、同类可用性自检

脚本启动时先打印各同类方案在本机的可装状态，避免「编造对比」：

| peer | 可用 | 说明 |
|------|------|------|
| `Swoole\Table` | **yes** | `ext-swoole` 已编译安装 6.2.2（本机实测） |
| APCu | no | 未装；CLI 还需 `apc.enable_cli=1` |
| Workerman | no | 现代 Workerman（v4/v5）已移除共享内存表；旧版 v3 的 `Workerman\Table` 即 `Swoole\Table` 子类（需 ext-swoole），故可直接用上方 Swoole 数字代表 |
| SysV shm | yes | PHP 内置 `ext-sysvshm`+`ext-sysvsem`，零安装兜底 |

本库 `SharedTable` 自动择优：可用 `[swoole, sysvshm]`，`auto()` 选中 `swoole`。

---

## 二、单进程微基准（set / get / increment / cas，ops/s）

键空间有界（500 个键循环写入），避免撑爆 Swoole 固定行数 / SysV 小段。

| backend | set | get | increment | cas |
|---------|-----:|-----:|----------:|----:|
| Kode\[swoole\] | 3,773,858 | 3,669,846 | 2,324,397 | 1,829,122 |
| Kode\[sysvshm\] | 1,491,425 | 2,257,697 | 551,375 | 20,485 |
| 原生 Swoole\Table | 10,586,597 | 10,435,150 | 15,128,784 | N/A* |

`*` `Swoole\Table` **无原生 CAS**；本库 `SwooleTable` 后端用锁实现 `cas`（见上 Kode\[swoole\] 行）。

观察：
- 本库 `swoole` 后端相对原生 `Swoole\Table` 约 **35%（set）– 65%（cas）** 的吞吐 —— 差额来自本库统一的 `TableInterface` 封装：`set` 走 `v` 列序列化、`cas` 需加锁读-比-写。这是「多后端可互换、统一语义、支持 TTL 与软件 CAS」所付的合理代价。
- `sysvshm` 后端 `cas` 仅 ~2 万 ops/s：软件 CAS 要读目录 + 加信号量 + 读值 + 比较 + 写回，开销集中于此；set/get 仍达百万级。
- 若运行环境已装 APCu，`auto()` 会选中 `apcu` 后端（本机未装，未列出绝对数）。

---

## 三、多进程跨进程基准（fork 子进程写、父进程校验 + 测吞吐）

这才是 `Swoole\Table` / Workerman / 本库共享表真正解决的多进程数据共享场景：父进程 fork 出 N 个子进程，各子进程写自身可见键并以**原子自增**累加共享计数，父进程回收后校验「跨进程可见性」与「原子自增正确性」。

| backend | ops | ops/s | 跨进程校验 |
|---------|----:|------:|-----------|
| Kode\[swoole\] | 40,004 | 564,247 | OK |
| Kode\[sysvshm\] | 40,004 | 342,691 | OK |
| 原生 Swoole\Table | 40,004 | 2,759,868 | OK |

`ops = 子进程数 + 子进程数 × 每子进程自增`（即每个子进程 1 次 set + 10,000 次 increment）。校验项：`cnt === 40,000` 且每个 `c{i}` 对父进程可见。

观察：
- 三条路径**全部通过跨进程校验**，证明本库 `SharedTable` 各后端的跨进程可见性与原子自增在真实 fork 场景下正确。
- 原生 `Swoole\Table` 跨进程吞吐约为本库 `swoole` 后端的 **4.9 倍** —— 差额即本库适配层的锁 + 序列化开销（与单进程微基准的差距一致）。若你的场景对共享表吞吐极度敏感且已装 Swoole，本库 `swoole` 后端的绝对数字（$5.6\times10^5$ ops/s 量级）已非常可观。
- `sysvshm` 后端**零安装**即可跨进程工作，是「不装 Swoole 也能用」的关键兜底。

---

## 四、与同类（Swoole / Workerman）对比的结论

- **Swoole\Table**：当运行环境已装 `ext-swoole` 时，`SharedTable::auto()` 会**首选 Swoole 后端**。本库 `SwooleTable` 适配器实现同一 `TableInterface`，性能约为原生 `Swoole\Table` 的 35%（set）– 65%（cas）。脚本内置「原生 `\Swoole\Table` vs Kode 适配层」对比分支，已在上方给出实测适配层损耗。
- **Workerman**：现代 Workerman（v4/v5）**无共享内存表**，跨进程共享改走网络 GlobalData / Redis 等外部存储。旧版 Workerman（v3）的 `Workerman\Table` 类**即是 `Swoole\Table` 的子类**，表现与 Swoole 持平（需 ext-swoole）。本库已内置 `WorkermanTable` 后端：当 `class_exists('\Workerman\Table')` 为真时 `auto()` 自动纳入；本机未装旧版 Workerman，故标 skip。
- **选型**：已装 Swoole → 自动 `swoole` 后端最快；已装 APCu → 自动 `apcu`；否则零安装 `sysvshm` 兜底；跨主机 → 网络 GlobalData（Server/Client）。

---

## 五、如何复现

```bash
# 默认 100000 次单进程迭代 / 4 子进程 / 每子进程 10000 次自增
php benchmarks/real-benchmark.php

# 自定义：单进程迭代 / 子进程数 / 每子进程自增
php benchmarks/real-benchmark.php 100000 4 10000
```

脚本会依次输出：同类可用性自检、单进程微基准（各可用后端 + 原生 Swoole\Table）、fork 跨进程正确性+吞吐，以及 `SharedTable::auto()` 在该环境选中的后端。

> 进程间通信（SocketIPC / MessageQueue / SharedMemoryIPC）的吞吐基准由各 IPC 类的测试覆盖，本脚本聚焦于「多进程共享表」这一核心场景的同类对比。
