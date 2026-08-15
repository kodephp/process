# 响应对象边界说明

本仓库与同家族的 `kode/http`、`kode/runtime` 里都出现过名为 `Response` 的对象，但**层级与职责完全不同**。本文厘清它们的边界，避免误用。

## 一句话区分

| 对象 | 所在层 | 是什么 | 是否 PSR-7 |
| --- | --- | --- | --- |
| `Kode\Process\Response` | 业务/进程内核 | 通用 API 结果**信封值对象** | 否 |
| `Kode\Process\Async\HttpResponse` | 传输（出站客户端） | 进程作为 HTTP 客户端拿到的**响应** | 否 |
| `Kode\Process\Protocol\HttpProtocol::encode()` | 传输（服务端线级） | 直接对外提供 HTTP 时生成的**原始报文** | 否 |
| `Kode\Process\Http\Psr7Response` + `ConnectionInterface::sendResponse()` | 传输（服务端线级桥接） | 把 PSR-7 响应**桥接**回当前连接写出的**序列化器 + 连接方法** | 否 |
| `kode/http` 的 `Psr\Http\Message\ResponseInterface` | 应用层（HTTP 服务） | 路由/中间件产出的**HTTP 响应** | 是 |

## 1. `Kode\Process\Response` —— 通用 API 信封

- 路径：`src/Response.php`，`final readonly class`，实现 `JsonSerializable`。
- 结构：`{ code, message, data, meta, time }`，`code=0` 表示成功，非 0 为错误（内置 `CODE_*` 常量 + `fromCode()`）。
- 不可变：`with*()` 返回新实例；提供 `ok/error/timeout/notFound/...`、`toArray()/toJson()/fromJson()/fromArray()`、`isSuccess()/isError()`、`wrap()/pipe()/tap()/onSuccess()/onError()` 等。
- 用途：**操作结果**而非 HTTP 响应。出现在：
  - `ProtocolManager::listen*()` 返回监听器创建结果；
  - `QueueManager::process()/handle()` 返回任务执行结果；
  - 集群 RPC、跨进程消息的业务载荷。
- 关键点：它**不含** HTTP 状态行、头、流。把 `Response` 直接当 HTTP 响应发出去是错的——它只是 JSON 数据。

## 2. `Kode\Process\Async\HttpResponse` —— 出站 HTTP 客户端响应

- 路径：`src/Async/HttpResponse.php`。
- 进程作为 HTTP **客户端**调用外部服务时，由 `Async\HttpClient` 返回：`statusCode / body / headers / duration / info`，可 `json()` 解析、`isOk()/isServerError()` 等。
- 同样**不是 PSR-7**，是 process 自带异步客户端的轻量封装；语义上接近 HTTP 响应，但只用于"调用别人"。

## 3. `Protocol\HttpProtocol::encode()` —— 服务端线级响应

- 当用 process **直接对外提供 HTTP**（`Kode::serve('http://...')` 或 Native/Swoole/Workerman 的 HTTP 监听）时，handler 返回数组/字符串，`HttpProtocol::encode()` 将其编成原始 `HTTP/1.1 200 OK\r\n...\r\nbody` 报文写到连接。
- 这是最底层传输编码，只管"数组 → 线级字节"，不提供担保、中间件、流式等应用语义。与 `kode/http` 的 PSR-7 是"同目标、不同层"。

## 4. `kode/http` 的 PSR-7 `Response` —— 应用层 HTTP 响应

- 同家族 `kode/http` 提供路由 + 中间件 + PSR-7 实现，其 `Response` 实现 `Psr\Http\Message\ResponseInterface`（status code / headers / body / stream）。
- 在 Swoole / 协程非阻塞服务器中，PSR-7 响应由 handler 产出并在事件循环内 flush，**不阻塞 worker**。
- process 的传输层位于其下方：process 不替代 kode/http，二者通过"信封 vs 报文"协作。

## 5. `Psr7Response` + `sendResponse` —— PSR-7 桥接

- 路径：`src/Http/Psr7Response.php`（`final class`），`ConnectionInterface::sendResponse()` 是其连接侧入口。
- 用途：**把应用层 PSR-7 响应（来自 kode/http 等实现）桥接回 process 的连接写出去**，让同一份
  handler 在 Native / Swoole / Workerman / HTTP/2 四种运行时下写法完全一致。
- 它**不是** PSR-7 实现，而是"PSR-7 对象 → 线级字节"的**序列化器**（与 `HttpProtocol::encode` 同层）。
  复用后者的两处硬化点：头部 CR/LF/NUL 清洗（防响应拆分）、原因短语回退。

序列化语义：

- 状态行 `HTTP/{protocol} {code} {reason}`；PSR-7 未给原因短语时回退 `HttpProtocol::getStatusText()`。
- 头：同名多值头（如多个 `Set-Cookie`）逐条输出为独立头行。
- `Content-Length`：响应缺失时自动补，已显式声明则原样保留。
- gzip（`toHttp11($resp, true)`）：压缩响应体并设 `Content-Encoding: gzip` + 压缩后 `Content-Length`；
  跳过原始 `Content-Length` / `Content-Encoding`；压缩失败安全回退；响应已带 `Content-Encoding` 不二次压缩。
- 连接层 `sendResponse($response, $autoGzip = true)`：Native / Workerman 序列化为字节 raw 写出；
  Swoole HTTP 模式走原生 `status/header/end`；HTTP/2 走 HEADERS + DATA。自动 gzip 由连接的
  `isGzipAuto()` 与体量阈值共同决定。详见 `docs/runtime.md` 的「发送 PSR-7 响应」一节。

## 如何桥接

- **process 内 RPC / 队列得到 `Response` → 在 kode/http handler 中返回**：
  ```php
  $resp = $someProcessCall->execute();      // Kode\Process\Response
  return new JsonResponse($resp->toArray()); // 作为 PSR-7 body
  // 或：return new JsonResponse($resp->toJson(), $resp->isSuccess() ? 200 : 200);
  ```
- **不要**把 `Kode\Process\Response` 当 PSR-7 用：它没有实现接口，也没有 header / stream。
- **进程直接 serve HTTP** 时不需要 kode/http，handler 返回数组/字符串即可由 `HttpProtocol::encode` 兜底；需要完整应用层（路由、中间件、流式）再上 kode/http。

## 记忆要点

- 同名 `Response` ≠ 同物：process 的是**数据信封**，kode/http 的是**HTTP 报文**，Async\HttpResponse 是**出站客户端响应**。
- 选择依据：要"操作结果 / 跨进程数据" → `Kode\Process\Response`；要"对外 HTTP 报文" → `kode/http` 的 PSR-7 或 process 的 `HttpProtocol::encode`。
