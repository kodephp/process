# 队列系统

集成 `kode/queue` 包，提供任务队列功能。

## 快速开始

### 注册任务处理器

```php
use Kode\Process\Queue\QueueManager;

QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        // 发送邮件
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    })
    ->register('process_image', function (array $data) {
        // 处理图片
        $result = resizeImage($data['path'], $data['width'], $data['height']);
        return ['status' => 'processed', 'path' => $result];
    });
```

### 分发任务

```php
use Kode\Process\Queue\QueueManager;

// 立即执行
QueueManager::getInstance()->dispatch('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);

// 延迟执行（10 秒后）
QueueManager::getInstance()->dispatchDelayed('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Delayed',
    'body' => 'Message'
], 10);
```

## 任务类

### 定义任务类

```php
use Kode\Process\Queue\Job;
use Kode\Process\Response;

class SendEmailJob extends Job
{
    protected int $maxTries = 3;      // 最大重试次数
    protected int $timeout = 120;     // 超时时间（秒）
    protected int $delay = 0;         // 延迟执行（秒）
    protected ?string $queue = 'emails';  // 指定队列

    public function handle(): Response
    {
        $to = $this->data['to'] ?? '';
        $subject = $this->data['subject'] ?? '';
        $body = $this->data['body'] ?? '';

        $success = mail($to, $subject, $body);

        return $success
            ? Response::ok(['to' => $to])
            : Response::error('发送失败');
    }
    
    public function failed(\Throwable $e): void
    {
        // 任务失败回调
        error_log("邮件发送失败: " . $e->getMessage());
    }
}
```

### 分发任务类

```php
// 基本分发
SendEmailJob::dispatch([
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);

// 链式调用
SendEmailJob::dispatch($data)
    ->onQueue('emails')
    ->delay(60)      // 延迟 60 秒
    ->tries(5);      // 重试 5 次
```

## 队列消费者

### 创建消费者

```php
use Kode\Process\Queue\QueueWorker;

$worker = QueueWorker::create(['worker_count' => 4])
    ->on('send_email', function ($data) {
        return ['status' => 'sent'];
    })
    ->on('process_image', function ($data) {
        return ['status' => 'processed'];
    })
    ->queue('default')
    ->maxJobs(10000)             // 处理 10000 个任务后重启
    ->maxMemory(128 * 1024 * 1024)  // 内存限制 128MB
    ->start();
```

### 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Queue\QueueWorker;
use Kode\Process\Queue\QueueManager;

// 注册处理器
QueueManager::getInstance()
    ->register('send_email', function ($data) {
        echo "发送邮件到: {$data['to']}\n";
        return ['status' => 'sent'];
    });

// 启动消费者
$worker = QueueWorker::create(['worker_count' => 4])
    ->on('send_email', function ($data) {
        // 处理邮件
        return ['status' => 'sent'];
    })
    ->queue('default');

$worker->start();
```

## 队列适配器

### 内存队列（开发测试）

```php
use Kode\Process\Queue\QueueManager;

QueueManager::useMemory();
```

### Redis 队列（生产环境）

```php
use Kode\Process\Queue\QueueManager;

QueueManager::useRedis('127.0.0.1', 6379, 'password', 0);
```

### 自定义适配器

```php
use Kode\Process\Queue\QueueManager;
use Kode\Process\Queue\Adapters\QueueAdapterInterface;

class MyAdapter implements QueueAdapterInterface
{
    public function push(string $queue, array $payload): bool {}
    public function pop(string $queue): ?array {}
    public function size(string $queue): int {}
    public function clear(string $queue): bool {}
}

QueueManager::setAdapter(new MyAdapter());
```

## 队列统计

```php
use Kode\Process\Queue\QueueManager;

// 获取统计信息
$stats = QueueManager::getInstance()->stats('default');
// ['waiting' => 10, 'delayed' => 5, 'reserved' => 2, 'failed' => 1]

// 获取队列大小
$size = QueueManager::getInstance()->size('default');

// 清空队列
QueueManager::getInstance()->clear('default');
```

## 队列优先级

```php
// 高优先级队列
QueueManager::getInstance()->dispatch('urgent_task', $data)
    ->onQueue('high');

// 普通队列
QueueManager::getInstance()->dispatch('normal_task', $data)
    ->onQueue('default');

// 低优先级队列
QueueManager::getInstance()->dispatch('low_task', $data)
    ->onQueue('low');
```

## 失败处理

```php
use Kode\Process\Queue\QueueManager;

// 获取失败任务
$failed = QueueManager::getInstance()->getFailed('default');

// 重试失败任务
QueueManager::getInstance()->retry($jobId);

// 删除失败任务
QueueManager::getInstance()->forget($jobId);
```

## 完整示例

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Queue\QueueManager;
use Kode\Process\Queue\QueueWorker;
use Kode\Process\Compat\Worker;

// 使用 Redis 队列
QueueManager::useRedis('127.0.0.1', 6379);

// 注册任务处理器
QueueManager::getInstance()
    ->register('send_email', function ($data) {
        $success = mail($data['to'], $data['subject'], $data['body']);
        return ['success' => $success];
    })
    ->register('process_image', function ($data) {
        $result = processImage($data['path']);
        return ['result' => $result];
    });

// 创建队列消费者
$worker = new Worker('text://0.0.0.0:9000');
$worker->count = 4;
$worker->name = 'QueueWorker';

$worker->onWorkerStart = function () {
    // 启动队列消费
    QueueWorker::create()
        ->on('send_email', function ($data) {
            return ['status' => 'sent'];
        })
        ->start();
};

$worker->onMessage = function ($connection, $data) {
    // 分发任务
    QueueManager::getInstance()->dispatch('send_email', [
        'to' => 'user@example.com',
        'subject' => 'Hello',
        'body' => $data
    ]);
    
    $connection->send('ok');
};

Worker::runAll();
```
