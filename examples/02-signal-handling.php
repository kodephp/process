<?php

declare(strict_types=1);

/**
 * 示例 2: 信号处理
 *
 * 演示如何处理 SIGTERM、SIGINT 等信号
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;
use Kode\Process\Signal;

echo "=== 示例 2: 信号处理 ===\n";
echo "按 Ctrl+C 停止，或发送 SIGUSR1 重载配置\n\n";

Process::onSignal(Signal::TERM, function () {
    echo "\n收到 SIGTERM，准备优雅关闭...\n";
});

Process::onSignal(Signal::INT, function () {
    echo "\n收到 SIGINT (Ctrl+C)，准备优雅关闭...\n";
});

Process::onSignal(Signal::USR1, function () {
    echo "\n收到 SIGUSR1，重载配置...\n";
});

Process::onSignal(Signal::USR2, function () {
    echo "\n收到 SIGUSR2，打印状态...\n";
    print_r(Process::getStatus());
});

Process::start([
    'worker_count' => 2,
], function ($taskId, $data) {
    echo "Worker 处理任务: {$taskId}\n";
    return ['result' => 'success'];
});
