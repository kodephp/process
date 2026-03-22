<?php

declare(strict_types=1);

/**
 * 示例：SSL/TLS 加密服务
 * 
 * 运行方式：php examples/29-ssl-server.php
 * 
 * 需要准备证书文件：
 * - server.pem (证书)
 * - server.key (私钥)
 * 
 * 生成自签名证书命令:
 *   mkdir -p examples/certs
 *   openssl req -x509 -newkey rsa:2048 -keyout examples/certs/server.key -out examples/certs/server.pem -days 365 -nodes
 */

use Kode\Process\Compat\Worker;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== SSL/TLS 加密服务 ===\n\n";

$certFile = __DIR__ . '/certs/server.pem';
$keyFile = __DIR__ . '/certs/server.key';

if (!file_exists($certFile) || !file_exists($keyFile)) {
    echo "警告: 证书文件不存在，请先生成证书\n";
    echo "生成自签名证书命令:\n";
    echo "  mkdir -p examples/certs\n";
    echo "  openssl req -x509 -newkey rsa:2048 -keyout examples/certs/server.key -out examples/certs/server.pem -days 365 -nodes\n\n";
    exit(1);
}

$worker = new Worker('http://0.0.0.0:443', [
    'ssl' => [
        'local_cert' => $certFile,
        'local_pk' => $keyFile,
        'allow_self_signed' => true,
        'verify_peer' => false,
    ]
]);

$worker->transport = 'ssl';
$worker->name = 'https-server';
$worker->count = 1;

$worker->onMessage = function ($connection, $data) {
    $response = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: application/json\r\n";
    $response .= "Connection: close\r\n\r\n";
    $response .= json_encode([
        'status' => 'ok',
        'message' => 'Hello from HTTPS server!',
        'time' => date('Y-m-d H:i:s'),
        'ssl' => true
    ]);

    $connection->send($response);
};

echo "HTTPS 服务启动在 0.0.0.0:443\n";
echo "访问: https://localhost/\n";
echo "按 Ctrl+C 停止服务\n\n";

Worker::runAll();
