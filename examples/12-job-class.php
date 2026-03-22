<?php

declare(strict_types=1);

/**
 * 示例: 使用 Job 类定义任务
 *
 * 类似 Laravel Queue 的任务定义方式
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Queue\Job;
use Kode\Process\Queue\Consumer;
use Kode\Process\Response;

class SendEmailJob extends Job
{
    public function handle(): Response
    {
        $to = $this->data['to'] ?? '';
        $subject = $this->data['subject'] ?? '';

        echo "发送邮件到: {$to}, 主题: {$subject}\n";

        return Response::ok(['sent' => true, 'to' => $to]);
    }
}

class ProcessImageJob extends Job
{
    protected int $maxTries = 5;

    protected int $timeout = 120;

    public function handle(): Response
    {
        $path = $this->data['path'] ?? '';

        echo "处理图片: {$path}\n";

        return Response::ok(['processed' => true, 'path' => $path]);
    }
}

echo "=== Job 类示例 ===\n\n";

Consumer::create(['worker_count' => 4])
    ->on(SendEmailJob::class, fn($data, $job) => (new SendEmailJob($data))->handle())
    ->on(ProcessImageJob::class, fn($data, $job) => (new ProcessImageJob($data))->handle())
    ->start();
