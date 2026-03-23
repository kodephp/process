# SSL/TLS 支持

SSL/TLS 用于加密网络通信，保护数据安全。

## 快速开始

### 创建 SSL 上下文

```php
use Kode\Process\Ssl\SslContext;

$ssl = SslContext::fromFiles('/path/to/cert.pem', '/path/to/key.pem');
```

### 启动 SSL 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Ssl\SslContext;

$ssl = SslContext::fromFiles('/path/to/cert.pem', '/path/to/key.pem');

Kode::app([
    'worker_count' => 4,
    'ssl' => $ssl,
])
->listen('ssl://0.0.0.0:443')
->onMessage(fn($conn, $data) => $conn->send('Secure Hello'))
->start();
```

## WebSocket over SSL (WSS)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Ssl\SslContext;

$ssl = SslContext::fromFiles('/path/to/cert.pem', '/path/to/key.pem');

Kode::app([
    'worker_count' => 4,
    'ssl' => $ssl,
])
->listen('wss://0.0.0.0:443')
->onMessage(fn($conn, $data) => $conn->send($data))
->start();
```

## 证书生成（开发环境）

```bash
openssl req -x509 -newkey rsa:2048 -keyout key.pem -out cert.pem -days 365 -nodes
```

## Let's Encrypt 免费证书

```bash
sudo certbot certonly --standalone -d yourdomain.com
```

证书路径：`/etc/letsencrypt/live/yourdomain.com/fullchain.pem`

## 完整示例：HTTPS 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\Response;

Kode::app([
    'worker_count' => 4,
    'ssl' => [
        'local_cert' => '/etc/letsencrypt/live/example.com/fullchain.pem',
        'local_pk' => '/etc/letsencrypt/live/example.com/privkey.pem',
    ]
])
->listen('https://0.0.0.0:443')
->onMessage(function ($conn, $request) {
    $path = $request['path'] ?? '/';
    $response = Response::ok(['message' => 'Welcome', 'secure' => true]);
    $conn->send($response->toJson());
})
->start();
```

## 注意事项

1. **证书路径** - 确保证书文件路径正确且有读取权限
2. **端口 443** - 需要 root 权限或设置端口转发
3. **证书更新** - Let's Encrypt 证书 90 天过期，需要自动续期
