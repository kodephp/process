<?php

declare(strict_types=1);

/**
 * 示例 5: 自动伸缩
 *
 * 根据负载自动伸缩 Worker 数量
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;
use Kode\Process\Worker\WorkerPool;

echo "=== 示例 5: 自动伸缩 ===\n";

Process::start([
    'worker_count' => 2,
    'min_workers' => 2,
    'max_workers' => 8,
    'auto_scale' => true,
    'scale_up_threshold' => 0.7,
    'scale_down_threshold' => 0.3,
], function ($taskId, $data) {
    $count = Process::getWorkerCount();
    echo "当前 Worker 数: {$count}\n";
    return ['result' => 'success'];
});
