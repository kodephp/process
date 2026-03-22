<?php

declare(strict_types=1);

/**
 * 示例 3: IPC 通信
 *
 * Master 和 Worker 之间使用 Socket IPC 通信
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;

echo "=== 示例 3: IPC 通信 ===\n";

$ipc = Process::createIpc('socket');

Process::start([
    'worker_count' => 2,
], function ($taskId, $data) use ($ipc) {
    echo "Worker 收到任务: " . json_encode($data) . "\n";

    $result = ['task_id' => $taskId, 'status' => 'done'];

    $ipc->sendTo(Process::getPid(), $result);

    return $result;
});
