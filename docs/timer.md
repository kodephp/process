# 定时器

Timer 定时器在当前进程中运行，不会创建新的进程或线程。

## 基本用法

### 永久定时器

```php
use Kode\Process\Timer;

$timerId = Timer::add(2.5, function () {
    echo "定时任务执行\n";
});

$timerId = Timer::forever(1.0, function () {
    echo "每秒执行\n";
});
```

### 一次性定时器

```php
Timer::add(10, function () {
    echo "执行一次\n";
}, [], false);

Timer::once(10, function () {
    echo "10秒后执行\n";
});
```

### 带参数的定时器

```php
Timer::add(5, function ($to, $content) {
    echo "发送邮件到: {$to}\n";
}, ['user@example.com', 'Hello']);
```

### 指定执行次数

```php
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
Timer::del($timerId);
Timer::delAll();
```

## 暂停和恢复

```php
Timer::pause($timerId);
Timer::resume($timerId);
```

## Cron 表达式

```php
use Kode\Process\Crontab\Crontab;

new Crontab('* * * * *', fn() => print "每分钟执行\n");
new Crontab('30 8 * * *', fn() => print "早上 8:30 执行\n");
```

## 在 Worker 中使用

```php
use Kode\Process\Kode;
use Kode\Process\Timer;

Kode::worker('tcp://0.0.0.0:8080', 4)
    ->onWorkerStart(function ($worker) {
        if ($worker->id === 0) {
            Timer::add(60, fn() => cleanupExpiredData());
            Timer::add(3600, fn() => generateStatistics());
        }
    })
    ->onMessage(fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Timer;

Kode::worker('tcp://0.0.0.0:9000', 4)
    ->onWorkerStart(function ($worker) {
        if ($worker->id === 0) {
            Timer::add(60, fn() => print "[" . date('H:i:s') . "] 清理任务\n");
        }

        Timer::add(10, fn() => print "Worker {$worker->id} 心跳\n");
    })
    ->onMessage(fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

## 注意事项

1. **只能在回调中添加定时器** - 推荐在 `onWorkerStart` 中设置
2. **繁重任务会阻塞** - 建议放到单独的 Worker 进程
3. **多进程注意并发** - 判断 `$worker->id` 避免重复执行
