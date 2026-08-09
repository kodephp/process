# 分布式集群

`Kode\Process\Runtime` 解决「一台机器跑满多核」，`Kode\Process\Cluster` 解决「多台机器协同工作」：
谁在线、谁当头、谁来干、干多少、往哪派、节点间怎么调。

所有能力都建在统一的协调存储抽象（`Cluster\Store\StoreInterface`）之上，换后端不用改一行业务代码。

## 存储后端

| 后端 | 适用场景 | 依赖 |
|------|----------|------|
| `file` | 单机多实例 / 开发联调 | 零依赖（本地文件，建议落在 `/dev/shm`） |
| `globaldata` | 零外部依赖的多机 | 复用本包 GlobalData（`kode/process` 自带） |
| `redis` | 生产多机（推荐） | `ext-redis` + 一个 Redis 实例 |

```php
use Kode\Process\Cluster;

Cluster::make('redis', ['host' => '10.0.0.5', 'port' => 6379]);
// 或零依赖：
Cluster::make('globaldata', ['address' => '10.0.0.5:2207']);
// 或单机：
Cluster::make('file', ['path' => '/dev/shm/kode-cluster']);
```

未显式指定时 `Cluster::auto()` 按 `redis → globaldata → file` 顺序择优：前两者需真实连通才算可用，
连不上顺延，`file` 永远兜底成功，因此正常环境下不会失败。

## 一个完整节点

把集群协调收敛到「0 号 worker」即可，避免多进程重复竞选：

```php
use Kode\Process\{Kode, Cluster};

Cluster::make('redis', ['host' => '10.0.0.5']);

$server = Kode::serve('http://0.0.0.0:9501', ['workers' => 8]);

$server->on('workerStart', function ($rt) {
    if ($rt->workerId() !== 0) {
        return;                                  // 只让 0 号 worker 参与集群协调
    }

    Cluster::join(['id' => gethostname(), 'service' => 'api', 'port' => 9501]);

    $election = Cluster::election('cron');
    Kode::every(5.0, function () use ($election) {
        Cluster::heartbeat();                    // 上报存活（失联超 ttl×2 自动摘除）
        if ($election->tick()) {
            runClusterWideCron();                // 全集群唯一执行点
        }
    });
});

$server->on('message', fn($conn) => $conn->send('node ' . (Cluster::self()?->id ?? 'unknown')));
$server->start();
```

## 服务注册与发现

```php
use Kode\Process\Cluster;

$me = Cluster::join([
    'id'      => gethostname(),   // 省略则取 主机名-PID
    'service' => 'api',
    'host'    => '10.0.0.11',     // 省略则自动探测本机出口 IP
    'port'    => 9501,
    'weight'  => 100,
]);

Cluster::heartbeat();             // 周期续约（必须在 ttl 内调用，否则被摘除后自动重新注册）

$nodes  = Cluster::nodes('api');  // 某服务下健康节点（默认只返回健康节点）
$peers  = Cluster::peers('api');  // 排除自身的其他节点（广播时用）
$self   = Cluster::self();        // 本节点信息
Cluster::leave();                 // 优雅下线：注销注册、让出 Leader、归还机器 ID
```

节点状态三段式：`Up`（≤ ttl 内有心跳）→ `Suspect`（≤ 2×ttl，防抖观察）→ `Down`（自动摘除）。

## 分布式锁

基于存储层的原子 `setIfAbsent`（Redis 即 `SET NX PX`），具备互斥、防死锁（带 TTL）、
防误删（`compareAndDelete` 令牌校验）、可续期（`refresh()`，须手动调用）四个生产级性质，且支持可重入。

```php
use Kode\Process\Cluster;

$lock = Cluster::lock('order:1001', ttl: 30.0);

// 写法一：托管执行，异常也保证释放
$result = $lock->withLock(fn () => settle(1001), wait: 5.0);

// 写法二：手动控制
if ($lock->acquire(wait: 5.0)) {
    try {
        settle(1001);
    } finally {
        $lock->release();
    }
}

$lock->refresh();        // 长任务续命
$lock->isHeld();         // 本实例是否仍持有
$lock->owner();          // 当前持有者可读标识（取令牌中 owner 段）
```

## Leader 选举

让「只应跑一次」的任务（定时任务、数据补偿、缓存预热）真的只跑一次。基于带 TTL 的抢占式租约。

```php
use Kode\Process\Cluster;

$election = Cluster::election('cron');
$election->onElected(fn () => logger('本节点当选 Leader'))
         ->onResigned(fn () => logger('本节点让出 Leader'));

// 放进事件循环周期驱动（间隔建议 ttl / 3）
Kode::every(5.0, function () use ($election) {
    if ($election->tick()) {            // tick() 内部自动竞选 / 续租 / 让位
        runClusterWideCron();
    }
});

$election->isLeader();  // 当前是否 Leader
$election->resign();    // 主动让位（进程退出前调用）
```

> 一致性边界：这是单一存储上的租约锁，不是 Raft。存储脑裂或 Leader 卡顿超 TTL 的极端情况下可能短暂双 Leader。
> 金融级场景请在业务侧再加幂等键。

## 负载均衡

五种策略，别名见下表。有状态策略（一致性哈希）通过 `select($key)` 接收分片键。

| 策略 | 别名 | 说明 |
|------|------|------|
| `round-robin` | `rr` | 轮询 |
| `weighted` | `wrr` | 加权轮询（按节点 `weight`） |
| `random` | — | 随机 |
| `least-conn` | `least` | 最少连接（空闲节点优先于权重） |
| `hash` | `consistent-hash` | 一致性哈希（相同 key 落到同一节点） |

```php
use Kode\Process\Cluster;

$lb = Cluster::balancer('least-conn', Cluster::nodes('cache'), service: 'cache');
$node = $lb->select();                 // 选出下一个节点

$hashLb = Cluster::balancer('hash');
$node   = $hashLb->select("user:{$userId}");   // 相同 userId 永远落到同一节点

$lb->trySelect();                      // 无可用节点时返回 null 而非抛异常
$lb->setNodes(Cluster::peers('api')); // 用服务发现结果刷新候选
```

## 分布式 ID（Snowflake）

全局唯一、趋势递增、含时间戳与机器 ID。机器 ID 从协调存储自动领取并需周期续租。

```php
use Kode\Process\Cluster;

$snowflake = Cluster::snowflake();      // 自动领取集群内唯一 workerId
$id        = $snowflake->next();       // 生成下一个 ID

$info = Cluster::snowflake()->parse($id);
// 返回：['id'=>, 'timestamp'=>, 'datetime'=>, 'worker_id'=>, 'sequence'=>]

// 续租机器 ID（建议周期调用，间隔 < ttl）
Kode::every(60.0, fn () => Cluster::renewSnowflake());
// renewSnowflake() 返回 false 表示租约丢失并会自动重新分配
```

> **v5.2.12**：租约丢失后重新分配的新 `workerId` 会**就地换绑到同一个 `Snowflake` 实例**上。
> 此前是新建实例，任何提前持有 `$sf = Cluster::snowflake()` 的调用方仍在用旧 `workerId`
> 生成 ID——而那个 ID 已被集群分配给别的节点，直接产出重复 ID。
> 所以现在缓存实例是安全的，无需每次都走 `Cluster::snowflake()`。

## 分布式限流

计数落在共享存储上，限的是**整个集群的总量**，而不是每台各限一份（否则放行量会放大成「配置值 × 机器数」）。

```php
use Kode\Process\Cluster;

$limiter = Cluster::limiter();

// 固定窗口：每用户每分钟最多 100 次
if (!$limiter->attempt("api:{$userId}", limit: 100, window: 60.0)) {
    return error(429, '请求过于频繁');
}

// 令牌桶：容量 20、每秒补 5 个令牌（平滑限速 + 允许突发）
if (!$limiter->consume('sms:send', capacity: 20, refillPerSecond: 5.0)) {
    return error(429, '短信发送超速');
}

// 兜底写法：超限时执行回调而非抛异常
$limiter->throttle('api:ip:' . $ip, 100, 60.0,
    fn () => handleRequest(),
    fn () => error(429, 'too many requests'),
);
```

### 存储故障时 fail-closed

> **⚠️ 行为变更（v5.2.12）**：存储后端不可用时，限流器**拒绝**请求，而不是放行。

原先 `GlobalDataStore` / `RedisStore` 会把 `increment()` 的失败强转成 `0`，
而 `0 <= limit` 恒真——**Redis 一挂，全集群限流器直接失效、无限放行**，
恰恰是在后端最脆弱的时刻放开闸门。现在失败如实返回 `false`，限流器据此拒绝。

自定义存储需同步这个契约：

```php
// StoreInterface
public function increment(string $key, int $step = 1, int $ttlMs = 0): int|false;
//                                                                    ^^^^^^^^^
// 返回 false = 后端不可用/操作失败；限流器靠它区分「还没用过配额」与「后端挂了」
```

若你实现了 `StoreInterface`，把返回类型从 `int` 改成 `int|false`，
并确保失败路径返回 `false` 而非 `0`。

## 集群 RPC

节点间互相调方法、全集群广播。基于紧凑帧（`4 字节大端总长 + JSON`）在 TCP 上传输。

```php
use Kode\Process\Cluster;

// 服务端：在每个 worker 起的独立端口上
$server = Cluster::server(token: 'secret');
$server->register('flushCache', fn (array $params) => doFlush($params)); // 先注册方法
$server->listen('tcp://0.0.0.0:9700', ['workers' => 2])->start();        // 再监听并启动
// $request 帧格式：['i'=>id, 'm'=>method, 'p'=>params]，由运行时自动解帧后路由到已注册方法

// 客户端：调某个节点 / 广播全集群
$client  = Cluster::rpc(timeout: 3.0, token: 'secret');
$result  = $client->call('10.0.0.12:9502', 'flushCache', ['tags' => ['user']]);
$results = $client->broadcast(Cluster::peers('api'), 'reloadConfig');

// 便捷封装
$results = Cluster::broadcast('reloadConfig');   // 自动取 peers 广播
```

### 鉴权与错误边界

- `token` 用 `hash_equals()` 定时比较，避免逐字节短路带来的时序侧信道。
- 校验通过后 `_token` 会从 `$params` 中剔除，**不会**透传给你的方法处理器。
- 方法处理器抛异常时，客户端只收到通用错误文案，具体异常信息记在服务端日志里，
  不随响应帧外泄内部实现细节。
- 未配置 `token` 时不启用鉴权——集群 RPC 端口请勿直接暴露到公网。

## 自检

```php
print_r(Cluster::diagnose());
// 包含：available_backends / backend / connected / ttl / self /
//       elections / snowflake_worker / services / node_count
```

`Kode::diagnose()` 也会在 `cluster` 段暴露集群后端与已加入节点，便于部署前确认协调层连通。
