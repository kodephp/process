# 常驻进程运行器 (Daemon)

`Kode::daemon()` 是一个**轻量多进程周期任务运行器**。它只依赖两个原语：

- **`Process::fork()`** —— 多进程（监督进程 + N 个 worker 子进程）
- **`Timer`** —— 周期（`every`）/ 定时（`cron`）调度

完全不碰 Master/Worker 池，因此也不踩官方 worker 池的回调空转陷阱。

---

## 为什么不用 `Kode\Process::start($config, $cb)` 跑周期任务？

这是个关键认知。看调用链：

1. `Kode\Process::start($config, $cb)` → `ProcessManager::start($cb)`
   （`src/Process.php:70` → `src/Master/ProcessManager.php:56`）
2. `ProcessManager::start` → `WorkerPool::setWorkerCallback($cb)` → `WorkerPool::start()`
   （`ProcessManager.php:72-74`）
3. `WorkerPool::start` 给每个 worker `setCallback($cb)` 后 `start()`
   （`src/Worker/WorkerPool.php:54-58`）
4. worker 子进程事件循环 `runEventLoop()` 每 tick 调 `processTasks()`
   （`src/Worker/WorkerProcess.php:161-178`）
5. **`processTasks()` 是空转**（`WorkerProcess.php:201-212`）：只 `return` 或把 `status` 置 `FREE`，
   **从不调用 `$this->callback`**

回调唯一被调用的地方是外部 `WorkerProcess::assignTask()`（`WorkerProcess.php:373-399` 行 384）。
而 master 启动后没有任何地方向池里推任务，所以 `Process::start($config, fn()=>...)` 传入的回调
在 worker 里**永远不会执行**——worker 只剩 `usleep(10000)` 空转。

> 结论：官方 worker 池是为「外部推任务」设计的（需 `assignTask`），不是「每进程自动跑周期任务」的
> 运行器。想要「常驻 + 周期执行自定义任务」，用本运行器。

---

## 快速开始

### daemon 文件

daemon 文件必须 **`return` 一个 `Kode\Process\Daemon\Daemon` 实例**（不要在其中调用 `->run()`，
由 `kode process start` 命令来调用）：

```php
<?php
// daemon.php
use Kode\Process\Kode;

return Kode::daemon()
    ->task(function (): void {
        file_put_contents('/tmp/tick', date('c') . "\n", FILE_APPEND);
    })
    ->every(5)                 // 每 5 秒；或 ->cron('0 * * * *')
    ->workers(4)              // 4 个 worker 子进程并行跑
    ->daemonize()             // 脱离终端常驻（可选）
    ->pidFile('/var/run/app.pid')
    ->maxRestarts(1000)       // 单槽累计重生上限，防 fork bomb
    ->run();                  // ← CLI 模式下由 kode process start 调用；独立脚本也可直接调用
```

### 命令行

```bash
kode process start daemon.php --workers=4 --every=5        # 前台启动
kode process start daemon.php --daemon --cron='0 0 * * *'  # 脱离终端常驻
kode process status                                        # 查看状态
kode process stop                                          # 优雅停止
kode process restart daemon.php                            # 平滑重启（detached）
```

选项（`start`）：

| 选项 | 说明 |
|------|------|
| `--daemon` | 脱离终端常驻（两次 fork + `setsid`） |
| `--workers=N` | 覆盖 worker 子进程数 |
| `--every=N` | 覆盖周期间隔（秒） |
| `--cron=expr` | 覆盖为 cron 表达式（如 `'0 * * * *'`），优先级高于 `--every` |

> CLI 启动的 PID 文件默认 `/tmp/kode-daemon.pid`；可用环境变量 `KODE_DAEMON_PID_FILE`
> 覆盖（命令与 `stop`/`status`/`restart` 共用，必须一致）。

### PID 文件即互斥锁

pid 文件回答的是**唯一**一个问题：「这一路守护进程是谁在跑」。因此它的规则只有四条，
全部由 `Daemon::claimPidFile()` 一份判据实现（`run()` 在守护化之前、`writePidFile()` 在落盘之前各调一次）：

| pid 文件现状 | 判定 | 依据 |
|------|------|------|
| 写着**存活且非本进程**的 pid | 抛 `ProcessException` 拒绝启动，文件**一个字节都不动** | 覆盖 = 把第一代抹成查不到的孤儿 |
| 写着自己的 pid | 放行（幂等重入） | 同一进程不需要跟自己抢 |
| 写着一个已死的 pid | 接管并覆写 | 上一代崩溃/被 SIGKILL 留下的现场 |
| 内容为空或不是纯数字 | 接管并覆写 | 读不出归属；**绝不猜一个 pid 去判活** |

两条容易忽略的口径：

- **判定存活用 `Process::isProcessAlive()`，EPERM 也算活着。** 查不到的进程一律按「别抢」处理，
  宁可拒启也不能把别人的实例赶走（pid 会被操作系统复用，这是这个方案固有的代价，
  管理端发信号前请核对 pid）。
- **退出清理只删写着「自己 pid」的文件。** 无条件 `unlink` 等于第二次启动的进程退出时
  把第一次那位的登记抹掉 —— 和「覆盖」是同一个后果。
- **占位判定排在 `daemonize()` 之前。** 守护化会 fork 且父进程随即 `exit`，
  在那之后才失败意味着异常没人读得到（落进 `kode process start` 的返回值里就是「静默启动失败」）。

因此 `kode process start` 重复执行**不会**叠出第二套守护进程：第二代直接拒绝启动并点名占位的 pid。

### 独立脚本（不走 CLI）

daemon 文件里直接 `->run()` 也能跑，然后 `php daemon.php` 即可。需要自己管 PID 文件与停止信号时
推荐走 `kode process` 子命令。

---

## 架构

```
                监督进程 (父)
        ┌───────────────────────────┐
        │  信号 (TERM/INT→停止)      │
        │  USR1→平滑重启全部 worker   │
        │  USR2→状态探针             │
        │  回收僵尸 (WNOHANG 轮询)    │
        │  异常退出 → 带上限重生       │
        └───────┬───────┬───────┬────┘
          fork  │       │       │  fork
                ▼       ▼       ▼
            worker0  worker1  worker2  ...   (N 个)
                │       │       │
                └── 各自独立事件循环：Timer::tick() 周期执行用户任务

停止：TERM → 监督进程通知各 worker TERM → 回收 → 清理 PID 文件
```

- **worker**：`installWorkerSignals()` 设 `TERM/INT/USR1` → 注册 `Timer` → 循环
  `Timer::tick()` + `pcntl_signal_dispatch()` + `usleep(10ms)`，直到被信号停止后 `exit(0)`。
- **监督进程**：`installSupervisorSignals()` 设 `TERM/INT` → `spawnWorker()` × N →
  进入 `supervise()`：每 ~50ms 轮询回收退出的子进程；异常退出则按 `maxRestarts` 上限决定是否重生；
  收到 `TERM/INT` 退出循环并 `stopAllWorkers()`。

---

## 健壮性要点

| 关注点 | 做法 |
|--------|------|
| **异常退出重生** | worker 异常退出后监督进程自动拉起新实例，维持 N 个 worker |
| **防 fork bomb** | 单槽累计重生次数超 `maxRestarts`（默认 1000）即放弃该槽位并告警，避免无限重生 |
| **重生退避** | 每次重生前 `usleep(restartDelay)`（默认 0.1s），降低风暴概率 |
| **优雅退出** | `TERM` 先通知 worker，等待其自行退出（最多 ~5s），残留才 `SIGKILL`，最后兜底回收 |
| **僵尸回收** | 监督进程用 `Process::wait(..., WNOHANG)` 轮询回收，不依赖 `SIGCHLD` 时序，确定性更强 |
| **PID 文件** | 监督进程启动后写入自身 PID，退出时清理；`stop`/`status` 复用 `isKodeMaster` 校验避免误杀 |
| **任务隔离** | 每个 worker 是独立 OS 进程，一个 worker 崩溃不拖垮其它 worker 或监督进程 |

---

## 与「多进程定时任务重复执行」的关系

Daemon 解决的是**单机上 N 个 worker 各自可靠地周期跑任务**。它**不做**跨进程/跨机的「同一时刻只跑一次」去重。

若你的任务还要求集群内至多执行一次，叠加以下任一方案（详见 [timer.md](./timer.md) 与 [cluster.md](./cluster.md)）：

- `Kode::cronCluster()` / `ClusterCron::create()` —— 每表达式分布式锁，抢到锁的 worker 才执行
- `Kode::tickCronOnLeader()` —— 整套 cron 只在 Leader 选举胜出者推进（强 exactly-once）
- `Kode::queue()`（Redis 后端）—— 持久、可重试、崩溃不丢的执行侧

> 一句话：**Daemon 负责「可靠地常驻跑」；集群锁/选举负责「不重复」；队列负责「持久可执行」。**

---

## API

`Kode::daemon(?LoggerInterface $logger = null): Daemon` 返回构造器，链式方法：

| 方法 | 说明 |
|------|------|
| `task(callable $cb, array $args = [])` | 设置每周期执行的任务（位置参数透传 `$args`） |
| `every(float $seconds)` | 周期间隔（秒）；传 `cron` 后本项失效 |
| `cron(string $expression)` | cron 表达式，优先级高于 `every` |
| `workers(int $count)` | worker 子进程数（≥1） |
| `daemonize(bool $v = true)` | 脱离终端常驻 |
| `pidFile(string $path)` | PID 文件路径（**互斥占位**，见上节《PID 文件即互斥锁》） |
| `maxRestarts(int $n)` | 单槽累计重生上限（防 fork bomb） |
| `run()` | 启动（占 pid 文件 → fork worker → 监督循环，直到停止信号） |

内部可测方法（供测试）：`runWorker(int $slot)`（子进程主循环）、`spawnWorker`、`stopAllWorkers`、
`exceedsRestartBudget(int $slot)`、`claimPidFile()`（pid 归属判定，唯一一份）、`cleanup()`（只删自己那份）。
