<?php

declare(strict_types=1);

/**
 * 示例 6: 守护进程模式
 *
 * 以后台守护进程方式运行
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;

echo "=== 示例 6: 守护进程模式 ===\n";
echo "进程将以后台方式运行，PID 文件在: /tmp/kode-process.pid\n\n";

Process::start([
    'worker_count' => 4,
    'daemonize' => true,
    'pid_file' => '/tmp/kode-process.pid',
    'log_file' => '/tmp/kode-process.log',
    'user' => null,
    'group' => null,
], function ($taskId, $data) {
    return ['result' => 'success'];
});
