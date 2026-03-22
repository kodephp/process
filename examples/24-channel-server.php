<?php

declare(strict_types=1);

/**
 * 示例：Channel 分布式通讯
 * 
 * 运行方式：
 * 1. 启动 Channel 服务端: php examples/24-channel-server.php
 * 2. 启动 Worker 服务端: php examples/25-channel-client.php
 */

use Kode\Process\Channel\Server;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Channel 分布式通讯服务端 ===\n\n";

$server = new Server('0.0.0.0', 2206);

echo "Channel 服务端启动在 0.0.0.0:2206\n";
echo "按 Ctrl+C 停止服务\n\n";

$server->start();
