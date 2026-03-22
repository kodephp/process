# 定时器

Timer 定时器在当前进程中运行，不会创建新的进程或线程。

## 基本用法

### 永久定时器

```php
use Kode\Process\Compat\Timer;

// 每 2.5 秒执行一次
$timerId = Timer::add(2.5, function () {
    echo "定时任务执行\n";
});

// 等效方式
$timerId = Timer::forever(1.0, function () {
    echo "每秒执行\n";
});
```

### 一次性定时器

```php
// 10 秒后执行一次
Timer::add(10, function () {
    echo "执行一次\n";
}, [], false);  // 第 4 个参数 false 表示只执行一次

// 等效方式
Timer::once(10, function () {
    echo "10秒后执行\n";
});
```

### 带参数的定时器

```php
Timer::add(5, function ($to, $content) {
    echo "发送邮件到: {$to}\n";
    echo "内容: {$content}\n";
}, ['user@example.com', 'Hello']);
```

### 指定执行次数

```php
// 执行 5 次
Timer::repeat(1.0, function ($count) {
    echo "第 {$count} 次执行\n";
}, 5);
```

### 立即执行

```php
Timer::immediate(function () {
    echo "立即执行\n";
});
```

## 删除定时器

```php
// 删除指定定时器
Timer::del($timerId);

// 删除所有定时器
Timer::delAll();
```

## 暂停和恢复

```php
// 暂停
Timer::pause($timerId);

// 恢复
Timer::resume($timerId);
```

## Cron 表达式

```php
use Kode\Process\Crontab\Crontab;

// 每分钟执行
new Crontab('* * * * *', function () {
    echo "每分钟执行\n";
});

// 每小时第 30 分钟执行
new Crontab('30 * * * *', function () {
    echo "每小时 30 分执行\n";
});

// 每天 8:30 执行
new Crontab('30 8 * * *', function () {
    echo "早上 8:30 执行\n";
});

// 每周一 9:00 执行
new Crontab('0 9 * * 1', function () {
    echo "周一 9:00 执行\n";
});

// 销毁
$crontab->destroy();
```

## 在 Worker 中使用

### 在 onWorkerStart 中设置全局定时器

```php
use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;

$worker = new Worker('tcp://0.0.0.0:8080');

$worker->onWorkerStart = function ($worker) {
    // 只在第一个进程执行（避免重复）
    if ($worker->id === 0) {
        // 每分钟清理过期数据
        Timer::add(60, function () {
            cleanupExpiredData();
        });
        
        // 每小时统计
        Timer::add(3600, function () {
            generateStatistics();
        });
    }
};

Worker::runAll();
```

### 在连接中使用定时器

```php
$worker->onConnect = function ($connection) {
    // 每 30 秒发送心跳
    $connection->heartbeatTimer = Timer::add(30, function () use ($connection) {
        $connection->send(json_encode(['type' => 'ping']));
    });
    
    // 60 秒超时检测
    $connection->timeoutTimer = Timer::add(60, function () use ($connection) {
        $connection->close('timeout');
    });
};

$worker->onMessage = function ($connection, $data) {
    // 收到消息，重置超时定时器
    Timer::del($connection->timeoutTimer);
    $connection->timeoutTimer = Timer::add(60, function () use ($connection) {
        $connection->close('timeout');
    });
    
    // 处理消息
    $connection->send('ok');
};

$worker->onClose = function ($connection) {
    // 清理定时器
    Timer::del($connection->heartbeatTimer);
    Timer::del($connection->timeoutTimer);
};
```

## 定时器统计

```php
// 获取定时器统计
$stats = Timer::getStats();
// ['count' => 10, 'next_run' => 1234567890]
```

## 注意事项

1. **只能在回调中添加定时器** - 推荐在 `onWorkerStart` 中设置
2. **繁重任务会阻塞** - 建议放到单独的 Worker 进程
3. **多进程注意并发** - 判断 `$worker->id` 避免重复执行
4. **定时器不能跨进程删除** - 只能删除当前进程的定时器
5. **定时器 ID 可能重复** - 不同进程的 ID 可能相同

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;

$worker = new Worker('tcp://0.0.0.0:9000');
$worker->count = 4;

$worker->onWorkerStart = function ($worker) {
    // 只在主进程执行全局任务
    if ($worker->id === 0) {
        // 每分钟清理
        Timer::add(60, function () {
            echo "[" . date('H:i:s') . "] 清理任务\n";
        });
        
        // 每天 0 点重置
        new \Kode\Process\Crontab\Crontab('0 0 * * *', function () {
            echo "每日重置\n";
        });
    }
    
    // 每个进程的心跳
    Timer::add(10, function () use ($worker) {
        echo "Worker {$worker->id} 心跳\n";
    });
};

$worker->onMessage = function ($connection, $data) {
    $connection->send("收到: {$data}");
};

Worker::runAll();
```
