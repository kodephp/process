# 异步（Async）

`Kode\Process\Async` 提供运行时无关（不依赖 Swoole / Workerman）的异步原语，全部跑在框架统一的事件循环（[`Kode::loop()`](runtime.md) / `Reactor`）之上。它与协程、进程编排的关系是：

- **协程（CPU 友好的并发单元）**：用 `Kode::go($task)` 启动（底层 `Fibers::go`），在协程体内可 `yield` 挂起、用 `Promise::await()` 等待异步结果。
- **事件循环**：`Async` 的定时器、微任务、Promise 决议都发生在 `Reactor` 上；服务器由框架自行驱动循环，你通常**不需要**自己调用 `Async::run()`，在 `Kode::go()` 协程或回调里直接用这些原语即可。
- **共享语义**：`Promise` / `EventEmitter` / `HttpClient` 三者在任意运行时下交付同一套 API，业务零改动。

命名空间：`Kode\Process\Async\{Async, Promise, EventEmitter, HttpClient, HttpResponse}`。

---

## Promise

`Promise` 是异步结果容器，支持链式 `then / catch / finally` 与 `await` 同步取值。

```php
use Kode\Process\Async\Promise;

$p = Promise::resolve(42)
    ->then(fn($v) => $v * 2)                 // 84
    ->then(fn($v) => Promise::resolve($v + 1)); // 支持返回 Promise，自动展平

$p->then(fn($v) => echo "结果: $v\n");      // 结果: 85
$p->catch(fn($e) => echo "错误: {$e->getMessage()}\n");
$p->finally(fn() => echo "无论成败都会执行\n");

// 同步等待（协程内挂起；非协程上下文会自行驱动事件循环直到决议）
$value = $p->await();                       // 85
```

### 组合子（静态）

| 方法 | 语义 |
|------|------|
| `Promise::all(array $ps)` | 全部成功才成功，值为结果数组；任一失败立即拒绝 |
| `Promise::race(array $ps)` | 第一个决议（成功或失败）即定胜负 |
| `Promise::any(array $ps)` | 第一个**成功**即成功；全部失败才拒绝 |
| `Promise::allSettled(array $ps)` | 等全部落定，返回 `[{status,value\|reason}]` 数组 |

### 状态查询

`$p->isPending()` / `isFulfilled()` / `isRejected()` 取布尔态；`getState()` 返回字符串态，`getValue()` / `getReason()` 取结果或拒因。

`await()` 在协程（Fiber）上下文中挂起当前协程直到决议；在非协程上下文中由 `Async` 自己驱动事件循环充当等待，因此 `Promise::resolve(1)->then(...)->await()` 在任何环境下都不会死锁（v5.2.25 修复）。

---

## 定时器与微任务（Async）

所有定时器返回 `int` 句柄，用对应的 `clear*` 取消。时间单位为**秒（支持小数）**。

```php
use Kode\Process\Async\Async;

$id = Async::setTimeout(fn() => echo "2.5 秒后一次\n", 2.5);
Async::clearTimeout($id);

Async::setInterval(fn() => echo "每 1 秒\n", 1.0);
Async::setImmediate(fn() => echo "本轮循环末尾、定时器之前\n");
Async::nextTick(fn() => echo "当前操作完成后、微任务阶段\n");
Async::defer(fn() => echo "当前调用栈清空后\n");

// 协程内可用
Async::delay(1.0)->then(fn() => echo "延迟 1 秒（返回 Promise）\n");
Async::sleep(0.5);                          // 阻塞当前协程 0.5 秒（不阻塞事件循环）

// 微任务：在当前宏任务之后、定时器之前批量执行
Async::queueMicrotask(fn($x) => echo "微任务 $x\n", 1);
```

`Async::run()` 是循环驱动入口（微任务 → 定时器 → deferred → 自适应休眠），仅在你**自己**托管主循环时才调用；服务器场景交给框架即可。需要手动推进一帧时用 `Async::tick()`。

---

## 并发原语（Async）

对一组成果为 `Promise` 的任务或一组可并发处理的数据提供高阶组合。

```php
use Kode\Process\Async\Async;

// 并发组合（参数为 Promise 数组）
Async::all([$p1, $p2, $p3])->then(fn($results) => /* [...] */);
Async::race([$a, $b]);
Async::any([$a, $b, $c]);
Async::allSettled([$a, $b]);

// 数据并行：对 $items 逐项回调，最多 $concurrency 路并发
Async::map($items, fn($item) => httpGet($item), 16)
    ->then(fn($mapped) => /* 保持原顺序的结果数组 */);
Async::each($items, fn($item) => process($item), 8);
Async::filter($items, fn($item) => keep($item), 8);
Async::reduce($items, fn($acc, $item) => $acc + $item, 0);

// 失败重试与回调 Promise 化
Async::retry(fn() => unreliable(), maxAttempts: 3, delay: 0.1)
    ->then(fn($ok) => /* 成功结果 */);
$wrapped = Async::promisify($someCallbackStyleFn);  // 包装为返回 Promise

// 条件轮询（最多等 $timeout 秒，每 $interval 秒探一次）
$ready = Async::wait(fn() => isReady(), timeout: 5.0, interval: 0.1);

// 运行态快照：微任务 / 定时器 / deferred 计数等，用于诊断
$snapshot = Async::getStatus();
```

> `map / each / filter / reduce` 的并发上限 `$concurrency` 自 v5.2.26 起真正生效（此前被写死为 10，高并发请求会被静默吞掉）。

---

## 事件发射器（EventEmitter）

进程内发布/订阅，与 `Kode::emitter()` 门面同源。

```php
use Kode\Process\Async\EventEmitter;

$ee = new EventEmitter();
$ee->on('task.done', fn($id) => echo "任务 $id 完成\n");
$ee->once('boot', fn() => echo "只触发一次\n");
$ee->prependListener('x', fn() => /* 插到队首 */);

$ee->emit('task.done', 7);
$ee->listenerCount('task.done');             // 1
$ee->off('task.done');
$ee->setMaxListeners(20);
```

方法：`on / off / once / prependListener / prependOnceListener / emit / listeners / hasListeners / listenerCount / eventNames / removeAllListeners / setMaxListeners / getMaxListeners`。

---

## 异步 HTTP 客户端（HttpClient）

`HttpClient` 基于 `Async` 事件循环做非阻塞请求，所有方法返回 `Promise<HttpResponse>`。

```php
use Kode\Process\Async\HttpClient;

$client = HttpClient::create('https://api.example.com', timeout: 10.0)
    ->withHeaders(['Accept' => 'application/json'])
    ->withTimeout(5.0)
    ->withOptions(['verify_peer' => true]);

// 便捷动词
$client->get('/users', ['page' => 1])->then(fn($res) => /* HttpResponse */);
$client->post('/users', ['name' => 'kode']);
$client->put('/users/1', ['name' => 'new']);
$client->delete('/users/1');
$client->patch('/users/1', ['status' => 'active']);
$client->head('/health');

// JSON 自动序列化请求体、解析响应体
$client->json('POST', '/rpc', ['a' => 1])->then(fn($res) => $res->json());

// 原始请求 + 自定义头
$client->request('POST', '/x', $body, ['X-Token' => 'secret']);

// 大文件上传 / 下载
$client->upload('/upload', ['file' => '/path/a.jpg'], ['title' => 'a']);
$client->download('/report.pdf', '/tmp/report.pdf');

// 批量并发（最多 $concurrency 路同时飞）
$client->concurrent([
    $client->get('/a'),
    $client->get('/b'),
    $client->get('/c'),
], concurrency: 5)->then(fn($responses) => /* HttpResponse[]，保持顺序 */);
```

`await()` 后得到的 `HttpResponse` 提供：

| 方法 | 说明 |
|------|------|
| `getStatusCode()` | HTTP 状态码 |
| `getBody()` / `getParsedBody()` | 原始体 / 解析后的体（按 Content-Type） |
| `json()` / `toArray()` | 解析为数组（JSON 场景） |
| `getHeaders()` / `getInfo()` / `getDuration()` | 响应头 / 传输信息 / 耗时（秒） |
| `isOk()` / `isSuccessful()` / `isRedirect()` / `isClientError()` / `isServerError()` / `isNotFound()` / `isForbidden()` / `isUnauthorized()` | 状态分类谓词 |

---

## 综合示例

```php
use Kode\Process\Async\HttpClient;
use Kode\Process\Async\Async;
use Kode\Process\Kode;

Kode::go(function (): void {
    $client = HttpClient::create('https://api.example.com');

    // 并发抓取 100 个用户，16 路并发，每个失败重试 2 次
    $users = Async::retry(
        fn() => Async::map(
            range(1, 100),
            fn($id) => $client->json('GET', "/users/$id"),
            16
        ),
        maxAttempts: 2, delay: 0.2
    )->await();

    echo '抓到 ' . count($users) . " 个用户\n";
});
```

上面的 `Kode::go()` 启动协程，协程内用 `Async` 的并发原语 + `HttpClient` 非阻塞抓取，`await()` 在协程上下文挂起等待，不阻塞事件循环。

---

## 与运行时 / 集群的关系

- `Async` 与 [运行时（Runtime）](runtime.md) 正交：无论 Native / Swoole / Workerman，`Async`、`Promise`、`EventEmitter`、`HttpClient` 都是同一实现。
- 需要 **CPU 并行**（真多线程或进程池）时用 `Kode::parallel()` / `Kode::awaitParallel()`（见 [并行](parallel.md)）；`Async` 解决的是 **I/O 并发**，二者互补。
- 集群内的定时任务用 `Kode::cronCluster()`（仅 Leader 执行，见 [集群](cluster.md)），单机定时用 `Kode::cron()`（见 [定时器](timer.md)）。
