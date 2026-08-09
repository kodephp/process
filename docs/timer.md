# 定时器

Timer 定时器在当前进程中运行，不会创建新的进程或线程。

> ⚠️ **Timer 是手动驱动的**：必须在主循环中周期性调用 `Timer::tick()`（或 `Kode::tickTimers()`），
> 否则注册的定时器**永远不会触发**。它与 Reactor 的 `addTimer()` 是两套独立机制——
> 后者由事件循环自动驱动，前者适合自定义主循环、批处理进程，或需要 `pause/resume` 与 cron 的场景。
>
> 在 `Kode::serve()` 这类由运行时接管事件循环的服务器中，用运行时的定时器间接驱动即可：
> ```php
> // 每 0.1s 驱动一次 Timer，足以覆盖亚秒级定时任务
> $reactor->addTimer(0.1, fn() => Timer::tick());
> ```
> 若拿不到 Reactor 实例，就在自己的循环里直接调用 `Timer::tick()`。

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
// 第 3 个参数为执行次数，达到后自动停止并移除
$count = 0;
Timer::repeat(1.0, function () use (&$count) {
    $count++;
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

Kode::serve('tcp://0.0.0.0:8080', ['workers' => 4])
    ->on('workerStart', function (int $workerId) {
        if ($workerId === 0) {
            Timer::add(60, fn() => cleanupExpiredData());
            Timer::add(3600, fn() => generateStatistics());
        }
    })
    ->on('message', fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Timer;

Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('workerStart', function (int $workerId) {
        if ($workerId === 0) {
            Timer::add(60, fn() => print "[" . date('H:i:s') . "] 清理任务\n");
        }

        Timer::add(10, fn() => print "Worker {$workerId} 心跳\n");
    })
    ->on('message', fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

## 注意事项

1. **必须手动驱动** - 见开头说明：周期性调用 `Timer::tick()`，否则定时器不触发
2. **异常不会静默丢失** - 回调抛异常时若已通过 `Timer::onError($cb)` 注册监听器则交给它，
   否则以 PHP 警告形式上报（便于故障排查）；`onError` 在首个定时器进程内注册一次即可
3. **只能在回调中添加定时器** - 推荐在 `workerStart` 事件中设置
4. **繁重任务会阻塞** - 建议放到单独的 Worker 进程
5. **多进程注意并发** - 判断 `workerStart` 回调的 `$workerId` 避免重复执行
6. **fork 后需重置** - 用 `pcntl_fork()` 派生子进程后，子进程应调用 `Timer::reset()`
   清除从父进程继承的定时器，避免父子共享同一静态状态
