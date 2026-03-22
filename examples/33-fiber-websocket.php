<?php

declare(strict_types=1);

/**
 * 示例：Fiber 协程集成 - 高并发 WebSocket 服务
 * 
 * 运行方式：php examples/33-fiber-websocket.php
 */

use Kode\Process\Compat\Worker;
use Kode\Fibers\Fibers;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Fiber 协程 WebSocket 服务 ===\n\n";

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 1;
$worker->name = 'fiber-websocket';

$worker->onWebSocketConnect = function ($connection) {
    echo "新 WebSocket 连接: {$connection->getRemoteAddress()}\n";
    $connection->id = uniqid('fiber_user_');
    $connection->tasks = 0;
};

$worker->onMessage = function ($connection, $data) use ($worker) {
    $connection->tasks++;

    $msg = json_decode($data, true);

    if ($msg === null) {
        $connection->send(json_encode(['error' => 'Invalid JSON']));
        return;
    }

    $type = $msg['type'] ?? 'echo';

    switch ($type) {
        case 'login':
            $connection->nickname = $msg['nickname'] ?? 'Fiber用户';
            broadcast($worker, json_encode([
                'type' => 'system',
                'message' => "{$connection->nickname} 加入了聊天室 (Fiber模式)",
                'time' => date('H:i:s')
            ]));
            break;

        case 'message':
            $nickname = $connection->nickname ?? $connection->id;
            
            Fibers::go(function () use ($connection, $msg, $nickname, $worker) {
                $startTime = microtime(true);
                
                usleep(random_int(1000, 5000));
                
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000, 2);

                broadcast($worker, json_encode([
                    'type' => 'message',
                    'from' => $nickname,
                    'message' => $msg['message'] ?? '',
                    'time' => date('H:i:s'),
                    'fiber_duration_ms' => $duration,
                    'task_count' => $connection->tasks
                ]));
            });
            break;

        case 'batch':
            $count = min(100, $msg['count'] ?? 10);
            $startTime = microtime(true);
            
            $results = Fibers::batch(
                range(1, $count),
                function ($i) {
                    usleep(random_int(100, 500));
                    return "任务 #{$i} 完成";
                },
                10
            );

            $endTime = microtime(true);
            $totalTime = round(($endTime - $startTime) * 1000, 2);

            $connection->send(json_encode([
                'type' => 'batch_result',
                'count' => $count,
                'total_time_ms' => $totalTime,
                'avg_time_ms' => round($totalTime / $count, 2),
                'results' => $results
            ]));
            break;

        case 'ping':
            Fibers::go(function () use ($connection) {
                $connection->send(json_encode([
                    'type' => 'pong',
                    'time' => time(),
                    'fiber' => true
                ]));
            });
            break;
    }
};

$worker->onClose = function ($connection) use ($worker) {
    echo "连接关闭: {$connection->getRemoteAddress()}\n";
    $nickname = $connection->nickname ?? '用户';
    broadcast($worker, json_encode([
        'type' => 'system',
        'message' => "{$nickname} 离开了聊天室",
        'time' => date('H:i:s')
    ]));
};

function broadcast(Worker $worker, string $message): void
{
    foreach ($worker->connections as $connection) {
        $connection->send($message);
    }
}

echo "Fiber WebSocket 服务启动在 0.0.0.0:8080\n";
echo "在浏览器中打开 examples/32-websocket-client.html 测试\n";
echo "按 Ctrl+C 停止服务\n\n";

Worker::runAll();
