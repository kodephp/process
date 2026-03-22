# SSL/TLS 支持

SSL/TLS 用于加密网络通信，保护数据安全。

## 快速开始

### 创建 SSL 上下文

```php
use Kode\Process\Ssl\SslContext;

// 从证书文件创建
$ssl = SslContext::fromFiles('/path/to/cert.pem', '/path/to/key.pem');

// 或从目录创建（自动查找 cert.pem 和 key.pem）
$ssl = SslContext::fromPath('/etc/ssl/certs');
```

### 启动 SSL 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Ssl\SslContext;

// 创建 SSL 上下文
$ssl = SslContext::fromFiles('/path/to/cert.pem', '/path/to/key.pem');

// 创建 SSL 服务器
$worker = new Worker('ssl://0.0.0.0:443', [
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
        'verify_peer' => false,
    ]
]);

$worker->onMessage = function ($connection, $data) {
    $connection->send('Secure Hello');
};

Worker::runAll();
```

## 配置选项

```php
$ssl = SslContext::fromFiles($certFile, $keyFile);

// 设置验证选项
$ssl->setVerifyPeer(true)       // 验证对端证书
    ->setVerifyHost(true)       // 验证主机名
    ->allowSelfSigned(false)    // 是否允许自签名证书
    ->setCiphers('HIGH:!aNULL:!MD5');  // 设置加密套件

// 设置 CA 证书
$ssl->setCaFile('/path/to/ca.pem');

// 获取上下文
$context = $ssl->getContext();
```

## WebSocket over SSL (WSS)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;

// WSS 协议
$worker = new Worker('websocket://0.0.0.0:443', [
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
    ]
]);

$worker->onMessage = function ($connection, $data) {
    $connection->send($data);
};

Worker::runAll();
```

## 客户端连接

```php
// 创建 SSL Socket
$socket = $ssl->createServerSocket('0.0.0.0', 443);

// 或创建客户端连接
$socket = $ssl->createClientSocket('example.com', 443);
```

## 证书生成（开发环境）

```bash
# 生成私钥
openssl genrsa -out key.pem 2048

# 生成证书签名请求
openssl req -new -key key.pem -out csr.pem

# 生成自签名证书（开发用）
openssl x509 -req -days 365 -in csr.pem -signkey key.pem -out cert.pem

# 或一步生成
openssl req -x509 -newkey rsa:2048 -keyout key.pem -out cert.pem -days 365 -nodes
```

## Let's Encrypt 免费证书

```bash
# 安装 certbot
sudo apt install certbot

# 获取证书
sudo certbot certonly --standalone -d yourdomain.com

# 证书路径
# /etc/letsencrypt/live/yourdomain.com/fullchain.pem
# /etc/letsencrypt/live/yourdomain.com/privkey.pem
```

## 完整示例：HTTPS 服务器

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\Response;

// HTTPS 服务器
$worker = new Worker('http://0.0.0.0:443', [
    'ssl' => [
        'local_cert' => '/etc/letsencrypt/live/example.com/fullchain.pem',
        'local_pk' => '/etc/letsencrypt/live/example.com/privkey.pem',
        'verify_peer' => false,
    ]
]);

$worker->name = 'HttpsServer';
$worker->count = 4;

$worker->onMessage = function ($connection, $request) {
    $path = $request['path'] ?? '/';
    
    switch ($path) {
        case '/':
            $response = Response::ok([
                'message' => 'Welcome to HTTPS Server',
                'secure' => true
            ]);
            break;
            
        case '/api/status':
            $response = Response::ok([
                'status' => 'running',
                'ssl' => $connection->isSsl()
            ]);
            break;
            
        default:
            $response = Response::error('Not Found', 404);
    }
    
    $connection->send($response->toJson());
};

Worker::runAll();
```

## 注意事项

1. **证书路径** - 确保证书文件路径正确且有读取权限
2. **端口 443** - 需要 root 权限或设置端口转发
3. **证书更新** - Let's Encrypt 证书 90 天过期，需要自动续期
4. **性能影响** - SSL/TLS 会增加 CPU 开销
