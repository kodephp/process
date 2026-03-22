<?php

declare(strict_types=1);

/**
 * 示例：GlobalData 全局数据共享
 * 
 * 运行方式：
 * 1. 启动服务端: php examples/26-global-data-server.php
 * 2. 启动客户端: php examples/27-global-data-client.php
 */

use Kode\Process\GlobalData\Server;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== GlobalData 全局数据共享服务端 ===\n\n";

$server = new Server('0.0.0.0', 2207);

echo "GlobalData 服务端启动在 0.0.0.0:2207\n";
echo "按 Ctrl+C 停止服务\n\n";

$server->start();
