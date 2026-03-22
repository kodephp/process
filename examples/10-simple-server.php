<?php

declare(strict_types=1);

/**
 * 简洁示例 1: 使用 Server 类启动 Worker 池
 *
 * 类似 Workerman/Swoole 的简洁 API
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Server;

echo "=== 简洁示例: Server 类 ===\n";

Server::create([
    'worker_count' => 4,
])
->onTask(function ($taskId, $data) {
    echo "处理任务: {$taskId}\n";
    return ['result' => 'success'];
})
->onWorkerStart(function () {
    echo "Worker 进程已启动\n";
})
->onMasterStart(function () {
    echo "Master 进程已启动\n";
})
->onReload(function () {
    echo "重新加载配置\n";
})
->start();
