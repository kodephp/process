# 协议系统

## 协议与运行时支持

`Kode::serve()` 通过地址 scheme 自动选择协议。各运行时支持的协议略有差异：

| 协议 | 地址格式 | Swoole | Workerman |
|------|----------|:------:|:---------:|
| HTTP | `http://0.0.0.0:8080` | ✅ | ✅ |
| WebSocket | `websocket://0.0.0.0:8081` | ✅ | ✅ |
| TCP | `tcp://0.0.0.0:9000` | ✅ | ✅ |
| Text | `text://0.0.0.0:9001` | ✅ | ✅ |
| 自定义长度前缀 | `frame://0.0.0.0:9002` | ✅ | ✅ |
| Unix Socket | `unix:///tmp/app.sock` | ✅ | ✅ |
| SSL | `ssl://0.0.0.0:443` | ✅ | ✅* |
| UDP | `udp://0.0.0.0:9002` | ✅ | ✅ |

> \* SSL 需要 `ext-openssl`。UDP 仅 Swoole / Workerman 运行时支持（本包不自带服务器，运行时二者必有其一）。

## 内置协议

### HTTP 协议

`$request` 是 `Kode\Process\Http\Request`，三个运行时交付的是同一个类，
字段与方法完全一致。完整 API 见 [HTTP 请求对象](request.md)。

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($conn, $request) {
        $method = $request->method();
        $path   = $request->path();          // 已消解 ../ 与重复斜杠
        $page   = $request->get('page', 1);
        $conn->send(json_encode(['code' => 0, 'path' => $path]));
    })
    ->start();
```

请求对象按字段惰性解析：上面这段只碰了 method / path / page，
头部与请求体一个字节都不会被解析。

### WebSocket 协议

```php
use Kode\Process\Kode;

Kode::serve('websocket://0.0.0.0:8081', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```

### Text 协议

```php
use Kode\Process\Kode;

Kode::serve('text://0.0.0.0:9000', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send("收到: {$data}"))
    ->start();
```

### TCP 原始协议

```php
use Kode\Process\Kode;

Kode::serve('tcp://0.0.0.0:9001', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```

### UDP 协议

> 仅 Swoole / Workerman 运行时支持（本包不自带服务器，运行时二者必有其一）。

```php
use Kode\Process\Kode;

Kode::serve('udp://0.0.0.0:9002', ['workers' => 1], 'swoole')
    ->on('message', fn($conn, $data) => $conn->send("UDP: {$data}"))
    ->start();
```

### SSL 协议

通过监听选项的 `ssl` 字段配置，需 `ext-openssl`：

```php
use Kode\Process\Kode;

Kode::serve('ssl://0.0.0.0:443', [
    'workers' => 4,
    'ssl'     => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk'   => '/path/to/key.pem',
    ],
])
->on('message', fn($conn, $data) => $conn->send('Secure response'))
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

Kode::serve('json-nl://0.0.0.0:9000', ['workers' => 4])
    ->on('message', fn($conn, $data) => $conn->send($data))
    ->start();
```
