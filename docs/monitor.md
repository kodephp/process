# 监控（Monitor）

> 设计原则：**监控自身绝不能成为故障源**。巡检是常驻循环，任何一个进程、连接或回调出问题，都不得中断整轮扫描或打死 worker。

`Kode\Process\Monitor` 下有四个互相独立的组件：

| 组件 | 职责 | 粒度 |
|---|---|---|
| `ProcessMonitor` | 进程存活 / 内存 / CPU 健康巡检与自动重启 | 进程（pid） |
| `Heartbeat` | 进程级心跳上报与超时判定 | 进程（pid） |
| `ConnectionHeartbeat` | 连接级心跳保活与超时摘除 | 连接（connection id） |
| `FileMonitor` | 源文件变更监视（热重载） | 文件 |

---

## ProcessMonitor

```php
use Kode\Process\Monitor\ProcessMonitor;

$monitor = new ProcessMonitor();
$monitor->start();

$monitor->register($pid, [
    'memory_limit' => 256 * 1024 * 1024,  // 字节
    'cpu_limit'    => 80.0,               // 百分比
]);

$monitor->onUnhealthy(function (int $pid, array $result): void {
    // $result['issues'] 可能含 memory_exceeded / cpu_exceeded
});

$monitor->onRestart(function (int $pid): void {
    // 进程已判定为 dead，在此拉起替代进程
});

$results = $monitor->checkAll();
```

- `check($pid)` 返回 `status` 为 `healthy` / `unhealthy` / `dead` / `unknown`。
- 存活判定用 `posix_kill($pid, 0)`，并区分 `ESRCH`（真的不在了）与 `EPERM`（在，但无权限）——后者仍算存活。
- CPU 采用**双采样求占用率**，返回 0~100 的百分比，而不是累计 CPU 秒数。
- 重启次数受 `setMaxRestartAttempts()` 约束，超限后只记日志不再触发回调；`resetRestartAttempts($pid)` 可清零。

### 巡检的错误边界

`checkAll()` 是常驻循环里被反复调用的方法，它对三类异常做了隔离：

| 异常来源 | 处理 |
|---|---|
| `check($pid)` 自身抛错（如 `/proc` 读取失败） | 记日志，该 pid 记为 `unknown` + 不健康，继续巡检下一个 |
| `onUnhealthy` 回调抛错 | 记日志，继续执行后续回调 |
| `onRestart` 回调抛错 | 记日志，继续执行后续回调 |

换言之，**`checkAll()` 不会因为任何单点故障而提前返回或向上抛异常**。这一点由 `ProcessMonitorTest` 中的三个回归用例守卫。

---

## Heartbeat（进程级）

```php
use Kode\Process\Monitor\Heartbeat;

$hb = new Heartbeat($logger);
$hb->setTimeout(30.0);

$hb->onTimeout(function (int $pid, float $elapsed): void {
    // 心跳超时处置
});

$hb->beat($pid);            // worker 侧定期上报
$results = $hb->check();    // master 侧定期判定
```

`beat()` 对未注册的 pid 会自动注册。`check()` 返回 `active` / `timeout` 两组明细。

**超时回调全部包了异常隔离**：注册多个 `onTimeout` 时，前一个抛异常不会阻断后一个，也不会中断整轮 `check()`。

---

## ConnectionHeartbeat（连接级）

```php
use Kode\Process\Monitor\ConnectionHeartbeat;

$hb = new ConnectionHeartbeat(interval: 55, timeout: 110);

$hb->register($connectionId, ['ip' => $ip]);
$hb->onHeartbeat(fn(int $id): bool => $connections[$id]->send($ping));
$hb->onTimeout(fn(int $id) => $connections[$id]->close());

// 在定时器里
$hb->sendHeartbeats();   // 给静默超过 interval 的连接发 ping
$result = $hb->check();  // 摘除静默超过 timeout 的连接
```

收到任意报文时调用 `updateActivity($id)` 刷新活跃时间。

设计要点：

- **超时连接必定被摘除**。`onTimeout` 回调即便抛异常，`unregister()` 也照常执行——否则该连接会永远残留在表里，每一轮都重复触发回调。
- **超时处置在遍历结束后统一执行**，不在 `foreach` 内部改动连接表，避免边遍历边删除。
- `onHeartbeat` 回调抛异常时该连接计为发送失败并继续处理后续连接；返回值统一转成 `bool`。
- 时间差做了 `max(0, ...)` 钳制，系统时钟回拨不会算出负的静默时长。

---

## FileMonitor（热重载）

```php
use Kode\Process\Monitor\FileMonitor;

$monitor = FileMonitor::watch([__DIR__ . '/src'], function (array $changes): void {
    // $changes = ['added' => [...], 'modified' => [...], 'deleted' => [...]]
    Kode::runtime()->reload();
});

$monitor->setExtensions(['.php', '.env'])
        ->addExcludeDir('runtime')
        ->setCheckInterval(1_000_000);   // 微秒

$monitor->start();   // 阻塞循环；或在自己的定时器里调 tick()
```

### tick() 会自动推进基线

`tick()` 在触发回调之后会调用 `applyChanges()` 更新 mtime 快照。这一步是必须的——否则同一次文件改动会在**每一个** tick 被重复上报，热重载场景下直接退化成无限重启。

如果只用 `checkChanges()` 手动驱动，就需要自己在处理完之后调用 `applyChanges($changes)`。

### 扫描的安全边界

| 情况 | 行为 |
|---|---|
| 目录不可读（`scandir` 返回 false） | 跳过该目录，不中断整次扫描 |
| 目录内的软链接 | **一律跳过**，防止指向祖先目录的环形链接导致无限递归 |
| 递归深度超过 32 层 | 停止下探（软链接跳过之外的第二道防线） |
| `filemtime()` 失败（扫描期间文件被删） | 跳过该文件，不写入 `false` 污染快照 |
| 变更回调抛异常 | 记录到 `error_log` 并继续，基线照常推进，监视循环不中断 |

监视根目录本身会先经 `realpath()` 解析，所以**把软链接目录作为监视根传入是可以的**，被跳过的只是目录树内部的软链接。

### mtime 变小也算变更

变更判定用的是 `!==` 而不是 `<`。`git checkout` 回退到旧版本时 mtime 会变小，这种情况同样需要触发重载。

---

## 与运行时集成

```php
Kode::serve([
    'worker_num' => 4,
    'on' => [
        'workerStart' => function () {
            $loop = Kode::loop();
            $hb = new ConnectionHeartbeat(55, 110);

            $loop->addTimer(30.0, function () use ($hb) {
                $hb->sendHeartbeats();
                $hb->check();
            }, true);
        },
    ],
]);
```

定时器回调本身也有异常隔离（见 [docs/reactor.md](reactor.md)），叠加监控组件内部的隔离，构成两层防护。
