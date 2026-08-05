# 队列系统

集成 `kode/queue` 包（^2.1），在进程侧提供**处理器注册 + 消费**封装：底层保持 kode/queue 的不可变消息对象、可见性超时与至少一次投递语义，本层只负责把 `ReservedJob` 路由到已注册的处理器并完成 `ack` / `fail`。

> 切换 kode/queue 2.x 的破坏性变更（本库已吸收）：`Factory` 移除改为 `QueueManager::make()/auto()`；`QueueInterface` 迁移到 `Kode\Queue\Contract\QueueInterface`；`pop()` 返回 `ReservedJob` 对象；`stats()` 返回 `QueueStats` 值对象。

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

处理器签名：`function (array $payload, ReservedJob $reserved): mixed`。返回值即任务结果；抛出异常会被捕获并标记为失败。

### 分发任务

```php
use Kode\Process\Queue\QueueManager;

QueueManager::getInstance()->dispatch('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);

// 延迟分发（秒）
QueueManager::getInstance()->dispatchDelayed('send_email', $data, delay: 60);

// 批量分发（每条需含 job / name 键）
QueueManager::getInstance()->dispatchBulk([
    ['job' => 'send_email', 'data' => ['to' => 'a@b.com']],
    ['job' => 'send_email', 'data' => ['to' => 'c@d.com']],
]);
```

### 消费任务

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Queue\QueueManager;

$qm = QueueManager::useMemory();
$qm->register('send_email', fn(array $data) => ['status' => 'sent']);

while (true) {
    $resp = $qm->process();   // 队列为空返回 null
    if ($resp === null) {
        break;
    }
    // $resp 是 Kode\Process\Response：isSuccess() / isError() / isNotFound() / $resp->data
}
```

- `process(?string $queue, float $timeout)` — 阻塞/非阻塞拉取一条并处理，返回 `Response` 或 `null`。
- `processBatch(?string $queue, int $limit, float $timeout)` — 批量处理，返回实际处理条数。
- `consume(?string $queue, ?int $limit, float $timeout)` — 以生成器方式持续消费。

> 没有注册处理器的任务会被直接判失败（避免无限重投），`process()` 返回 `Response::notFound(...)`。

## 队列适配器

### 内存队列（开发 / 测试 / 压测，无需外部服务）

```php
QueueManager::useMemory();
```

### 同步队列（投递即执行，便于本地调试）

```php
QueueManager::useSync();
```

### Redis 队列（生产推荐）

```php
QueueManager::useRedis('127.0.0.1', 6379, 'password', 0);
// 或自定义连接：withDriver(DriverType::Redis, ['host' => ..., 'port' => ..., ...])
```

### 数据库 / 其他驱动

```php
QueueManager::useDatabase(['dsn' => 'mysql:host=...', 'username' => ..., 'password' => ..., 'table' => 'jobs']);
QueueManager::driverAvailable(\Kode\Queue\Enum\DriverType::Redis); // 该驱动当前是否可用
```

驱动类型见 `Kode\Queue\Enum\DriverType`（Redis / Database / Beanstalkd / Amqp / Kafka / Memory / Sync / Null）。

## 统计与运维

```php
$qm = QueueManager::getInstance();

$size  = $qm->size('default');          // 当前待处理条数
$stats = $qm->stats('default');         // 数组：driver / size / ready / delayed / reserved / failed / total ...
$cleared = $qm->clear('default');       // 清空并返回清除条数

$qm->supports(\Kode\Queue\Enum\Capability::Delay); // 当前驱动是否支持延迟
$qm->diagnose();                        // 驱动自检信息
```

失败的任务由 kode/queue 的失败存储接管；`stats()['failed']` 可观察失败计数，具体失败原因与重试由 kode/queue 完成。消费方法在处理器抛异常时返回 `Response::error($message)`（若任务仍可重试则自动重新入队）。

## 在 Worker 中使用

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Queue\QueueManager;

QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    });

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($connection, $request) {
        $jobId = QueueManager::getInstance()->dispatch('send_email', [
            'to' => 'user@example.com',
            'subject' => 'Hello',
            'body' => 'World'
        ]);

        $connection->send(json_encode([
            'code' => 0,
            'job_id' => $jobId
        ]));
    })
    ->start();
```
