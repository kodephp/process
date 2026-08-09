# 协议系统

## 协议与运行时支持

`Kode::serve()` 通过地址 scheme 自动选择协议。各运行时支持的协议略有差异：

| 协议 | 地址格式 | Native | Swoole | Workerman |
|------|----------|:------:|:------:|:---------:|
| HTTP | `http://0.0.0.0:8080` | ✅ | ✅ | ✅ |
| WebSocket | `websocket://0.0.0.0:8081` | ✅ | ✅ | ✅ |
| TCP | `tcp://0.0.0.0:9000` | ✅ | ✅ | ✅ |
| Text | `text://0.0.0.0:9001` | ✅ | ✅ | ✅ |
| 自定义长度前缀 | `frame://0.0.0.0:9002` | ✅ | ✅ | ✅ |
| Unix Socket | `unix:///tmp/app.sock` | ✅ | ✅ | ✅ |
| SSL | `ssl://0.0.0.0:443` | ✅* | ✅ | ✅* |
| UDP | `udp://0.0.0.0:9002` | ✅ | ✅ | ✅ |

> \* SSL 需要 `ext-openssl`（Swoole 还需编译时开启）。
> Native 运行时（零扩展、纯 PHP 8.3+）同样自带 UDP / HTTP / WebSocket 等服务器，无需依赖 Swoole / Workerman；三种运行时均支持上述全部协议。

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

> Native / Swoole / Workerman 三种运行时均自带 UDP 服务器，无需额外依赖。

```php
use Kode\Process\Kode;

Kode::serve('udp://0.0.0.0:9002', ['workers' => 1])
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

## 协议层安全约束

协议解析直接面对不受信任的网络输入，因此内置协议在 v5.2.12 起统一按规范做了严格校验，
`input()` 返回 `-1`（协议错误）即由运行时断开连接。

### HTTP 请求

| 校验 | 行为 |
|------|------|
| 同时出现 `Content-Length` 与 `Transfer-Encoding` | 拒绝（RFC 9112 §6.1，防请求走私） |
| 单独出现 `Transfer-Encoding` | 拒绝（本包不支持 chunked **请求体**；chunked **响应**照常支持） |
| `Content-Length` 非纯数字（负号、`+`、空格、字母） | 拒绝 |
| 多个 `Content-Length` 且取值不一致 | 拒绝（取值完全相同则接受） |

响应侧：所有响应头在写出前会剔除名与值中的 `\r`、`\n`、`\0`，
杜绝用户可控数据（`Location`、`Set-Cookie` 等）导致的 CRLF 注入 / 响应拆分。

### WebSocket 帧

按 RFC 6455 拒绝以下客户端帧（关闭连接）：

- 未设置 `MASK` 位的帧（客户端必须掩码）
- 控制帧（close / ping / pong）负载 > 125 字节，或被分片（`FIN = 0`）
- 未协商扩展却置位 `RSV1/2/3`
- 64 位扩展长度最高位为 1（负长度），或超过 `MAX_PAYLOAD_LENGTH`

大帧跨多次 TCP 读到达时按「基础头 → 扩展长度 → 掩码键 → 负载」分阶段判定，
任一阶段字节不足只等待，不丢帧（此前 > 64KB 的分包帧会被丢弃）。

### `frame://` 长度前缀协议

> **⚠️ 行为变更（v5.2.12）**：`LengthPrefix::decode()` 默认不再还原任何类
> （等价 `unserialize($raw, ['allowed_classes' => false])`）。
> 该协议的报文完全由对端控制，默认放开类还原等同于把对象注入面暴露给网络。

确需跨端传对象时显式声明白名单：

```php
use Kode\Process\Protocol\LengthPrefix;

LengthPrefix::setAllowedClasses([\App\Dto\Order::class]);   // 仅还原白名单内的类
LengthPrefix::setAllowedClasses(false);                     // 恢复默认：不还原任何类
```

未声明白名单时，对端发来的对象会解成 `__PHP_Incomplete_Class`，数组 / 标量不受影响。

### HTTP/2

见 [HTTP/2 · 解析层健壮性](http2.md)。HPACK 侧新增：变长整数位移上限与截断检测
（此前超长 varint 会整数溢出成负索引，静默产出 `[NULL, NULL]` 头部）、
Huffman 截断码字抛 `Http2Exception` 而非 `ArithmeticError`（此前 2 字节即可打死 worker）、
解压后头列表体积在**解码过程中**逐步累计校验（此前 62KB 输入可展开成数百 MB）。

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
