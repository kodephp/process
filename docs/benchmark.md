# 性能压测数据

本仓库的压测数据**全部来自 `benchmarks/real-benchmark.php` 的实测**（而非估算）。脚本直接在本机运行、用 `microtime` 实测吞吐，聚焦**多进程维度**：本地多进程共享表的单进程微基准 + fork 跨进程正确性/吞吐，并与**原生 Swoole\Table** 对比，体现「本库零安装兜底」与「成熟方案」的真实差距。

> **重要**：以下数字是在本开发机上的实测快照，仅供相对量级参考。**生产环境请在本机运行脚本获取真实数据**：
>
> ```bash
> php benchmarks/real-benchmark.php [单进程迭代数] [子进程数] [每子进程自增数]
> ```

---

## 定位澄清：本库共享表是 Swoole / Workerman 之外的「第三选择」

Swoole、Workerman 运行多年、生态成熟稳定。**若你的应用已经基于它们，应优先使用它们自带的共享表**（`Swoole\Table` / `Workerman\Table`），不必引入本库维护负担。本库内置共享表的真正价值，是**在你不打算引入 Swoole / Workerman 时**提供一套**零安装、零依赖**的兜底。**「GlobalData」这个概念与名称来自 Workerman 的 GlobalData 组件**——本库网络版是同一思路的兼容实现。压测的目的不是「打败」Swoole/Workerman，而是验证：当用不上它们时，本库兜底是否足够好用。

---

## 测试环境（本次快照）

| 项目 | 配置 |
|------|------|
| PHP 版本 | 8.3.31（NTS，无 ZTS） |
| 操作系统 | macOS Darwin 25.5.0 |
| CPU | 11 核 |
| OPcache | 开启 |
| 已装扩展 | `ext-swoole` 6.2.2（作为**兼容适配器**可用，非本库必需） |
| 未装扩展 | APCu（CLI 还需 `apc.enable_cli=1`）、Workerman 多进程表（现代版本已移除） |
| SharedTable 可选后端 | `[sysvshm, swoole]`（零安装兜底在前）→ `auto()` 默认选中 `sysvshm` |
| 单进程迭代数 | 100,000 · 子进程 4 · 每子进程自增 10,000 |

> macOS 的 System V 共享内存总量仅约 4MB（`kern.sysv.shmall=1024`），故共享内存类基准逐段测量（用完即释放）。在 Linux 生产环境共享内存容量大得多，绝对数值会显著高于本快照；但**相对量级与适配层开销比例**具有参考意义。

---

## 一、同类可用性自检

脚本启动时先打印各同类方案在本机的可装状态，避免「编造对比」：

| peer | 可用 | 说明 |
|------|------|------|
| `Swoole\Table` | **yes** | `ext-swoole` 已编译安装 6.2.2（本机实测，作为兼容适配器） |
| APCu | no | 未装；CLI 还需 `apc.enable_cli=1` |
| Workerman | no | 现代 Workerman（v4/v5）已移除共享内存表；旧版 v3 的 `Workerman\Table` 即 `Swoole\Table` 子类（需 ext-swoole），故可直接用上方 Swoole 数字代表 |
| SysV shm | yes | PHP 内置 `ext-sysvshm`+`ext-sysvsem`，本库零安装兜底 |

本库 `SharedTable` 默认 `auto()` 只在**零安装后端**间择优（可用 `[sysvshm, swoole]`，`auto()` 选中 `sysvshm`）。`swoole` / `workerman` 是**兼容适配器**，应用已运行在它们之上时通过 `make('swoole')` / `make('workerman')` 显式复用其共享表。

---

## 二、单进程微基准（set / get / increment / cas，ops/s）

键空间有界（500 个键循环写入），避免撑爆 Swoole 固定行数 / SysV 小段。

| backend | set | get | increment | cas |
|---------|-----:|-----:|----------:|----:|
| 本库兜底 Kode\[sysvshm\] | 1,415,708 | 2,070,607 | 520,771 | 21,136 |
| 兼容适配器 Kode\[swoole\] | 4,302,027 | 3,834,617 | 2,428,426 | 1,948,293 |
| 原生 Swoole\Table | 10,546,402 | 10,754,901 | 13,613,008 | N/A* |

`*` `Swoole\Table` **无原生 CAS**；本库 `SwooleTable` 兼容适配器用锁实现 `cas`（见上 Kode\[swoole\] 行）。

观察：
- **本库零安装兜底（sysvshm）的真实位置**：整体约为原生 `Swoole\Table` 的 **4%（increment）– 19%（get）**，CAS 仅约 2 万 ops/s。这正是预期——它胜在「零安装、零依赖、跨进程语义完整」，而非性能。若你的场景对共享表吞吐极度敏感且已装 Swoole，直接用 Swoole\Table 即可。
- **兼容适配器（Kode\[swoole\]）的开销**：复用 Swoole 共享表时，本库 `TableInterface` 封装带来 set 约 41%、get 约 36%、increment 约 18% 的吞吐（差额来自统一封装 + 锁 + 序列化）。这是「统一语义、支持 TTL 与软件 CAS、多后端可互换」所付的合理代价。
- `sysvshm` 后端 `cas` 仅 ~2 万 ops/s：软件 CAS 要读目录 + 加信号量 + 读值 + 比较 + 写回，开销集中于此；set/get 仍达百万级。
- 若运行环境已装 APCu，`auto()` 会选中 `apcu` 后端（本机未装，未列出绝对数）。

---

## 三、多进程跨进程基准（fork 子进程写、父进程校验 + 测吞吐）

这才是共享表真正解决的多进程数据共享场景：父进程 fork 出 N 个子进程，各子进程写自身可见键并以**原子自增**累加共享计数，父进程回收后校验「跨进程可见性」与「原子自增正确性」。

| backend | ops | ops/s | 跨进程校验 |
|---------|----:|------:|-----------|
| 本库兜底 Kode\[sysvshm\] | 40,004 | 325,027 | OK |
| 兼容适配器 Kode\[swoole\] | 40,004 | 562,763 | OK |
| 原生 Swoole\Table | 40,004 | 2,842,002 | OK |

`ops = 子进程数 + 子进程数 × 每子进程自增`（即每个子进程 1 次 set + 10,000 次 increment）。校验项：`cnt === 40,000` 且每个 `c{i}` 对父进程可见。

观察：
- 三条路径**全部通过跨进程校验**，证明本库 `SharedTable` 各后端的跨进程可见性与原子自增在真实 fork 场景下正确。
- 原生 `Swoole\Table` 跨进程吞吐约为本库兜底（sysvshm）的 **8.7 倍**、约为本库兼容适配器（swoole）的 **5 倍** —— 差额即「零安装软件实现」vs「扩展原生共享内存」的差距。若你的场景对共享表吞吐极度敏感且已装 Swoole，本库兼容适配器已能拿到 $5\times10^5$ ops/s 量级，仍非常可观。
- `sysvshm` 后端**零安装**即可跨进程工作，是「不装 Swoole 也能用」的关键兜底。

---

## 四、与同类（Swoole / Workerman）对比的结论

- **Swoole\Table（成熟选择）**：本库 `SwooleTable` 兼容适配器实现同一 `TableInterface`，让本库代码能直接复用 Swoole 的共享表（不引入第二个依赖）。实测适配层开销：set 约 41%、get 约 36%、increment 约 18%。当应用已基于 Swoole 时，建议直接用 `Swoole\Table`，需要统一 API 时再走本库适配器。
- **Workerman（成熟选择 + GlobalData 名称来源）**：「GlobalData」这个概念与名称来自 Workerman 的 GlobalData 组件。现代 Workerman（v4/v5）已无共享内存表，跨进程共享走网络 GlobalData / Redis；旧版 Workerman（v3）的 `Workerman\Table` 即 `Swoole\Table` 子类，表现与 Swoole 持平（需 ext-swoole）。本库已内置 `WorkermanTable` 兼容适配器，当 `class_exists('\Workerman\Table')` 为真时可用。
- **本库零安装兜底（sysvshm）的价值边界**：从上表可见，本库兜底的吞吐显著低于 Swoole/Workerman（CAS 仅约 2 万 ops/s、整体约为原生 Swoole 的 4%–19%）。**这正是预期**：它胜在「零安装、零依赖、跨进程语义完整」，而非性能。若压测显示它与 Workerman 量级相当，那更说明「能上 Workerman 就直接用 Workerman」；本库兜底的使命是「上不了 Swoole / Workerman 时仍然能用」。
- **选型建议**：
  - 已用 Swoole / Workerman → 直接用它们自带的共享表（成熟稳定、无需额外维护）。
  - 两者都不想引入 → 本库 `SharedTable::auto()` 零安装兜底（sysvshm / apcu）。
  - 跨主机共享 → 网络 GlobalData（Server/Client，与 Workerman GlobalData 同思路兼容）。

---

## 五、如何复现

```bash
# 默认 100000 次单进程迭代 / 4 子进程 / 每子进程 10000 次自增
php benchmarks/real-benchmark.php

# 自定义：单进程迭代 / 子进程数 / 每子进程自增
php benchmarks/real-benchmark.php 100000 4 10000
```

脚本会依次输出：同类可用性自检、单进程微基准（本库兜底 + 兼容适配器 + 原生 Swoole\Table）、fork 跨进程正确性+吞吐，以及 `SharedTable::auto()` 在该环境选中的后端（本机默认选中零安装兜底 `sysvshm`）。

> 进程间通信（SocketIPC / MessageQueue / SharedMemoryIPC）的吞吐基准由各 IPC 类的测试覆盖，本脚本聚焦于「多进程共享表」这一核心场景的同类对比。
