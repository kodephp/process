# 事件循环（Reactor）

> 设计原则：**可选加速 + 零扩展兜底**。装了扩展就走 C 层多路复用，没装也能跑。

## 三种驱动

| 驱动 | 类 | 依赖 | 权重 | 底层 |
|---|---|---|:-:|---|
| `event` | `Reactor\EventLoop` | `ext-event` | 100 | libevent（epoll/kqueue） |
| `ev` | `Reactor\EvLoop` | `ext-ev` | 90 | libev（epoll/kqueue） |
| `select` | `Reactor\SelectLoop` | 无 | 0 | `stream_select` |

`LoopFactory` 按权重降序择优，`select` 永远可用，是最后一道防线。

```bash
# 可选加速（强烈建议生产环境安装其一）
pecl install event
# 或
pecl install ev
```

## 使用

```php
use Kode\Process\Reactor\LoopFactory;

$loop = LoopFactory::create();        // 自动择优
$loop = LoopFactory::create('select');// 显式指定
$loop = LoopFactory::global();        // 全局共享实例

LoopFactory::preferred();   // 'event'
LoopFactory::available();   // ['event', 'select']
LoopFactory::diagnose();    // 逐驱动可用性报告
```

## 契约

```php
// I/O
$loop->onReadable($stream, fn($s) => ...);   // 同一 stream 重复注册会覆盖
$loop->offReadable($stream);
$loop->onWritable($stream, fn($s) => ...);
$loop->offWritable($stream);

// 信号
$loop->onSignal(SIGTERM, fn(int $sig) => ...);
$loop->offSignal(SIGTERM);

// 定时器
$id = $loop->addTimer(1.5, fn() => ..., periodic: true);
$loop->delTimer($id);

// 微任务：下一次循环迭代执行
$loop->defer(fn() => ...);

// 生命周期
$loop->run();        // 阻塞直到 stop()
$loop->stop();
$loop->isRunning();
$loop->destroy();    // 释放全部监听与底层资源
$loop->stats();      // ['driver'=>..., 'read'=>, 'write'=>, 'timer'=>, 'signal'=>, 'deferred'=>]
```

## 语义保证

三种驱动的行为完全一致，`tests/SelectLoopTest.php` 是行为契约的基准：

- **一次性定时器触发后自动摘除**，`stats()['timer']` 随之归零
- **周期定时器**需显式 `delTimer()` 才会停止
- `offReadable()` 后回调不再投递
- `defer()` 的回调在**下一次**迭代执行，晚于当前同步代码
- `destroy()` 幂等，清空所有计数

> **回调异常隔离（v5.2.15）**：read / write / 定时器 / 信号 / deferred 回调中抛出的任何异常都会被事件循环**就地捕获并隔离**（`error_log` 记录后继续运行），不会穿透 `run()` 打死整个循环。长驻服务中单条任务的底层异常不应中断整个事件循环——这与 `QueueManager` 消费循环、`NativeRuntime` handler 的错误边界策略一致。需要「失败即停」的语义请在回调内部自行处理。

## 与运行时的关系

Reactor 是**独立的统一事件循环层**（`Kode::loop()`），与 `Runtime` 兼容层正交：

- **Swoole / Workerman 运行时**在跑服务时用各自的宿主事件循环，不叠加第二套
  （Workerman 在 Linux 上也会自动选择 `ext-event`，底层是同一个 libevent）
- **本层和运行时解耦**：当你的代码需要一套可移植的事件循环（不依赖任何运行时）时，
  用 `Kode::loop()` 即可——例如纯 CLI 工具、定时器驱动、与 `kode/fibers` 协程配合。
  `LoopFactory` 仅在手动调用 `Kode::loop()` 时创建实例，不会干扰 `Runtime` 的服务器循环。

## 已知限制

`SelectLoop` 受 `FD_SETSIZE` 限制（多数系统 1024），且每次迭代 O(n) 扫描全部 fd。
连接数超过几百就应该装 `ext-event` 或 `ext-ev`——这也是它权重最低的原因。
