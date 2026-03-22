# 协议系统

## 内置协议

### HTTP 协议

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->onMessage = function ($connection, $request) {
    // $request 是解析后的请求数组
    $method = $request['method'];      // GET, POST, etc.
    $path = $request['path'];          // /api/users
    $query = $request['query'];        // ['id' => 1]
    $headers = $request['headers'];    // ['Host' => 'localhost']
    $body = $request['body'];          // POST 数据
    
    $connection->send(json_encode([
        'code' => 0,
        'data' => ['message' => 'ok']
    ]));
};
```

### WebSocket 协议

```php
$worker = new Worker('websocket://0.0.0.0:8081');
$worker->onMessage = function ($connection, $data) {
    // $data 已经解码
    $connection->send($data);
};
```

### Text 协议

文本 + 换行符，适合简单的文本通信。

```php
$worker = new Worker('text://0.0.0.0:9000');
$worker->onMessage = function ($connection, $data) {
    // $data 不包含换行符
    $connection->send("收到: {$data}");
};
```

### TCP 原始协议

```php
$worker = new Worker('tcp://0.0.0.0:9001');
$worker->onMessage = function ($connection, $data) {
    // $data 是原始字节流
    $connection->send($data);
};
```

### UDP 协议

```php
$worker = new Worker('udp://0.0.0.0:9002');
$worker->onMessage = function ($connection, $data) {
    $connection->send("UDP: {$data}");
};
```

### SSL 协议

```php
$context = [
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
        'verify_peer' => false,
    ]
];

$worker = new Worker('ssl://0.0.0.0:443', $context);
$worker->onMessage = function ($connection, $data) {
    $connection->send('Secure response');
};
```

## 自定义协议

### 协议接口

所有协议必须实现 `ProtocolInterface`：

```php
interface ProtocolInterface
{
    /**
     * 获取协议名称
     */
    public static function getName(): string;

    /**
     * 检查包完整性
     * 返回 0: 需要更多数据
     * 返回 -1: 协议错误
     * 返回 > 0: 完整包长度
     */
    public static function input(string $buffer, mixed $connection = null): int;

    /**
     * 编码（发送时调用）
     */
    public static function encode(mixed $data, mixed $connection = null): string;

    /**
     * 解码（接收时调用）
     */
    public static function decode(string $buffer, mixed $connection = null): mixed;
}
```

### 示例：JsonNL 协议

JSON + 换行符协议：

```php
<?php
namespace App\Protocol;

use Kode\Process\Protocol\ProtocolInterface;

class JsonNLProtocol implements ProtocolInterface
{
    public static function getName(): string
    {
        return 'json-nl';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, "\n");
        
        if ($pos === false) {
            return 0;  // 需要更多数据
        }
        
        return $pos + 1;  // 返回包长
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        return json_decode(trim($buffer), true);
    }
}
```

使用：

```php
$worker = new Worker('JsonNL://0.0.0.0:9000');
// 或
$worker->protocol = JsonNLProtocol::class;
```

### 示例：长度前缀协议

首部 4 字节标记包长度：

```php
<?php
namespace App\Protocol;

use Kode\Process\Protocol\ProtocolInterface;

class LengthPrefixProtocol implements ProtocolInterface
{
    private const HEAD_LEN = 4;
    private const MAX_SIZE = 10485760;  // 10MB

    public static function getName(): string
    {
        return 'length-prefix';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return 0;
        }

        $data = unpack('Nlen', $buffer);

        if ($data === false || $data['len'] < self::HEAD_LEN || $data['len'] > self::MAX_SIZE) {
            return -1;  // 协议错误
        }

        return strlen($buffer) < $data['len'] ? 0 : $data['len'];
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        $body = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        return pack('N', self::HEAD_LEN + strlen($body)) . $body;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $body = substr($buffer, self::HEAD_LEN);
        
        $json = json_decode($body, true);
        return $json !== null ? $json : $body;
    }
}
```

### 示例：二进制协议

```php
<?php
namespace App\Protocol;

use Kode\Process\Protocol\ProtocolInterface;

class BinaryProtocol implements ProtocolInterface
{
    public static function getName(): string
    {
        return 'binary';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        // 假设格式: [2字节类型][2字节长度][数据]
        if (strlen($buffer) < 4) {
            return 0;
        }

        $header = unpack('ntype/nlen', $buffer);
        $totalLen = 4 + $header['len'];

        return strlen($buffer) < $totalLen ? 0 : $totalLen;
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        $type = $data['type'] ?? 0;
        $payload = $data['payload'] ?? '';
        
        return pack('nn', $type, strlen($payload)) . $payload;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $header = unpack('ntype/nlen', $buffer);
        
        return [
            'type' => $header['type'],
            'payload' => substr($buffer, 4, $header['len'])
        ];
    }
}
```

## 协议注册

```php
use Kode\Process\Protocol\ProtocolFactory;

// 注册自定义协议
ProtocolFactory::register('my-protocol', MyProtocol::class);

// 使用
$worker = new Worker('my-protocol://0.0.0.0:9000');
```
