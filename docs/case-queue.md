# 组合案例：任务队列系统

本文档展示如何使用 Kode Process 构建一个完整的任务队列系统。

## 系统架构

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   API 服务   │────▶│   队列服务   │────▶│  任务执行器  │
└─────────────┘     └─────────────┘     └─────────────┘
       │                   │                   │
       └───────────────────┼───────────────────┘
                           │
                    ┌──────┴──────┐
                    │    Redis    │
                    │   (队列)    │
                    └─────────────┘
```

## 第一步：定义任务类

```php
<?php
// jobs/SendEmailJob.php
namespace Jobs;

use Kode\Process\Queue\Job;
use Kode\Process\Response;

class SendEmailJob extends Job
{
    protected int $maxTries = 3;
    protected int $timeout = 120;
    protected ?string $queue = 'emails';

    public function handle(): Response
    {
        $to = $this->data['to'] ?? '';
        $subject = $this->data['subject'] ?? '';
        $body = $this->data['body'] ?? '';

        // 模拟发送邮件
        $success = $this->sendEmail($to, $subject, $body);

        return $success
            ? Response::ok(['to' => $to, 'sent_at' => date('Y-m-d H:i:s')])
            : Response::error('发送失败');
    }

    public function failed(\Throwable $e): void
    {
        error_log("邮件发送失败: " . $e->getMessage());
    }

    private function sendEmail(string $to, string $subject, string $body): bool
    {
        // 实际发送邮件逻辑
        return mail($to, $subject, $body);
    }
}
```

```php
<?php
// jobs/ProcessImageJob.php
namespace Jobs;

use Kode\Process\Queue\Job;
use Kode\Process\Response;

class ProcessImageJob extends Job
{
    protected int $maxTries = 2;
    protected int $timeout = 300;
    protected ?string $queue = 'images';

    public function handle(): Response
    {
        $path = $this->data['path'] ?? '';
        $width = $this->data['width'] ?? 800;
        $height = $this->data['height'] ?? 600;

        // 处理图片
        $result = $this->resizeImage($path, $width, $height);

        return Response::ok([
            'original' => $path,
            'resized' => $result,
            'size' => [$width, $height]
        ]);
    }

    private function resizeImage(string $path, int $width, int $height): string
    {
        // 实际图片处理逻辑
        $info = pathinfo($path);
        return $info['dirname'] . '/' . $info['filename'] . "_{$width}x{$height}." . $info['extension'];
    }
}
```

## 第二步：创建队列消费者

```php
<?php
// queue-worker.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Queue\QueueWorker;
use Kode\Process\Queue\QueueManager;
use Jobs\SendEmailJob;
use Jobs\ProcessImageJob;

// 使用 Redis 队列
QueueManager::useRedis('127.0.0.1', 6379);

// 注册任务处理器
QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        return SendEmailJob::dispatch($data)->handle();
    })
    ->register('process_image', function (array $data) {
        return ProcessImageJob::dispatch($data)->handle();
    })
    ->register('generate_report', function (array $data) {
        // 生成报表
        $report = generateReport($data['type'], $data['params']);
        return ['status' => 'completed', 'report' => $report];
    });

// 启动消费者
$worker = QueueWorker::create(['worker_count' => 4])
    ->on('send_email', function ($data) {
        echo "处理邮件任务: {$data['to']}\n";
        // 实际处理逻辑
        return ['status' => 'sent'];
    })
    ->on('process_image', function ($data) {
        echo "处理图片任务: {$data['path']}\n";
        // 实际处理逻辑
        return ['status' => 'processed'];
    })
    ->on('generate_report', function ($data) {
        echo "生成报表: {$data['type']}\n";
        // 实际处理逻辑
        return ['status' => 'completed'];
    })
    ->queue('default')
    ->maxJobs(10000)
    ->maxMemory(256 * 1024 * 1024)
    ->start();
```

## 第三步：创建 API 服务

```php
<?php
// api-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Queue\QueueManager;
use Kode\Process\Response;
use Jobs\SendEmailJob;
use Jobs\ProcessImageJob;

// 使用 Redis 队列
QueueManager::useRedis('127.0.0.1', 6379);

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'QueueApi';

$worker->onMessage = function ($connection, $request) {
    $path = $request['path'] ?? '/';
    $method = $request['method'] ?? 'GET';
    $post = $request['post'] ?? [];

    switch ($path) {
        case '/':
            $response = Response::ok([
                'name' => 'Queue API',
                'version' => '1.0.0',
                'queues' => ['emails', 'images', 'reports']
            ]);
            break;

        case '/api/jobs/email':
            if ($method !== 'POST') {
                $response = Response::error('Method not allowed', 405);
                break;
            }

            // 分发邮件任务
            $jobId = QueueManager::getInstance()->dispatch('send_email', [
                'to' => $post['to'] ?? '',
                'subject' => $post['subject'] ?? '',
                'body' => $post['body'] ?? ''
            ]);

            $response = Response::ok([
                'job_id' => $jobId,
                'message' => '任务已加入队列'
            ]);
            break;

        case '/api/jobs/image':
            if ($method !== 'POST') {
                $response = Response::error('Method not allowed', 405);
                break;
            }

            // 分发图片处理任务
            $jobId = QueueManager::getInstance()->dispatch('process_image', [
                'path' => $post['path'] ?? '',
                'width' => (int)($post['width'] ?? 800),
                'height' => (int)($post['height'] ?? 600)
            ]);

            $response = Response::ok([
                'job_id' => $jobId,
                'message' => '任务已加入队列'
            ]);
            break;

        case '/api/jobs/report':
            if ($method !== 'POST') {
                $response = Response::error('Method not allowed', 405);
                break;
            }

            // 分发报表生成任务（延迟执行）
            $jobId = QueueManager::getInstance()->dispatchDelayed('generate_report', [
                'type' => $post['type'] ?? 'daily',
                'params' => $post['params'] ?? []
            ], (int)($post['delay'] ?? 0));

            $response = Response::ok([
                'job_id' => $jobId,
                'message' => '任务已加入队列'
            ]);
            break;

        case '/api/stats':
            // 获取队列统计
            $stats = [
                'emails' => QueueManager::getInstance()->stats('emails'),
                'images' => QueueManager::getInstance()->stats('images'),
                'default' => QueueManager::getInstance()->stats('default')
            ];

            $response = Response::ok($stats);
            break;

        default:
            $response = Response::error('Not Found', 404);
    }

    $connection->send($response->toJson());
};

Worker::runAll();
```

## 第四步：创建定时任务

```php
<?php
// scheduler.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;
use Kode\Process\Crontab\Crontab;
use Kode\Process\Queue\QueueManager;

QueueManager::useRedis('127.0.0.1', 6379);

$worker = new Worker('text://0.0.0.0:9000');
$worker->count = 1;
$worker->name = 'Scheduler';

$worker->onWorkerStart = function ($worker) {
    // 每分钟检查待执行任务
    Timer::add(60, function () {
        checkScheduledTasks();
    });

    // 每天凌晨生成日报
    new Crontab('0 0 * * *', function () {
        QueueManager::getInstance()->dispatch('generate_report', [
            'type' => 'daily',
            'params' => ['date' => date('Y-m-d', strtotime('-1 day'))]
        ]);
        echo "已调度日报生成任务\n";
    });

    // 每周一 9 点生成周报
    new Crontab('0 9 * * 1', function () {
        QueueManager::getInstance()->dispatch('generate_report', [
            'type' => 'weekly',
            'params' => ['start' => date('Y-m-d', strtotime('-7 days'))]
        ]);
        echo "已调度周报生成任务\n";
    });

    // 每月 1 号生成月报
    new Crontab('0 0 1 * *', function () {
        QueueManager::getInstance()->dispatch('generate_report', [
            'type' => 'monthly',
            'params' => ['month' => date('Y-m', strtotime('-1 month'))]
        ]);
        echo "已调度月报生成任务\n";
    });

    echo "调度器已启动\n";
};

function checkScheduledTasks() {
    // 检查数据库中的待执行任务
    // $tasks = fetchScheduledTasks();
    // foreach ($tasks as $task) {
    //     QueueManager::getInstance()->dispatch($task['type'], $task['data']);
    // }
}

Worker::runAll();
```

## 第五步：启动脚本

```bash
# start.sh
#!/bin/bash

# 启动队列消费者
php queue-worker.php start -d

# 启动 API 服务
php api-server.php start -d

# 启动调度器
php scheduler.php start -d

echo "队列系统已启动"
```

## 使用示例

### 发送邮件

```bash
curl -X POST http://localhost:8080/api/jobs/email \
  -d "to=user@example.com" \
  -d "subject=Hello" \
  -d "body=This is a test email"
```

### 处理图片

```bash
curl -X POST http://localhost:8080/api/jobs/image \
  -d "path=/uploads/image.jpg" \
  -d "width=800" \
  -d "height=600"
```

### 生成报表

```bash
curl -X POST http://localhost:8080/api/jobs/report \
  -d "type=daily" \
  -d "delay=60"
```

### 查看统计

```bash
curl http://localhost:8080/api/stats
```

## 总结

本案例展示了：

1. **任务定义** - Job 类封装任务逻辑
2. **队列消费者** - 多进程消费任务
3. **API 服务** - 接收任务请求
4. **定时调度** - Crontab 定时任务
5. **延迟任务** - 延迟执行功能
