<?php

declare(strict_types=1);

/**
 * 示例 9: 任务队列 Worker
 *
 * 使用 Worker 池处理后台任务队列
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;

echo "=== 示例 9: 任务队列 Worker ===\n";

$taskQueue = new class {
    private array $tasks = [];

    public function push(string $type, array $data): string
    {
        $taskId = uniqid('task_');
        $this->tasks[$taskId] = [
            'id' => $taskId,
            'type' => $type,
            'data' => $data,
            'created_at' => time(),
        ];
        return $taskId;
    }

    public function pop(): ?array
    {
        return array_pop($this->tasks);
    }

    public function count(): int
    {
        return count($this->tasks);
    }
};

Process::start([
    'worker_count' => 4,
    'max_requests' => 10000,
], function ($taskId, $data) use ($taskQueue) {
    $task = $taskQueue->pop();

    if (!$task) {
        usleep(100000);
        return null;
    }

    echo "处理任务: {$task['type']} ({$task['id']})\n";

    $result = match ($task['type']) {
        'send_email' => sendEmail($task['data']),
        'process_image' => processImage($task['data']),
        'generate_report' => generateReport($task['data']),
        default => ['status' => 'unknown_type'],
    };

    return $result;
});

function sendEmail(array $data): array
{
    sleep(1);
    return ['status' => 'email_sent', 'to' => $data['to']];
}

function processImage(array $data): array
{
    sleep(2);
    return ['status' => 'image_processed', 'path' => $data['path']];
}

function generateReport(array $data): array
{
    sleep(3);
    return ['status' => 'report_generated', 'report_id' => $data['report_id']];
}
