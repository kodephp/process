# 队列系统

集成 `kode/queue` 包，提供任务队列功能。

## 快速开始

### 注册任务处理器

```php
use Kode\Process\Queue\QueueManager;

QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    })
    ->register('process_image', function (array $data) {
        $result = resizeImage($data['path'], $data['width'], $data['height']);
        return ['status' => 'processed', 'path' => $result];
    });
```

### 分发任务

```php
use Kode\Process\Queue\QueueManager;

QueueManager::getInstance()->dispatch('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);
```

## 任务类

```php
use Kode\Process\Queue\Job;

class SendEmailJob extends Job
{
    protected int $maxTries = 3;
    protected int $timeout = 120;

    public function handle(): mixed
    {
        $to = $this->data['to'] ?? '';
        $subject = $this->data['subject'] ?? '';
        $body = $this->data['body'] ?? '';

        $success = mail($to, $subject, $body);
        return $success ? ['status' => 'sent'] : ['status' => 'failed'];
    }
}

SendEmailJob::dispatch([
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);
```

## 队列消费者

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Queue\QueueManager;
use Kode\Process\Kode;

QueueManager::getInstance()
    ->register('send_email', fn($data) => ['status' => 'sent']);

Kode::worker('text://0.0.0.0:9000', 4)
    ->onMessage(function ($conn, $data) {
        QueueManager::getInstance()->dispatch('send_email', [
            'to' => $data,
            'subject' => 'Hello',
            'body' => 'World'
        ]);
        $conn->send('ok');
    })
    ->start();
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

## 队列统计

```php
$stats = QueueManager::getInstance()->stats('default');
$size = QueueManager::getInstance()->size('default');
```

## 失败处理

```php
$failed = QueueManager::getInstance()->getFailed('default');
QueueManager::getInstance()->retry($jobId);
QueueManager::getInstance()->forget($jobId);
```
