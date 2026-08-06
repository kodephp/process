# GlobalData / 多进程共享数据

本库提供**两套独立**的跨进程数据共享能力：

1. **本地多进程共享表（`SharedTable`）** —— 同主机、fork 出来的多进程之间共享计数/配置/状态，**零安装**即可用（已装 Swoole/APCu/旧版 Workerman 时自动启用对应高性能后端）。这是绝大多数场景的首选。
2. **网络 GlobalData（`Server` + `Client`）** —— 跨**多台主机**共享数据，见文末「网络模型」一节。

> **命名说明**：本库既提供了 `Kode\Process\GlobalData\GlobalData` 门面（向后兼容、同时暴露本地表与网络客户端），也单独提供了 `Kode\Process\SharedTable` 门面——**只负责本地多进程共享表、不依赖网络**。如果你只想要「同主机的多进程共享数据」，直接用 `SharedTable` 即可，不必经过 `GlobalData`。

---

# 一、本地多进程共享表（`SharedTable`）

`SharedTable` 对外提供**一套语义**（`TableInterface`），内部按「已装什么用什么」自动挑选最快后端。

| 后端 | 依赖 | 何时被 `auto()` 选中 | 特点 |
|------|------|---------------------|------|
| `swoole` | `ext-swoole` | 首选（已装 swoole 时） | 性能最高；**必须在 fork 之前创建** |
| `apcu`   | `ext-apcu`（CLI 需 `apc.enable_cli=1`） | 次选 | 运行期任意时刻可创建，适合 FPM / 动态拉起的 worker |
| `workerman` | `Workerman\Table`（仅旧版 Workerman v3 + ext-swoole） | 仅当该类存在 | `Workerman\Table` ≡ `Swoole\Table` 子类，表现与 Swoole 持平 |
| `sysvshm` | `ext-sysvshm` + `ext-sysvsem`（PHP 内置） | 兜底 | **零安装**，开箱即用，跨进程语义完整 |

设计原则：**零安装优先**。本库从不要求你安装 Swoole / APCu / Workerman，只在它们恰好存在时顺带用上；否则一律走 PHP 内置 System V 共享内存。

> `GlobalData` 门面（`Kode\Process\GlobalData\GlobalData`）的本地表方法**全部委托给 `SharedTable`**，二者语义完全一致；`GlobalData` 额外暴露 `client()` 用于跨主机网络共享。下文以 `SharedTable` 为例，`GlobalData::xxx()` 同样可用。

## 1.1 自动选择

```php
use Kode\Process\SharedTable;

// 自动挑选当前环境可用的最快后端（swoole → apcu → workerman → sysvshm）
$table = SharedTable::auto();

// 或显式指定
$table = SharedTable::make(SharedTable::BACKEND_SHM, key: 0x4B4F4445, size: 4 * 1024 * 1024);

// 自检当前可用后端
SharedTable::available();   // 例如 ['swoole', 'sysvshm']
SharedTable::preferred();   // 例如 'swoole'
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
```

## 2.2 客户端

```php
use Kode\Process\GlobalData\Client;

$client = new Client('127.0.0.1:2207');

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

Kode::worker('websocket://0.0.0.0:8080', 4)
    ->onWorkerStart(function () use (&$globalData) {
        $globalData = new Client('127.0.0.1:2207');
        $globalData->online_count = 0;
    })
    ->onConnect(function ($conn) use (&$globalData) {
        $globalData->increment('online_count', 1);
    })
    ->onClose(function ($conn) use (&$globalData) {
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

# 三、注意事项

1. **本地共享表**：原子操作（`increment` / `cas`）跨进程安全；fork 场景下 Swoole/共享内存表必须在 fork 前由父进程创建，子进程继承其内存。macOS 的 System V 共享内存总量很小（约 4MB），生产部署建议在 Linux 上运行，或安装 Swoole/APCu 启用更大容量的后端。
2. **网络延迟** - 网络模型每次操作都有网络开销，避免频繁调用。
3. **数据大小** - 不要存储过大的数据，影响性能。
4. **连接管理** - 网络模型在 `onWorkerStart` 中创建连接。
