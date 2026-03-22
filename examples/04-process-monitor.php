<?php

declare(strict_types=1);

/**
 * 示例 4: 进程监控
 *
 * 监控进程健康状态、资源使用
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;
use Kode\Process\Monitor\ProcessMonitor;

echo "=== 示例 4: 进程监控 ===\n";

$monitor = new ProcessMonitor();

$monitor->setMaxMemoryUsage(256 * 1024 * 1024);
$monitor->setMaxCpuUsage(80.0);

$monitor->onUnhealthy(function ($pid, $status) {
    echo "进程 {$pid} 不健康: " . json_encode($status) . "\n";
});

$monitor->onRestart(function ($pid) {
    echo "重启进程: {$pid}\n";
});

Process::startMonitor();

Process::start([
    'worker_count' => 3,
], function ($taskId, $data) {
    $health = Process::checkHealth();
    echo "健康状态: " . json_encode($health) . "\n";
    return ['result' => 'success'];
});
