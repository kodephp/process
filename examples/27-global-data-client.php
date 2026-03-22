<?php

declare(strict_types=1);

/**
 * 示例：GlobalData 客户端使用
 * 
 * 运行方式：php examples/27-global-data-client.php
 */

use Kode\Process\GlobalData\Client;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== GlobalData 客户端示例 ===\n\n";

$client = new Client('127.0.0.1:2207');

// 设置数据
$client->counter = 0;
$client->users = ['user1', 'user2', 'user3'];
$client->config = [
    'app_name' => 'KodeProcess',
    'version' => '2.3.1',
    'debug' => true
];

echo "设置数据:\n";
echo "  counter = 0\n";
echo "  users = ['user1', 'user2', 'user3']\n";
echo "  config = {...}\n\n";

// 读取数据
echo "读取数据:\n";
echo "  counter = " . json_encode($client->counter) . "\n";
echo "  users = " . json_encode($client->users) . "\n";
echo "  config = " . json_encode($client->config) . "\n\n";

// 原子操作
echo "原子操作:\n";
$client->increment('counter', 1);
echo "  increment(counter, 1) => " . $client->counter . "\n";

$client->increment('counter', 5);
echo "  increment(counter, 5) => " . $client->counter . "\n";

$client->decrement('counter', 2);
echo "  decrement(counter, 2) => " . $client->counter . "\n\n";

// CAS 操作
echo "CAS 操作:\n";
$result = $client->cas('counter', 4, 100);
echo "  cas(counter, 4, 100) => " . ($result ? 'success' : 'failed') . "\n";
echo "  counter = " . $client->counter . "\n\n";

// 检查存在
echo "检查存在:\n";
echo "  isset(counter) => " . (isset($client->counter) ? 'true' : 'false') . "\n";
echo "  isset(nonexistent) => " . (isset($client->nonexistent) ? 'true' : 'false') . "\n\n";

// 获取所有键
echo "获取所有键:\n";
$keys = $client->keys();
echo "  keys() => " . json_encode($keys) . "\n\n";

// 获取统计
echo "获取统计:\n";
$stats = $client->stats();
echo "  stats() => " . json_encode($stats) . "\n\n";

// 删除数据
echo "删除数据:\n";
unset($client->counter);
echo "  unset(counter)\n";
echo "  isset(counter) => " . (isset($client->counter) ? 'true' : 'false') . "\n";

echo "\n完成！\n";
