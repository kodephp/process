# GlobalData 全局数据共享

GlobalData 是一个跨进程共享数据的组件，支持原子操作。

## 工作原理

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Worker 1   │     │  Worker 2   │     │  Worker 3   │
└──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │                   │                   │
       └───────────────────┼───────────────────┘
                           │
                    ┌──────┴──────┐
                    │ GlobalData  │
                    │   Server    │
                    │  (2207端口)  │
                    └─────────────┘
```

## 服务端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Server;

$server = new Server('0.0.0.0', 2207);
$server->start();
```

## 客户端

### 连接服务端

```php
use Kode\Process\GlobalData\Client;

$client = new Client('127.0.0.1:2207');
```

### 基本操作

```php
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

## 完整示例：在线人数统计

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

## 完整示例：分布式锁

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

## API 参考

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

## 注意事项

1. **原子操作** - 使用 `increment`、`cas` 等原子操作保证数据一致性
2. **网络延迟** - 每次操作都有网络开销，避免频繁调用
3. **数据大小** - 不要存储过大的数据，影响性能
4. **连接管理** - 在 `onWorkerStart` 中创建连接
