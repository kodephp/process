<?php

declare(strict_types=1);

use Kode\Process\Async\EventEmitter;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== EventEmitter 事件发射器示例 ===\n\n";

$emitter = new EventEmitter();

// 监听用户登录事件
$emitter->on('user.login', function (array $user) {
    echo "[事件] 用户登录: {$user['name']} (ID: {$user['id']})\n";
});

// 监听用户登出事件
$emitter->on('user.logout', function (array $user) {
    echo "[事件] 用户登出: {$user['name']}\n";
});

// 一次性监听 - 只执行一次
$emitter->once('app.ready', function () {
    echo "[事件] 应用已就绪（只触发一次）\n";
});

// 前置监听 - 最先执行
$emitter->prependListener('user.login', function (array $user) {
    echo "[前置] 准备处理用户登录: {$user['name']}\n";
});

// 发射事件
echo ">>> 发射 user.login 事件\n";
$emitter->emit('user.login', [['id' => 1, 'name' => '张三']]);

echo "\n>>> 发射 app.ready 事件（第一次）\n";
$emitter->emit('app.ready');

echo "\n>>> 发射 app.ready 事件（第二次 - 不会触发）\n";
$emitter->emit('app.ready');

echo "\n>>> 发射 user.logout 事件\n";
$emitter->emit('user.logout', [['id' => 1, 'name' => '张三']]);

// 检查监听器
echo "\n>>> 监听器统计\n";
echo "user.login 监听器数量: " . $emitter->listenerCount('user.login') . "\n";
echo "所有事件: " . implode(', ', $emitter->eventNames()) . "\n";

// 移除监听器
$callback = function () {
    echo "这个不会被调用\n";
};
$emitter->on('test', $callback);
$emitter->off('test', $callback);
echo "\n>>> 移除监听器后 test 事件监听数: " . $emitter->listenerCount('test') . "\n";

echo "\n=== 示例完成 ===\n";
