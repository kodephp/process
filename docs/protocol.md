# 协议系统

## 内置协议

### HTTP 协议

```php
use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($conn, $request) {
        $method = $request['method'];
        $path = $request['path'];
        $conn->send(json_encode(['code' => 0, 'path' => $path]));
    })
    ->start();
```

### WebSocket 协议

```php
use Kode\Process\Kode;

Kode::worker('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

### Text 协议

```php
use Kode\Process\Kode;

Kode::worker('text://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

### TCP 原始协议

```php
use Kode\Process\Kode;

Kode::worker('tcp://0.0.0.0:9001', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```

### UDP 协议

```php
use Kode\Process\Kode;

Kode::worker('udp://0.0.0.0:9002', 1)
    ->onMessage(fn($conn, $data) => $conn->send("UDP: {$data}"))
    ->start();
```

### SSL 协议

```php
use Kode\Process\Kode;

Kode::app([
    'worker_count' => 4,
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
    ]
])
->listen('ssl://0.0.0.0:443')
->onMessage(fn($conn, $data) => $conn->send('Secure response'))
->start();
```

## 自定义协议

所有协议必须实现 `ProtocolInterface`：

```php
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
        return $pos === false ? 0 : $pos + 1;
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

## 协议注册

```php
use Kode\Process\Protocol\ProtocolFactory;

ProtocolFactory::register('json-nl', JsonNLProtocol::class);

Kode::worker('json-nl://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();
```
