<?php

declare(strict_types=1);

/**
 * 示例 8: 与 Kode Fibers 集成
 *
 * 在 Worker 进程内使用 Fiber 协程
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;

echo "=== 示例 8: 与 Kode Fibers 集成 ===\n";

Process::start([
    'worker_count' => 4,
], function ($taskId, $data) {
    if (class_exists('Kode\\Fibers\\Fibers')) {
        echo "使用 Kode Fibers 协程处理任务\n";
        $fibers = Kode\Fibers\Fibers::concurrent([
            fn() => fetchData('https://api.example.com/users'),
            fn() => fetchData('https://api.example.com/products'),
            fn() => fetchData('https://api.example.com/orders'),
        ]);
        return ['result' => $fibers];
    }

    echo "Kode Fibers 未安装，使用同步方式\n";
    return ['result' => 'sync'];
});

function fetchData(string $url): array
{
    return ['url' => $url, 'data' => 'mock data'];
}
