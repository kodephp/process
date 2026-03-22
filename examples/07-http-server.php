<?php

declare(strict_types=1);

/**
 * 示例 7: 完整的 HTTP 服务器
 *
 * 使用 Master-Worker 架构构建 HTTP 服务器
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Process;

echo "=== 示例 7: 完整的 HTTP 服务器 ===\n";

$routes = [
    '/' => function () {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/html'],
            'body' => '<h1>Hello World from Kode Process!</h1>',
        ];
    },
    '/api' => function () {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['message' => 'Hello API', 'time' => time()]),
        ];
    },
    '/health' => function () {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'status' => 'healthy',
                'workers' => Process::getWorkerCount(),
                'uptime' => time() - Process::getPid(),
            ]),
        ];
    },
];

Process::start([
    'worker_count' => 4,
    'host' => '0.0.0.0',
    'port' => 8080,
], function ($taskId, $request) use ($routes) {
    $path = $request['path'] ?? '/';

    if (isset($routes[$path])) {
        return $routes[$path]($request);
    }

    return [
        'status' => 404,
        'headers' => ['Content-Type' => 'text/plain'],
        'body' => 'Not Found',
    ];
});
