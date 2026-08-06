# 信号处理

信号是进程间通信的一种方式，用于控制进程行为。

## 支持的信号

| 信号 | 常量 | 说明 |
|------|------|------|
| SIGINT | 2 | 中断信号（Ctrl+C） |
| SIGTERM | 15 | 终止信号 |
| SIGHUP | 1 | 挂起信号（重载配置） |
| SIGUSR1 | 10 | 用户自定义信号1 |
| SIGUSR2 | 12 | 用户自定义信号2 |
| SIGCHLD | 17 | 子进程状态改变 |

## 基本用法

### 注册信号处理器

```php
use Kode\Process\Signal\SignalHandler;

// 推荐用单例：同一进程内共享已注册的信号监听器
$handler = SignalHandler::getInstance();

// 注册 SIGINT 处理器
$handler->register(SIGINT, function () {
    echo "收到 SIGINT 信号，准备退出\n";
    exit(0);
});

// 注册 SIGTERM 处理器
$handler->register(SIGTERM, function () {
    echo "收到 SIGTERM 信号，优雅关闭\n";
    gracefulShutdown();
});

// 注册 SIGHUP 处理器（重载配置）
$handler->register(SIGHUP, function () {
    echo "收到 SIGHUP 信号，重载配置\n";
    reloadConfig();
});
```

### 发送信号

```bash
# 终止进程
kill -SIGTERM <pid>

# 重载配置
kill -SIGHUP <pid>

# 用户信号
kill -SIGUSR1 <pid>
```

## 在服务中使用

> 运行时的 `SIGTERM` / `SIGINT` 已由 `Kode::serve()` 自动处理为**优雅停机**，
> `SIGUSR1` 自动处理为**进程级平滑重载**。下面的示例展示如何在 worker 中注册
> **自定义信号**（如状态打印、应用级缓存刷新），与运行时默认行为共存。

### 示例：状态打印（SIGUSR2）

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Signal\SignalHandler;

Kode::serve('tcp://0.0.0.0:8080', ['workers' => 4])
    ->on('workerStart', function (int $workerId) {
        $handler = SignalHandler::getInstance();

        // SIGUSR2：打印当前 worker 状态
        $handler->register(SIGUSR2, function () use ($workerId) {
            echo "Worker {$workerId} 内存: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
        });
    })
    ->on('message', fn($conn, $data) => $conn->send('Hello'))
    ->start();
```

### 示例：应用级缓存刷新（SIGUSR1）

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Signal\SignalHandler;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('workerStart', function (int $workerId) {
        $handler = SignalHandler::getInstance();

        // 进程级平滑重载由运行时完成；此处做应用级缓存刷新
        $handler->register(SIGUSR1, function () use ($workerId) {
            echo "Worker {$workerId} 刷新应用缓存\n";
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
        });
    })
    ->on('message', fn($conn, $req) => $conn->send('Hello'))
    ->start();
```

## 信号分发器

```php
use Kode\Process\Signal\SignalDispatcher;

$dispatcher = SignalDispatcher::getInstance();

// 注册信号处理器
$dispatcher->register(SIGTERM, function () {
    echo "终止信号\n";
});

$dispatcher->register(SIGUSR1, function () {
    echo "用户信号1\n";
});

// 分发信号
$dispatcher->dispatch(SIGTERM);

// 移除处理器
$dispatcher->unregister(SIGUSR1);
```

## 进程控制命令

使用 `kode` 命令控制进程：

```bash
kode start              # 启动服务
kode stop              # 停止服务
kode reload            # 平滑重载
kode status            # 查看状态
kode info             # 版本信息
```

## 底层信号说明

`kode` 命令底层使用信号实现（Workerman / Swoole 运行时均遵循此行为）：

| 信号 | 说明 | 操作 |
|------|------|------|
| SIGTERM | 优雅停止 | `kill -TERM $PID` |
| SIGINT | 优雅停止 | `Ctrl+C` |
| SIGQUIT | 停机 | `kill -QUIT $PID` |
| SIGUSR1 | 平滑重载（不中断连接） | `kill -USR1 $PID` |

> Swoole / Workerman 运行时可能使用各自框架定义的额外信号；自定义信号（如 SIGUSR2）可
> 通过 `SignalHandler` 注册做应用级钩子。

## 注意事项

1. **信号处理器中避免耗时操作** - 信号处理器应该尽快返回
2. **避免死锁** - 信号处理器中不要调用可能阻塞的函数
3. **信号安全函数** - 在信号处理器中只使用异步信号安全函数
4. **多进程注意** - 信号发送给主进程，由主进程分发给子进程

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Signal\SignalHandler;
use Kode\Process\Timer;

// 全局状态
$isShuttingDown = false;

Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4, 'name' => 'SignalDemo'])
    ->on('workerStart', function (int $workerId) use (&$isShuttingDown) {
        $handler = SignalHandler::getInstance();

        // SIGUSR1：应用级配置重载（进程级平滑重载由运行时自动完成）
        $handler->register(SIGUSR1, function () use ($workerId) {
            echo "Worker {$workerId} 重载配置\n";
        });

        // SIGUSR2：打印状态
        $handler->register(SIGUSR2, function () use ($workerId) {
            echo "Worker {$workerId} 内存: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
        });

        // 定时检查关闭状态（SIGTERM/SIGINT 已由运行时优雅停机）
        Timer::add(1, function () use ($workerId, &$isShuttingDown) {
            if ($isShuttingDown) {
                echo "Worker {$workerId} 正在关闭\n";
            }
        });
    })
    ->on('connect', function ($connection) use (&$isShuttingDown) {
        if ($isShuttingDown) {
            $connection->send(json_encode(['type' => 'error', 'message' => '服务器正在关闭']));
            $connection->close();
        }
    })
    ->on('message', function ($connection, $data) use (&$isShuttingDown) {
        if ($isShuttingDown) {
            $connection->send(json_encode(['type' => 'error', 'message' => '服务器正在关闭']));
            $connection->close();
            return;
        }
        $connection->send("收到: {$data}");
    })
    ->start();
```
