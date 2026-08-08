# HTTP 请求对象

HTTP 场景下，`on('message')` 的第二个参数是 `Kode\Process\Http\Request`。

**三个运行时交付的是同一个类。** 无论底层跑的是 Native、Swoole 还是 Workerman，
业务拿到的类型、方法、字段名、头部大小写完全一致——这是「切换运行时业务代码零改动」
在 HTTP 场景下真正成立的前提。

```php
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($conn, $request) {
        // 这段代码在 native / swoole / workerman 上逐字节等价
        $conn->send(json_encode([
            'method' => $request->method(),
            'path'   => $request->path(),
            'page'   => $request->get('page', 1),
            'token'  => $request->bearerToken(),
        ]));
    })
    ->start();
```

---

## 为什么要有这一层

在 5.2.0 之前，请求随运行时而变：

| 运行时 | 交付类型 | `$request['path']` |
|--------|----------|--------------------|
| Native | `array` | 正常 |
| Swoole | `Swoole\Http\Request` | **Fatal error** |
| Workerman | `Workerman\Protocols\Http\Request` | **Fatal error** |

同一份 handler 换个运行时就崩。现在三者统一到 `Kode\Process\Http\Request`，
并由 `RuntimeRequestContractTest` 在每次 CI 上用真实服务逐字段比对锁定，
适配层一旦退化立刻失败。

---

## 惰性解析

构造 Request **不做任何解析**，只记住数据来源。请求行、头部、查询串、
表单体各自独立求值，谁被访问才解析谁，且只解析一次。

```php
$server->on('message', fn ($conn, $req) => $conn->send('Hello'));
// 上面这个 handler 完全不碰请求 —— 于是一个字节都不会被解析
```

运行时内部判定 gzip / keep-alive / h2c 时走的是 `rawHeader()`：
直接在原始报文上做一次定向 `strpos`，找到那一行就返回，不构建头部数组。
所以「契约更丰富」和「吞吐更高」在这里不是取舍关系。

---

## 头部大小写

`headers()` 返回的键**一律小写**：

```php
$request->headers();          // ['host' => '...', 'content-type' => '...']
```

这不是随意选择。HTTP/2（RFC 7540 §8.1.2）强制小写字段名，Swoole 与 Workerman
也都返回小写，统一到小写是三方唯一的公共解。

访问器本身大小写不敏感，业务写哪种都取得到：

```php
$request->header('Content-Type');   // ✅
$request->header('CONTENT-TYPE');   // ✅
$request->header('content-type');   // ✅
```

> **从 5.1.x 升级**：如果代码里写了 `$request['headers']['Content-Type']`，
> 改成 `$request->header('Content-Type')`。其余数组写法不受影响。

---

## 路径规范化

`path()` 返回消解过 `.` / `..` 与重复斜杠的路径（RFC 3986 §5.2.4）：

```
/api/../api/v1/./items   →  /api/v1/items
/static/../../etc/passwd →  /etc/passwd
//a///b                  →  /a/b
/a/b/                    →  /a/b/     （结尾斜杠语义保留）
```

穿越串在到达路由之前就被拍平，业务再怎么拼路径也拼不出上级目录。
需要客户端原样发来的路径用 `rawPath()`。

---

## API

### 请求行

| 方法 | 说明 |
|------|------|
| `method(): string` | `GET` / `POST` / … |
| `isMethod(string $m): bool` | 大小写不敏感比较 |
| `uri(): string` | 完整请求目标，含查询串 |
| `path(): string` | 规范化路径 |
| `rawPath(): string` | 未规范化的原始路径 |
| `protocol(): string` | `HTTP/1.1` / `HTTP/2` |
| `queryString(): string` | `?` 之后的原始串 |
| `scheme(): string` | `http` / `https` |
| `isSecure(): bool` | 等价 `scheme() === 'https'` |
| `streamId(): int` | HTTP/2 流 ID，1.x 恒为 0 |

### 头部

| 方法 | 说明 |
|------|------|
| `headers(): array` | 全部头部，键小写 |
| `header(string $n, ?string $default = null): ?string` | 单个头部，名字大小写不敏感 |
| `hasHeader(string $n): bool` | |
| `rawHeader(string $n): string` | 定向扫描，不触发整块头部解析（热路径用） |
| `contentType(): string` | |
| `contentLength(): int` | |
| `host(): string` | |
| `userAgent(): string` | |
| `isAjax(): bool` | `X-Requested-With: XMLHttpRequest` |
| `isJson(): bool` | Content-Type 含 `json` |
| `bearerToken(): ?string` | `Authorization: Bearer xxx` 中的 token |
| `ip(bool $trustProxy = false): string` | 见下方安全说明 |

### 参数与请求体

| 方法 | 说明 |
|------|------|
| `get(?string $k = null, mixed $d = null)` | 查询参数，不传 key 返回全部 |
| `post(?string $k = null, mixed $d = null)` | 表单参数（urlencoded 与 JSON 体） |
| `input(string $k, mixed $d = null)` | 先查表单再查查询串 |
| `all(): array` | 两者合并，表单优先 |
| `has(string $k): bool` | |
| `body(): string` / `rawBody(): string` | 原始请求体 |
| `json(bool $assoc = true): mixed` | 非法 JSON 返回 `null`，不抛异常 |
| `cookies(): array` / `cookie(string $k, mixed $d = null)` | |
| `files(): array` | Swoole / Workerman 直取；Native 暂不解析 multipart |

### 附加数据与逃生舱

| 方法 | 说明 |
|------|------|
| `attribute(string $k, mixed $d = null)` | 中间件之间传值 |
| `setAttribute(string $k, mixed $v): self` | 可链式 |
| `attributes(): array` | |
| `toArray(): array` | 展开为旧版数组结构（会强制解析全部字段） |
| `raw(): string` | 原始报文；非 1.x 来源按字段重建 |
| `native(): ?object` | 底层框架的原始请求对象 |

---

## 安全默认值

**反向代理头默认不采信。** `X-Forwarded-For` / `X-Real-IP` 是客户端可以随便伪造的，
不在可信网络里拿它做鉴权就是漏洞。所以 `ip()` 默认只返回直连地址：

```php
$request->ip();       // 直连地址
$request->ip(true);   // 明确表示「我在可信反代后面」才采信 XFF
```

**头部条数上限。** 超过 `Request::MAX_HEADERS`（128）的头部被丢弃，
避免头部洪水撑爆内存。

**JSON 解析不抛异常。** `json()` 先 `json_validate()` 再 decode，
非法输入返回 `null`，不会让一个畸形请求打穿 handler。

**路径穿越前置拍平。** 见上文「路径规范化」。

---

## 兼容旧的数组写法

Request 实现了 `ArrayAccess` / `IteratorAggregate` / `Countable` / `JsonSerializable`，
5.1.x 的数组写法照常工作：

```php
$request['path'];        // 同 $request->path()
$request['method'];      // 同 $request->method()
$request['headers'];     // 同 $request->headers()（键小写）
$request['get']['page']; // 同 $request->get('page')
$request['body'];
json_encode($request);   // 输出 toArray() 结构
```

未定义的键落到 attributes，可用于中间件传值：

```php
$request['user'] = $user;    // 等价 setAttribute('user', $user)
$request['user']['id'];
```

唯一需要注意的是 `is_array($request)` 现在返回 `false`——
如果代码里有这种判断，改成 `$request instanceof Request`。

---

## 使用运行时专有能力

需要 Swoole 或 Workerman 的独有 API 时，用 `native()` 取回原对象：

```php
$server->on('message', function ($conn, $request) {
    if ($swoole = $request->native()) {
        // $swoole 是 Swoole\Http\Request
        $raw = $swoole->getData();
    }
});
```

这条路会把业务绑到具体运行时上，只在确有必要时使用。

---

## HTTP/2

HTTP/2 请求同样交付 `Request`，`protocol()` 返回 `HTTP/2`，
`streamId()` 给出流 ID。同一份 handler 无需任何分支即可同时服务 1.1 与 2：

```php
$server->on('message', function ($conn, $request) {
    // $conn 在 HTTP/2 下是 Http2Stream，send() 行为一致
    $conn->send('protocol=' . $request->protocol());
});
```

参见 [响应与协议](response.md)。
