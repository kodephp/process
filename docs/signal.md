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

$handler = new SignalHandler();

// 注册 SIGINT 处理器
$handler->on(SIGINT, function () {
    echo "收到 SIGINT 信号，准备退出\n";
    exit(0);
});

// 注册 SIGTERM 处理器
$handler->on(SIGTERM, function () {
    echo "收到 SIGTERM 信号，优雅关闭\n";
    gracefulShutdown();
});

// 注册 SIGHUP 处理器（重载配置）
$handler->on(SIGHUP, function () {
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

## 在 Worker 中使用

### 示例：优雅关闭

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Signal\SignalHandler;

$worker = new Worker('tcp://0.0.0.0:8080');
$worker->count = 4;

$worker->onWorkerStart = function ($worker) {
    $handler = new SignalHandler();
    
    // 注册 SIGTERM 处理器
    $handler->on(SIGTERM, function () use ($worker) {
        echo "Worker {$worker->id} 收到终止信号\n";
        
        // 停止接受新连接
        $worker->pauseAccept();
        
        // 等待现有连接处理完成
        $timeout = 30;
        $start = time();
        
        while (count($worker->connections) > 0 && (time() - $start) < $timeout) {
            sleep(1);
        }
        
        // 关闭所有连接
        foreach ($worker->connections as $conn) {
            $conn->close();
        }
        
        // 退出进程
        exit(0);
    });
};

$worker->onMessage = function ($connection, $data) {
    $connection->send('Hello');
};

Worker::runAll();
```

### 示例：热重载

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Signal\SignalHandler;

$worker = new Worker('http://0.0.0.0:8080');

$worker->onWorkerStart = function ($worker) {
    $handler = new SignalHandler();
    
    // SIGUSR1 触发代码重载
    $handler->on(SIGUSR1, function () use ($worker) {
        echo "Worker {$worker->id} 重载代码\n";
        
        // 清除 OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // 重载业务代码
        $files = glob(__DIR__ . '/src/*.php');
        foreach ($files as $file) {
            require_once $file;
        }
        
        echo "代码重载完成\n";
    });
};

Worker::runAll();
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

本包使用信号控制进程：

```bash
# 启动服务
php http_server.php

# 停止（发送 SIGTERM）
kill -TERM $PID

# 平滑重载（发送 SIGHUP）
kill -HUP $PID

# 查看状态（发送 SIGUSR2）
kill -USR2 $PID
```

## 信号说明

| 信号 | 说明 | 操作 |
|------|------|------|
| SIGTERM | 优雅停止 | `kill -TERM $PID` |
| SIGINT | 优雅停止 | `Ctrl+C` |
| SIGQUIT | 强制停止 | `kill -QUIT $PID` |
| SIGHUP | 平滑重载 | `kill -HUP $PID` |
| SIGUSR1 | 平滑重载 | `kill -USR1 $PID` |
| SIGUSR2 | 打印状态 | `kill -USR2 $PID` |

## 注意事项

1. **信号处理器中避免耗时操作** - 信号处理器应该尽快返回
2. **避免死锁** - 信号处理器中不要调用可能阻塞的函数
3. **信号安全函数** - 在信号处理器中只使用异步信号安全函数
4. **多进程注意** - 信号发送给主进程，由主进程分发给子进程

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Signal\SignalHandler;
use Kode\Process\Compat\Timer;

$worker = new Worker('tcp://0.0.0.0:9000');
$worker->count = 4;
$worker->name = 'SignalDemo';

// 全局状态
$isShuttingDown = false;

$worker->onWorkerStart = function ($worker) use (&$isShuttingDown) {
    $handler = new SignalHandler();
    
    // SIGTERM: 优雅关闭
    $handler->on(SIGTERM, function () use ($worker, &$isShuttingDown) {
        echo "Worker {$worker->id} 开始优雅关闭\n";
        $isShuttingDown = true;
        
        // 通知所有连接
        foreach ($worker->connections as $conn) {
            $conn->send(json_encode(['type' => 'shutdown', 'message' => '服务器即将关闭']));
        }
        
        // 30秒后强制退出
        Timer::add(30, function () use ($worker) {
            echo "Worker {$worker->id} 强制退出\n";
            exit(0);
        }, [], false);
    });
    
    // SIGUSR1: 重载配置
    $handler->on(SIGUSR1, function () use ($worker) {
        echo "Worker {$worker->id} 重载配置\n";
        // 重载配置逻辑
    });
    
    // SIGUSR2: 打印状态
    $handler->on(SIGUSR2, function () use ($worker) {
        echo "Worker {$worker->id} 状态:\n";
        echo "  连接数: " . count($worker->connections) . "\n";
        echo "  内存: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
    });
    
    // 定时检查关闭状态
    Timer::add(1, function () use ($worker, &$isShuttingDown) {
        if ($isShuttingDown && count($worker->connections) === 0) {
            echo "Worker {$worker->id} 所有连接已关闭，退出\n";
            exit(0);
        }
    });
};

$worker->onConnect = function ($connection) use (&$isShuttingDown) {
    if ($isShuttingDown) {
        $connection->send(json_encode(['type' => 'error', 'message' => '服务器正在关闭']));
        $connection->close();
    }
};

$worker->onMessage = function ($connection, $data) {
    $connection->send("收到: {$data}");
};

Worker::runAll();
```
