# UDP 服务

UDP 是无连接的传输层协议，适用于实时性要求高的场景。

## 特点

- **无连接** - 不需要建立连接，直接发送数据
- **快速** - 没有 TCP 的握手开销
- **不可靠** - 不保证数据到达和顺序
- **轻量** - 协议头只有 8 字节

## 适用场景

- 实时音视频
- 游戏
- DNS 查询
- 日志收集
- 心跳检测

## 创建 UDP 服务器

### 方式一：使用 Worker

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;

$worker = new Worker('udp://0.0.0.0:9292');
$worker->count = 1;  // UDP 通常使用单进程

$worker->onMessage = function ($connection, $data) {
    echo "收到数据: {$data}\n";
    $connection->send("已收到: {$data}");
};

Worker::runAll();
```

### 方式二：使用 UdpServer

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Server\UdpServer;

$server = new UdpServer('0.0.0.0:9292');

$server->onMessage(function ($client, $data) {
    echo "收到来自 {$client->getAddress()} 的数据: {$data}\n";
    $client->send("回复: {$data}");
});

$server->start();
```

## UDP 客户端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Server\UdpClient;

$client = new UdpClient('127.0.0.1:9292');

// 发送数据
$client->send('Hello UDP Server');

// 接收数据
$response = $client->recv(5);  // 5 秒超时
echo "服务器回复: {$response}\n";
```

## 完整示例：DNS 代理

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Server\UdpServer;

// DNS 代理服务器
$server = new UdpServer('0.0.0.0:53');

$server->onMessage(function ($client, $data) {
    // 解析 DNS 查询
    $query = parseDnsQuery($data);
    echo "DNS 查询: {$query['domain']}\n";
    
    // 转发到上游 DNS
    $response = queryUpstreamDns('8.8.8.8:53', $data);
    
    // 返回结果
    $client->send($response);
});

function parseDnsQuery($data) {
    // 简化解析，实际需要完整解析 DNS 协议
    $domain = '';
    $len = ord($data[12]);
    $pos = 13;
    while ($len > 0) {
        $domain .= substr($data, $pos, $len) . '.';
        $pos += $len;
        $len = ord($data[$pos++]);
    }
    return ['domain' => rtrim($domain, '.')];
}

function queryUpstreamDns($server, $data) {
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_sendto($socket, $data, strlen($data), 0, '8.8.8.8', 53);
    socket_recvfrom($socket, $response, 512, 0, $ip, $port);
    socket_close($socket);
    return $response;
}

$server->start();
```

## 完整示例：日志收集

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Server\UdpServer;

// 日志收集服务器
$server = new UdpServer('0.0.0.0:514');

// 日志文件
$logFile = '/var/log/udp-logs.log';
$fp = fopen($logFile, 'a');

$server->onMessage(function ($client, $data) use ($fp) {
    $log = json_decode($data, true);
    
    if ($log) {
        $line = sprintf(
            "[%s] [%s] [%s] %s\n",
            $log['timestamp'] ?? date('Y-m-d H:i:s'),
            $log['level'] ?? 'INFO',
            $log['source'] ?? 'unknown',
            $log['message'] ?? $data
        );
        
        fwrite($fp, $line);
        echo $line;
    }
});

// 定期刷新文件
register_shutdown_function(function () use ($fp) {
    fclose($fp);
});

$server->start();
```

## 客户端发送日志

```php
<?php
// 客户端发送日志
function sendLog($level, $message, $source = 'app') {
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    
    $log = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level,
        'source' => $source,
        'message' => $message
    ]);
    
    socket_sendto($socket, $log, strlen($log), 0, '127.0.0.1', 514);
    socket_close($socket);
}

sendLog('INFO', '用户登录成功', 'auth-service');
sendLog('ERROR', '数据库连接失败', 'db-service');
```

## UDP vs TCP

| 特性 | UDP | TCP |
|------|-----|-----|
| 连接 | 无连接 | 面向连接 |
| 可靠性 | 不可靠 | 可靠 |
| 顺序 | 不保证 | 保证 |
| 速度 | 快 | 较慢 |
| 适用 | 实时、广播 | 文件、网页 |

## 注意事项

1. **数据大小** - UDP 数据包最大 65535 字节，实际建议不超过 1472 字节（MTU）
2. **丢包处理** - 应用层需要处理丢包和重传
3. **单进程** - UDP 通常使用单进程，多进程可能导致数据竞争
4. **安全性** - UDP 没有 SSL/TLS 支持，敏感数据需要应用层加密
