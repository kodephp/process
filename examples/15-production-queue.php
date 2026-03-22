<?php

declare(strict_types=1);

/**
 * 示例: 完整的队列 Worker 服务
 *
 * 生产环境可用的队列消费者配置
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Queue\QueueWorker;
use Kode\Process\Response;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "=== 完整队列 Worker 服务 ===\n\n";

$logger = new Logger('queue-worker');
$logger->pushHandler(new StreamHandler(__DIR__ . '/queue-worker.log'));

$worker = QueueWorker::create([
    'worker_count' => 8,
    'max_jobs' => 10000,
    'max_memory' => 256 * 1024 * 1024,
    'timeout' => 60,
    'sleep' => 1,
    'max_tries' => 3,
], $logger);

$worker
    ->queue('default')
    ->on('SendEmail', function (array $data) {
        $to = $data['to'] ?? '';
        $subject = $data['subject'] ?? 'No Subject';

        sleep(1);

        return Response::ok([
            'sent' => true,
            'to' => $to,
            'subject' => $subject,
        ]);
    })
    ->on('ProcessImage', function (array $data) {
        $path = $data['path'] ?? '';

        if (!file_exists($path)) {
            return Response::notFound("文件不存在: {$path}");
        }

        return Response::ok(['processed' => true, 'path' => $path]);
    })
    ->on('GenerateReport', function (array $data) {
        $reportId = $data['report_id'] ?? uniqid();

        sleep(2);

        return Response::ok([
            'generated' => true,
            'report_id' => $reportId,
            'url' => "/reports/{$reportId}.pdf",
        ]);
    })
    ->on('Cleanup', function (array $data) {
        $files = $data['files'] ?? [];

        $deleted = 0;
        foreach ($files as $file) {
            if (file_exists($file) && unlink($file)) {
                $deleted++;
            }
        }

        return Response::ok(['deleted' => $deleted]);
    });

echo "Worker 启动中...\n";
echo "配置:\n";
echo "  - Worker 数量: 8\n";
echo "  - 最大任务数: 10000\n";
echo "  - 内存限制: 256MB\n";
echo "  - 超时时间: 60秒\n\n";

$worker->start();
