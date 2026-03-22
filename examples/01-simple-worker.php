<?php

declare(strict_types=1);

/**
 * 示例 1: 最简单的 Worker 池
 *
 * 3 行代码启动 4 个 Worker 进程
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;

echo "=== 示例 1: 最简单的 Worker 池 ===\n";

Process::start([
    'worker_count' => 4,
], function ($taskId, $data) {
    echo "Worker 处理任务: {$taskId}\n";
    sleep(1);
    return ['result' => 'success', 'task_id' => $taskId];
});
