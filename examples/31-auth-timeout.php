<?php

declare(strict_types=1);

/**
 * 示例：连接认证 - 超时关闭未认证连接
 * 
 * 运行方式：php examples/31-auth-timeout.php start
 */

use Kode\Process\Compat\Worker;
use Kode\Process\Auth\ConnectionAuth;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== 连接认证示例 ===\n\n";

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 1;
$worker->name = 'auth-server';

// 创建认证管理器，设置 30 秒超时
$auth = ConnectionAuth::getInstance();
$auth->setTimeout(30);

// 认证成功回调
$auth->onAuth(function ($connection) {
    echo "连接 {$connection->id} 认证成功\n";
    $connection->send(json_encode([
        'type' => 'auth',
        'status' => 'success',
        'message' => '认证成功'
    ]));
});

// 超时回调
$auth->onTimeout(function ($connection) {
    echo "连接 {$connection->id} 认证超时，已关闭\n";
});

$worker->onConnect = function ($connection) use ($auth) {
    // 注册未认证连接
    $auth->register($connection);

    $connection->send(json_encode([
        'type' => 'auth_required',
        'message' => '请在 30 秒内完成认证',
        'timeout' => 30
    ]));

    echo "新连接 {$connection->id}，等待认证...\n";
};

$worker->onMessage = function ($connection, $data) use ($auth) {
    $msg = json_decode($data, true);

    if ($msg === null) {
        $connection->send(json_encode(['error' => 'Invalid JSON']));
        return;
    }

    // 检查是否已认证
    if (!$auth->isAuthenticated($connection)) {
        // 处理认证请求
        if (($msg['type'] ?? '') === 'auth') {
            $token = $msg['token'] ?? '';

            // 验证 token（示例：简单的 token 验证）
            if (validateToken($token)) {
                $auth->authenticate($connection);
                $connection->userId = $msg['user_id'] ?? 'unknown';
            } else {
                $connection->send(json_encode([
                    'type' => 'auth',
                    'status' => 'failed',
                    'message' => 'Token 无效'
                ]));
            }
            return;
        }

        // 未认证时拒绝其他请求
        $connection->send(json_encode([
            'error' => '请先完成认证'
        ]));
        return;
    }

    // 已认证，处理业务请求
    switch ($msg['type'] ?? '') {
        case 'ping':
            $connection->send(json_encode(['type' => 'pong', 'time' => time()]));
            break;

        case 'message':
            $connection->send(json_encode([
                'type' => 'message',
                'content' => $msg['content'] ?? '',
                'user' => $connection->userId,
                'time' => date('H:i:s')
            ]));
            break;

        default:
            $connection->send(json_encode(['error' => 'Unknown message type']));
    }
};

$worker->onClose = function ($connection) use ($auth) {
    echo "连接 {$connection->id} 已关闭\n";
};

// 简单的 token 验证函数
function validateToken(string $token): bool
{
    // 实际应用中应该验证 JWT 或查询数据库
    return !empty($token) && strlen($token) >= 10;
}

Worker::runAll();
