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

### 启动服务端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Server;

// 创建 GlobalData 服务端
$server = new Server('0.0.0.0', 2207);
$server->start();
```

### 命令行启动

```bash
php global-data-server.php start -d
```

## 客户端

### 连接服务端

```php
use Kode\Process\GlobalData\Client;

// 创建客户端连接
$client = new Client('127.0.0.1:2207');
```

### 基本操作

```php
// 设置值
$client->counter = 0;
$client->name = 'KodePHP';
$client->config = ['debug' => true, 'timezone' => 'Asia/Shanghai'];

// 读取值
echo $client->counter;  // 0
echo $client->name;     // KodePHP

// 修改值
$client->counter = 100;

// 检查存在
isset($client->counter);  // true
isset($client->notexist); // false

// 删除值
unset($client->counter);
```

### 原子操作

原子操作确保在多进程环境下的数据一致性。

```php
// 自增
$client->increment('counter', 1);  // counter + 1
$client->increment('counter', 5);  // counter + 5

// 自减
$client->decrement('counter', 1);  // counter - 1

// CAS（比较并交换）
// 如果 counter 当前值是 10，则设置为 20
$client->cas('counter', 10, 20);

// 原子添加（如果不存在则添加）
$client->add('new_key', 'default_value');
```

### 批量操作

```php
// 批量设置
$client->setMulti([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3'
]);

// 批量获取
$values = $client->getMulti(['key1', 'key2', 'key3']);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3']
```

## 完整示例：在线人数统计

### 服务端

```php
<?php
// global-data-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\GlobalData\Server;

$server = new Server('0.0.0.0', 2207);
$server->start();
```

### 业务服务端

```php
<?php
// chat-server.php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Process\GlobalData\Client;

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 4;

$worker->onWorkerStart = function ($worker) {
    // 连接 GlobalData 服务端
    global $globalData;
    $globalData = new Client('127.0.0.1:2207');
    
    // 初始化在线人数
    $globalData->online_count = 0;
};

$worker->onConnect = function ($connection) {
    global $globalData;
    // 原子增加在线人数
    $globalData->increment('online_count', 1);
    
    // 广播当前在线人数
    broadcastOnlineCount();
};

$worker->onClose = function ($connection) {
    global $globalData;
    // 原子减少在线人数
    $globalData->decrement('online_count', 1);
    
    // 广播当前在线人数
    broadcastOnlineCount();
};

function broadcastOnlineCount() {
    global $globalData, $worker;
    $count = $globalData->online_count;
    foreach ($worker->connections as $conn) {
        $conn->send(json_encode(['type' => 'online', 'count' => $count]));
    }
}

Worker::runAll();
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
    
    /**
     * 获取锁
     * @param string $key 锁名称
     * @param int $expire 过期时间（秒）
     * @return bool 是否成功
     */
    public function lock(string $key, int $expire = 10): bool
    {
        $lockKey = $this->prefix . $key;
        $expireTime = time() + $expire;
        
        // CAS 操作：如果不存在则设置
        if (!isset($this->client->$lockKey)) {
            $this->client->$lockKey = $expireTime;
            return true;
        }
        
        // 检查是否过期
        if ($this->client->$lockKey < time()) {
            $this->client->$lockKey = $expireTime;
            return true;
        }
        
        return false;
    }
    
    /**
     * 释放锁
     */
    public function unlock(string $key): void
    {
        $lockKey = $this->prefix . $key;
        unset($this->client->$lockKey);
    }
    
    /**
     * 带锁执行
     */
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

// 使用示例
$lock = new DistributedLock();

// 方式一：手动控制
if ($lock->lock('order_123', 30)) {
    try {
        // 处理订单
        processOrder(123);
    } finally {
        $lock->unlock('order_123');
    }
}

// 方式二：自动释放
$lock->withLock('order_123', function () {
    processOrder(123);
}, 30);
```

## API 参考

```php
use Kode\Process\GlobalData\Client;

$client = new Client('127.0.0.1:2207');

// 基本操作
$client->key = 'value';      // 设置
$value = $client->key;        // 获取
isset($client->key);          // 检查存在
unset($client->key);          // 删除

// 原子操作
$client->increment('key', 1); // 自增
$client->decrement('key', 1); // 自减
$client->cas('key', $old, $new); // 比较并交换
$client->add('key', 'value'); // 原子添加

// 批量操作
$client->setMulti(['k1' => 'v1', 'k2' => 'v2']);
$data = $client->getMulti(['k1', 'k2']);
```

## 注意事项

1. **原子操作** - 使用 `increment`、`cas` 等原子操作保证数据一致性
2. **网络延迟** - 每次操作都有网络开销，避免频繁调用
3. **数据大小** - 不要存储过大的数据，影响性能
4. **连接管理** - 在 `onWorkerStart` 中创建连接，避免在回调中重复创建
