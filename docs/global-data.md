# GlobalData / 多进程共享数据

本库提供**两套独立**的跨进程数据共享能力：

1. **本地多进程共享表（`SharedTable`）** —— 同主机、fork 出来的多进程之间共享计数/配置/状态，**零安装、零依赖**即可用（只依赖 PHP 内置的 System V 共享内存或常见 APCu 扩展）。这是「不引入 Swoole / Workerman 时」的兜底方案。
2. **网络 GlobalData（`Server` + `Client`）** —— 跨**多台主机**共享数据，见文末「网络模型」一节。

> **关于命名**：「GlobalData」这个**概念与名称来自 Workerman 的 GlobalData 组件**。本库的网络版 `Server` / `Client` 是同一思路的兼容实现（独立进程 + TCP，可跨主机）；如果你只用本地多进程共享，直接用 `SharedTable` 即可，不必经过 `GlobalData` 门面。

---

# 一、本地多进程共享表（`SharedTable`）

`SharedTable` 对外提供**一套语义**（`TableInterface`），内部按「零安装优先」择优。

## 本库定位：Swoole / Workerman 之外的「第三选择」

Swoole、Workerman 运行多年、生态成熟稳定。**若你的应用已经基于它们，应优先使用它们自带的共享表**（`Swoole\Table` / `Workerman\Table`）——不必引入本库的维护负担。本库内置共享表的真正价值，是**在你不打算引入 Swoole / Workerman 时**提供一套**零安装、零依赖**的兜底：

| 后端 | 依赖 | 角色 | 特点 |
|------|------|------|------|
| `apcu` | `ext-apcu`（CLI 需 `apc.enable_cli=1`） | 零安装兜底之一 | 运行期任意时刻可创建，适合 FPM / 动态拉起的 worker |
| `sysvshm` | `ext-sysvshm` + `ext-sysvsem`（PHP 内置） | 零安装兜底之一 | **零安装**，开箱即用，跨进程语义完整 |

`SharedTable::auto()` 默认只在这两类零安装后端间择优。

## 与 Swoole / Workerman 的兼容

当应用**已经**跑在 Swoole / Workerman 之上，可显式调用 `make('swoole')` / `make('workerman')`，让同一套 `TableInterface` 语义直接**复用它们的共享表**，做到「不引入第二个依赖」的兼容共存：

| 后端 | 依赖 | 角色 | 特点 |
|------|------|------|------|
| `swoole` | `ext-swoole` | 兼容适配器（可选） | 性能最高；**必须在 fork 之前创建** |
| `workerman` | `Workerman\Table`（仅旧版 Workerman v3 + ext-swoole） | 兼容适配器（可选） | `Workerman\Table` ≡ `Swoole\Table` 子类，表现与 Swoole 持平 |

设计原则：**零安装优先**。本库从不要求你安装 Swoole / APCu / Workerman，只在它们恰好存在时通过 `make()` 顺带兼容；否则一律走 PHP 内置 System V 共享内存。

> `GlobalData` 门面（`Kode\Process\GlobalData\GlobalData`）的本地表方法**全部委托给 `SharedTable`**，二者语义完全一致；`GlobalData` 额外暴露 `client()` 用于跨主机网络共享。下文以 `SharedTable` 为例，`GlobalData::xxx()` 同样可用。

## 1.1 自动选择

```php
use Kode\Process\SharedTable;

// 自动挑选当前环境可用的零安装后端（apcu → sysvshm）
$table = SharedTable::auto();

// 或显式指定；运行在 Swoole / Workerman 之上时复用其共享表（兼容适配器）
$table = SharedTable::make(SharedTable::BACKEND_SHM, key: 0x4B4F4445, size: 4 * 1024 * 1024);
$table = SharedTable::make(SharedTable::BACKEND_SWOOLE); // 仅当应用已用 swoole 时

// 自检当前可用后端
SharedTable::available();   // 例如 ['sysvshm', 'swoole']（零安装兜底在前）
SharedTable::preferred();   // 例如 'sysvshm'
SharedTable::supports(SharedTable::BACKEND_APCU); // bool
SharedTable::diagnose();    // 各后端可用性明细
```

## 1.2 统一 API（TableInterface）

所有后端实现同一套语义，可互换：

```php
// 写入 / 读取（值以原生 PHP 类型进出，false / null / 0 都能正确区分「存在但为假值」与「不存在」）
$table->set('config', ['debug' => true], ttl: 0);
$value = $table->get('config');

// 仅不存在时写入 / 仅存在时写入
$table->add('counter', 0);     // 已存在返回 false
$table->replace('counter', 1); // 不存在返回 false

// 批量
$table->setMultiple(['a' => 1, 'b' => 2]);
$table->getMultiple(['a', 'b', 'missing']); // ['a' => 1, 'b' => 2, 'missing' => null]

// 存在性 / 删除
$table->exists('config');
$table->delete('config');

// 原子自增 / 自减（跨进程安全；支持 int 与 float）
$table->increment('counter');        // 1
$table->increment('counter', 10);    // 11
$table->decrement('counter');        // 10
$table->increment('ratio', 0.5);     // 0.5

// 比较并交换：仅当当前值全等于 $old 时写入 $new
$table->cas('counter', 10, 20);      // true
$table->cas('counter', 999, 1);      // false（值已不是 999）

// 键集合 / 计数 / 清空 / 统计
$table->keys();
$table->count();
$table->clear();                     // 清空但表仍可用
$table->stats();                     // 含 'backend' 等字段

// TTL（秒级惰性过期，0 表示永不过期）
$table->set('ticket', 'abc', ttl: 60);
```

> **惰性过期**：过期键在下次读取时被惰性清理；`get()` 对已过期键返回 `null`，`exists()` 返回 `false`。

> **`null` 只表示「不存在」（v5.2.12）**：`get()` 未命中统一返回 `null`；
> 若存进去的值本身就是 `false`，读出来如实是 `false`。要区分「不存在」与「存了 null」请用 `exists()`。
> 共享内存后端此前在读失败时也返回 `false`，与「存的就是 false」混为一谈。

> **跨进程缓存一致性（v5.2.12）**：`SharedMemoryTable` 的进程内 `key → varId` 缓存
> 现在带代际（epoch）校验。此前别的进程 `delete()` / `clear()` 后本进程缓存不失效，
> 而 `clear()` 会把分配游标倒回低位、让 varId 被回收复用——
> 结果 `get('alpha')` 可能读出 `beta` 的值。现在 `clear()` 不再倒回游标，
> 缓存条目在读取时按代际校验，不匹配即重新查目录。

> **对象注入防护（v5.2.13）**：`SwooleTable` / `WorkermanTable` 的反序列化从「无限制 `unserialize()`」
> 收紧为受控反序列化（共享工具 `GlobalData\SafeUnserialize`）：默认 `allowed_classes=false`，
> 只还原标量 / 数组，任何对象一律降级为 `__PHP_Incomplete_Class` 或 `null`，杜绝 `__wakeup` / `__destruct`
> 对象注入——共享表内存可被同机其他进程写入。构造函数新增第三参 `$allowedClasses`
> （默认 `false`，向后兼容），确需存对象时显式传类名白名单。此前裸 `unserialize()` 会被投毒载荷触发对象注入。

## 1.3 典型场景：跨进程计数器 / 限流

```php
use Kode\Process\SharedTable;

$table = SharedTable::default();   // 进程内共享的默认表（按 auto() 创建）

// worker 启动时初始化
$table->add('online', 0);

// 连接建立
$table->increment('online');
// 连接关闭
$table->decrement('online');

echo $table->get('online');
```

> fork 之后子进程若需独立的默认表，调用 `SharedTable::reset()` 让子进程重建（Swoole/共享内存表必须在 fork 前由父进程创建，子进程继承其内存）。

## 1.4 共享内存帮助方法

同主机各进程传入相同键（或相同文件路径）即可共享同一份数据：

```php
use Kode\Process\SharedTable;

// 按整数键
$t = SharedTable::table(key: 0x4B4F4445, size: 4 * 1024 * 1024);
$t->set('x', 42);

// 按文件路径派生键（不同进程传相同路径即共享）
$o = SharedTable::open('/var/run/app/global.sock', project: 'g');
$o->set('y', 'z');
```

---

# 二、网络模型（跨主机）

当数据需要跨多台主机共享时，使用 `Server` + `Client` 网络模型（同主机的多进程共享请优先用上面的本地共享表，零安装、无网络开销）。

## 2.1 服务端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Server;

$server = new Server('0.0.0.0', 2207);
$server->start();

// 建议：显式配置共享令牌（第三参），仅通过校验的客户端可读写
$server = new Server('0.0.0.0', 2207, token: getenv('KODE_GD_TOKEN'));
$server->start();
```

> **⚠️ 安全**：不传 `token` 时**不启用鉴权**（保持向后兼容），任何能连上该端口的人
> 都能读写全部共享状态——而集群的分布式锁、Leader 选举状态就落在这里。
> 网络版 GlobalData 请只监听内网地址并配置 `token`，切勿暴露公网。
>
> 服务端对单帧长度设了 `Server::MAX_FRAME_SIZE`（8 MiB）上限，超出即断开该连接；
> 此前 4 字节长度前缀不设上限，对端发一个 `0xFFFFFFFF` 就能让服务端持续扩缓冲直到 OOM。
> 响应写出改为循环写完整帧，此前 `MSG_DONTWAIT` 的部分写会被静默丢弃，导致客户端解帧错位。

## 2.2 客户端

```php
use Kode\Process\GlobalData\Client;

$client = new Client('127.0.0.1:2207');
// 服务端配置了 token 时，客户端需带上同一个令牌
$client = new Client('127.0.0.1:2207', token: getenv('KODE_GD_TOKEN'));

$client->counter = 0;
$client->name = 'KodePHP';
$client->config = ['debug' => true, 'timezone' => 'Asia/Shanghai'];

echo $client->counter;
isset($client->counter);
unset($client->counter);
```

### 原子操作

```php
$client->increment('counter', 1);
$client->decrement('counter', 1);
$client->cas('counter', 10, 20);
$client->add('new_key', 'default_value');
```

### 批量操作

```php
$client->setMulti(['key1' => 'value1', 'key2' => 'value2']);
$values = $client->getMulti(['key1', 'key2']);
```

## 2.3 完整示例：在线人数统计

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;
use Kode\Process\GlobalData\Client;

$globalData = null;

Kode::serve('websocket://0.0.0.0:8080', ['workers' => 4])
    ->on('workerStart', function (int $workerId) use (&$globalData) {
        $globalData = new Client('127.0.0.1:2207');
        $globalData->online_count = 0;
    })
    ->on('connect', function ($conn) use (&$globalData) {
        $globalData->increment('online_count', 1);
    })
    ->on('close', function ($conn) use (&$globalData) {
        $globalData->decrement('online_count', 1);
    })
    ->start();
```

## 2.4 完整示例：分布式锁

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Client;

class DistributedLock
{
    private Client $client;
    private string $prefix = 'lock:';

    public function __construct(string $address = '127.0.0.1:2207')
    {
        $this->client = new Client($address);
    }

    public function lock(string $key, int $expire = 10): bool
    {
        $lockKey = $this->prefix . $key;
        $expireTime = time() + $expire;

        if (!isset($this->client->$lockKey)) {
            $this->client->$lockKey = $expireTime;
            return true;
        }

        if ($this->client->$lockKey < time()) {
            $this->client->$lockKey = $expireTime;
            return true;
        }

        return false;
    }

    public function unlock(string $key): void
    {
        unset($this->client->{$this->prefix . $key});
    }

    public function withLock(string $key, callable $callback, int $expire = 10): mixed
    {
        if (!$this->lock($key, $expire)) {
            throw new Exception('获取锁失败');
        }

        try {
            return $callback();
        } finally {
            $this->unlock($key);
        }
    }
}

$lock = new DistributedLock();
$lock->withLock('order_123', fn() => processOrder(123), 30);
```

## 2.5 API 参考

```php
use Kode\Process\GlobalData\Client;

$client = new Client('127.0.0.1:2207');

$client->key = 'value';
$value = $client->key;
isset($client->key);
unset($client->key);

$client->increment('key', 1);
$client->decrement('key', 1);
$client->cas('key', $old, $new);
$client->add('key', 'value');
$client->setMulti(['k1' => 'v1', 'k2' => 'v2']);
$data = $client->getMulti(['k1', 'k2']);
```

---

# 二、五 进程间通信（IPC）

`Kode\Process\IPC` 下的 `MessageQueue`（System V 消息队列）、`SharedMemoryIPC`
（共享内存环形队列）、`SocketIPC` 用于同机进程间传消息，语义与共享表不同：
共享表是「共享状态」，IPC 是「一次性投递」。

> **⚠️ 行为变更（v5.2.12）：`close()` 不再销毁队列。**
>
> ```php
> $q = new MessageQueue($key);
> $q->close();     // 只关闭本进程句柄，队列与在途消息保留给其他进程
> $q->destroy();   // 真正删除底层队列（对所有进程生效）
> ```
>
> 此前 `close()`（以及 `__destruct` 触发的隐式关闭）调的是 `msg_remove_queue()`——
> **任何一个进程退出都会把全局队列连同在途消息一起删掉**，其他进程的收发直接失效。
> 若你依赖旧行为在退出时清理队列，请显式改调 `destroy()`。

其他加固：

- **权限收紧到 `0600`**：消息队列与共享内存段此前是 `0644`，同机任意用户可读取
  甚至排空业务消息。
- **不再还原对象**：IPC 负载的 `unserialize()` 统一带 `['allowed_classes' => false]`。
- **超长消息不再变毒丸**：此前先校验原始消息大小、再套一层更大的信封且不复检，
  接收端 `msg_receive` 因 `E2BIG` 既不出队也不报错，阻塞模式下无 sleep 空转把 CPU 打满。
  现在按信封实际大小校验，接收端遇到超限消息会丢弃并继续，无消息时改为 `usleep` 退避。
- **共享内存写满不再静默丢数据**：`shm_put_var` 失败时不再照常推进计数，
  避免消费端读出 `false`。

---

# 三、注意事项

1. **选型建议**：若应用已经基于 Swoole / Workerman，优先用它们自带的共享表（`Swoole\Table` / `Workerman\Table`），成熟稳定、无需额外维护；本库本地表是「不引入 Swoole / Workerman 时」的零安装兜底。**本地共享表**的原子操作（`increment` / `cas`）跨进程安全；fork 场景下 Swoole/共享内存表必须在 fork 前由父进程创建，子进程继承其内存。macOS 的 System V 共享内存总量很小（约 4MB），生产部署建议在 Linux 上运行。
2. **网络延迟** - 网络模型每次操作都有网络开销，避免频繁调用。
3. **数据大小** - 不要存储过大的数据，影响性能。
4. **连接管理** - 网络模型在 `onWorkerStart` 中创建连接。
