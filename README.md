# Kode Process

**高性能 PHP 进程与线程管理器，为高并发而生**

## ✨ 特性

- 🚀 **极简 API** - 一行代码启动服务器，自动检测协议
- 🔄 **Workerman 兼容** - 无缝迁移，零学习成本
- 📡 **多协议支持** - HTTP、WebSocket、TCP、Text 自动识别，支持自定义协议
- 🌐 **分布式集群** - 跨机器多进程、Channel 通讯、自动选举、故障转移
- ⚡ **Fiber 协程** - 集成 kode/fibers 包，百万级并发支持
- ⏱️ **定时器系统** - 一次性、永久、指定次数、Cron 表达式
- 📢 **异步通知** - EventEmitter、Promise、Async 工具
- 🔌 **框架集成** - Laravel、Symfony 一键集成
- 📊 **进程监控** - 心跳检测、自动重启、负载均衡
- 🛡️ **安全健壮** - 进程隔离、资源限制、优雅关闭

## 📦 安装

```bash
composer require kode/process
```

## 🚀 快速开始

### 方式一：极简风格（推荐）

```php
use Kode\Process\Kode;

// 一行启动 HTTP 服务器
Kode::http('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => $conn->send('Hello'))
    ->start();

// 一行启动 WebSocket 服务器
Kode::websocket('websocket://0.0.0.0:8081', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();

// 一行启动 TCP 服务器
Kode::tcp('tcp://0.0.0.0:9000', 4)
    ->onMessage(fn($conn, $data) => $conn->send($data))
    ->start();

// 自动检测协议
Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(fn($conn, $data) => handleRequest($data))
    ->start();
```

### 方式二：Workerman 兼容（零学习成本）

```php
use Kode\Process\Compat\Worker;
use Kode\Process\Compat\Timer;

$worker = new Worker('tcp://0.0.0.0:8080');
$worker->count = 4;
$worker->onMessage = function ($connection, $data) {
    $connection->send("Hello: {$data}");
};
Worker::runAll();
```

## ⏱️ Timer 定时器

定时器在当前进程中运行，不会创建新的进程或线程。

### 基本用法

```php
use Kode\Process\Compat\Timer;

// 永久定时器 - 每 2.5 秒执行一次
$timerId = Timer::add(2.5, function () {
    echo "task run\n";
});

// 一次性定时器 - 10 秒后执行一次
Timer::add(10, function () {
    echo "send mail\n";
}, [], false);  // 第 4 个参数 false 表示只执行一次

// 带参数的定时器
Timer::add(10, function ($to, $content) {
    echo "send mail to {$to}: {$content}\n";
}, ['workerman@example.com', 'hello'], false);

// 删除定时器
Timer::del($timerId);
```

### 在连接中使用定时器

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->onConnect = function ($connection) {
    // 每 10 秒发送一次连接时间
    $connection->timerId = Timer::add(10, function () use ($connection) {
        $connection->send(time());
    });
};

$worker->onClose = function ($connection) {
    // 连接关闭时删除定时器
    Timer::del($connection->timerId);
};

Worker::runAll();
```

### 定时器注意事项

1. **只能在 onXXXX 回调中添加定时器**，推荐在 `onWorkerStart` 中设置全局定时器
2. **繁重任务会影响当前进程**，建议放到单独的 Worker 进程运行
3. **多进程设置定时任务可能造成并发问题**，如需单进程运行，判断 `$worker->id`
4. **定时器不能跨进程删除**
5. **不同进程间的定时器 ID 可能重复**，同一进程内不会重复

### 定时器方法

```php
// 永久定时器
$timerId = Timer::forever(1.0, fn() => echo "每秒执行\n");

// 一次性定时器
$timerId = Timer::once(1.0, fn() => echo "1秒后执行\n");

// 指定次数
$timerId = Timer::repeat(0.5, fn() => echo "执行\n", 5);

// 立即执行
$timerId = Timer::immediate(fn() => echo "立即执行\n");

// Cron 表达式
$timerId = Timer::cron('* * * * *', fn() => echo "每分钟执行\n");

// 暂停/恢复
Timer::pause($timerId);
Timer::resume($timerId);

// 删除
Timer::del($timerId);
Timer::delAll();

// 统计
$stats = Timer::getStats();
```

## 📡 协议系统

### 内置协议

```php
use Kode\Process\Compat\Worker;

// HTTP 协议
$httpWorker = new Worker('http://0.0.0.0:8080');

// WebSocket 协议
$wsWorker = new Worker('websocket://0.0.0.0:8081');

// Text 协议（文本+换行符）
$textWorker = new Worker('text://0.0.0.0:8082');

// TCP 原始协议
$tcpWorker = new Worker('tcp://0.0.0.0:8083');

// UDP 协议
$udpWorker = new Worker('udp://0.0.0.0:8084');
```

### 自定义协议

自定义协议需要实现三个静态方法：`input`、`encode`、`decode`。

#### 示例：JsonNL 协议（JSON + 换行符）

```php
namespace Kode\Process\Protocol;

use Kode\Process\Protocol\ProtocolInterface;

class JsonNLProtocol implements ProtocolInterface
{
    public static function getName(): string
    {
        return 'json-nl';
    }

    /**
     * 检查包完整性，返回包长度
     * 返回 0 表示需要更多数据
     * 返回 -1 表示协议错误，连接会断开
     */
    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, "\n");
        
        if ($pos === false) {
            return 0;  // 没有换行符，继续等待数据
        }
        
        return $pos + 1;  // 返回包长（包含换行符）
    }

    /**
     * 打包，发送数据时自动调用
     */
    public static function encode(mixed $data, mixed $connection = null): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * 解包，接收数据时自动调用
     */
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        return json_decode(trim($buffer), true);
    }
}
```

#### 示例：首部长度协议

```php
// 首部 4 字节网络字节序 unsigned int 标记包长度
class JsonIntProtocol implements ProtocolInterface
{
    public static function getName(): string
    {
        return 'json-int';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        if (strlen($buffer) < 4) {
            return 0;
        }
        
        $data = unpack('Ntotal_length', $buffer);
        $totalLength = $data['total_length'];
        
        if (strlen($buffer) < $totalLength) {
            return 0;
        }
        
        return $totalLength;
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $totalLength = 4 + strlen($body);
        
        return pack('N', $totalLength) . $body;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $body = substr($buffer, 4);
        return json_decode($body, true);
    }
}
```

### 协议使用

```php
use Kode\Process\Compat\Worker;

$worker = new Worker('JsonInt://0.0.0.0:1234');
$worker->onMessage = function ($connection, $data) {
    // $data 已经自动解码
    echo $data['type'] ?? '';
    
    // 发送数据会自动编码
    $connection->send(['code' => 0, 'msg' => 'ok']);
};

Worker::runAll();
```

## 📢 Channel 分布式通讯

Channel 是基于订阅发布模型的分布式通讯组件，用于进程间或服务器间通讯。

### Channel 服务端

```php
use Kode\Process\Channel\Server;

// 启动 Channel 服务端（整个系统只需一个）
$server = new Server('0.0.0.0', 2206);
$server->start();
```

### Channel 客户端

```php
use Kode\Process\Channel\Client;
use Kode\Process\Compat\Worker;

$worker = new Worker('websocket://0.0.0.0:4236');
$worker->count = 4;

$worker->onWorkerStart = function ($worker) {
    // 连接 Channel 服务端
    Client::connect('127.0.0.1', 2206);
    
    // 订阅事件
    Client::on('broadcast', function ($data) use ($worker) {
        foreach ($worker->connections as $conn) {
            $conn->send($data['message']);
        }
    });
    
    // 订阅特定连接的消息
    Client::on('send_to_connection', function ($data) use ($worker) {
        $connId = $data['connection_id'];
        if (isset($worker->connections[$connId])) {
            $worker->connections[$connId]->send($data['message']);
        }
    });
};

$worker->onMessage = function ($connection, $data) {
    // 发布事件
    Client::publish('user_message', [
        'connection_id' => $connection->id,
        'message' => $data
    ]);
};

Worker::runAll();
```

### 分布式推送系统示例

```php
// HTTP 推送服务
$httpWorker = new Worker('http://0.0.0.0:4237');
$httpWorker->onWorkerStart = function () {
    Client::connect('192.168.1.1', 2206);
};

$httpWorker->onMessage = function ($connection, $request) {
    $content = $request['content'] ?? '';
    
    if (isset($request['to_worker_id'], $request['to_connection_id'])) {
        // 推送给特定连接
        Client::publish($request['to_worker_id'], [
            'to_connection_id' => $request['to_connection_id'],
            'content' => $content
        ]);
    } else {
        // 广播给所有连接
        Client::publish('broadcast', ['content' => $content]);
    }
    
    $connection->send('ok');
};
```

### Channel API

```php
use Kode\Process\Channel\Client;

// 连接服务端
Client::connect('127.0.0.1', 2206);

// 订阅事件
Client::on('event_name', function ($data) {
    echo "收到事件: " . json_encode($data);
});

// 取消订阅
Client::off('event_name');

// 发布事件
Client::publish('event_name', ['key' => 'value']);

// 处理接收的消息（在主循环中调用）
Client::tick();

// 检查连接状态
Client::isConnected();

// 重连
Client::reconnect();

// 断开连接
Client::disconnect();
```

## 🌐 GlobalData 全局数据共享

跨进程共享数据的组件。

### 服务端

```php
use Kode\Process\GlobalData\Server;

$server = new Server('0.0.0.0', 2207);
$server->start();
```

### 客户端

```php
use Kode\Process\GlobalData\Client;

$client = new Client('127.0.0.1:2207');

// 魔术方法访问
$client->counter = 0;
$client->counter++;
echo $client->counter;

// 检查存在
isset($client->counter);

// 删除
unset($client->counter);

// 原子操作
$client->increment('counter', 1);
$client->decrement('counter', 1);

// CAS（比较并交换）
$client->cas('counter', 10, 20);

// 批量操作
$client->setMulti(['key1' => 'val1', 'key2' => 'val2']);
$data = $client->getMulti(['key1', 'key2']);
```

## 📁 文件监控

开发环境下自动监控文件变更并重载。

```php
use Kode\Process\Monitor\FileMonitor;

$monitor = new FileMonitor(['/path/to/src']);
$monitor->setExtensions(['.php'])
    ->addExcludeDir('vendor')
    ->setOnChange(function ($changes) {
        echo "检测到文件变更:\n";
        print_r($changes);
        // 执行重载逻辑
    });

$monitor->start();
```

## 📮 队列系统

### 快速开始

```php
use Kode\Process\Queue\QueueManager;
use Kode\Process\Queue\Job;

// 注册任务处理器
QueueManager::getInstance()
    ->register('send_email', function (array $data) {
        // 发送邮件逻辑
        mail($data['to'], $data['subject'], $data['body']);
        return ['status' => 'sent'];
    })
    ->register('process_image', function (array $data) {
        // 图片处理逻辑
        return ['status' => 'processed'];
    });

// 推送任务到队列
QueueManager::getInstance()->dispatch('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);

// 延迟任务（10 秒后执行）
QueueManager::getInstance()->dispatchDelayed('send_email', [
    'to' => 'user@example.com',
    'subject' => 'Delayed',
    'body' => 'Message'
], 10);
```

### 任务类定义

```php
use Kode\Process\Queue\Job;
use Kode\Process\Response;

class SendEmailJob extends Job
{
    protected int $maxTries = 3;      // 最大重试次数
    protected int $timeout = 120;     // 超时时间（秒）
    protected int $delay = 0;         // 延迟执行（秒）
    protected ?string $queue = 'emails';  // 指定队列

    public function handle(): Response
    {
        $to = $this->data['to'] ?? '';
        $subject = $this->data['subject'] ?? '';
        $body = $this->data['body'] ?? '';

        // 发送邮件逻辑
        $success = mail($to, $subject, $body);

        return $success
            ? Response::ok(['to' => $to])
            : Response::error('发送失败');
    }
}

// 分发任务
SendEmailJob::dispatch([
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
])->onQueue('emails')->delay(60)->tries(5);
```

### 队列消费者

```php
use Kode\Process\Queue\QueueWorker;

$worker = QueueWorker::create(['worker_count' => 4])
    ->on('send_email', function ($data) {
        // 处理邮件发送
        return ['status' => 'sent'];
    })
    ->on('process_image', function ($data) {
        // 处理图片
        return ['status' => 'processed'];
    })
    ->queue('default')
    ->maxJobs(10000)      // 处理 10000 个任务后重启
    ->maxMemory(128 * 1024 * 1024)  // 内存限制 128MB
    ->start();
```

### 队列适配器

```php
use Kode\Process\Queue\QueueManager;
use Kode\Process\Queue\MemoryAdapter;

// 使用内存队列（开发测试）
QueueManager::useMemory();

// 使用 Redis 队列（生产环境）
QueueManager::useRedis('127.0.0.1', 6379, 'password', 0);

// 自定义适配器
$adapter = new MemoryAdapter();
QueueManager::setAdapter($adapter);
```

### 队列统计

```php
// 获取队列统计信息
$stats = QueueManager::getInstance()->stats('default');
// ['waiting' => 10, 'delayed' => 5, 'reserved' => 2, 'failed' => 1]

// 获取队列大小
$size = QueueManager::getInstance()->size('default');

// 清空队列
QueueManager::getInstance()->clear('default');
```

## ⏰ Crontab 定时任务

```php
use Kode\Process\Crontab\Crontab;

// 每分钟第 1 秒执行
new Crontab('1 * * * * *', function () {
    echo date('Y-m-d H:i:s') . "\n";
});

// 每天 7:50 执行（省略秒位）
new Crontab('50 7 * * *', function () {
    echo "早安提醒\n";
});

// 销毁定时器
$crontab->destroy();
```

## 🔧 调试工具

### 状态监控

```php
use Kode\Process\Debug\StatusMonitor;

$monitor = new StatusMonitor();

// 注册 Worker
$monitor->registerWorker($pid, 'worker-name', 'http://0.0.0.0:8080');

// 更新状态
$monitor->incrementRequests($pid);
$monitor->updateMemory($pid);

// 保存状态
$monitor->save();

// 显示状态
echo $monitor->display();
```

### 进程调试

```php
use Kode\Process\Debug\ProcessDebugger;

// 开启调试
ProcessDebugger::enable();

// 性能追踪
$id = ProcessDebugger::startTrace('database_query');
// ... 执行操作
$trace = ProcessDebugger::endTrace($id);

// 性能分析
$result = ProcessDebugger::profile(function () {
    return expensiveOperation();
}, 'expensive_op');

// 内存使用
$memory = ProcessDebugger::getMemoryUsage();

// 慢日志
ProcessDebugger::setSlowThreshold(1.0);  // 1 秒
$slowTraces = ProcessDebugger::getSlowTraces();
```

## 💓 心跳检测

### 进程心跳

```php
use Kode\Process\Monitor\Heartbeat;

$heartbeat = new Heartbeat();
$heartbeat->setTimeout(30.0);

// 注册进程
$heartbeat->register($pid);

// 发送心跳
$heartbeat->beat($pid);

// 检查超时
$results = $heartbeat->check();

// 超时回调
$heartbeat->onTimeout(function ($pid, $elapsed) {
    echo "进程 {$pid} 超时 {$elapsed} 秒\n";
});
```

### 连接心跳

```php
use Kode\Process\Monitor\ConnectionHeartbeat;

$heartbeat = new ConnectionHeartbeat(55, 110);  // 55秒间隔，110秒超时

// 注册连接
$heartbeat->register($connectionId);

// 更新活动时间
$heartbeat->updateActivity($connectionId);

// 检查并发送心跳
$heartbeat->sendHeartbeats();

// 超时回调
$heartbeat->onTimeout(function ($connectionId, $connection) {
    // 关闭连接
});
```

## 📡 UDP 服务

```php
use Kode\Process\Server\UdpServer;

$server = new UdpServer('0.0.0.0:9292');

$server->onMessage(function ($client, $data) {
    echo "收到来自 {$client->getAddress()} 的数据: {$data}\n";
    $client->send("已收到: {$data}");
});

$server->start();
```

## 📢 广播系统

```php
use Kode\Process\Broadcast\Broadcaster;

$broadcaster = Broadcaster::getInstance();

// 注册连接
$broadcaster->register($connection, 'user_123');

// 加入群组
$broadcaster->joinGroup($connection, 'room_1');

// 全局广播
$broadcaster->broadcast('系统消息');

// 群组广播
$broadcaster->broadcastToGroup('room_1', '群组消息');

// 发送给特定用户
$broadcaster->sendToUid('user_123', '私人消息');

// 断开连接时注销
$broadcaster->unregister($connection);
```

## ⚡ 异步任务

```php
use Kode\Process\Task\AsyncTask;
use Kode\Process\Task\TaskClient;

// 任务服务端
$taskServer = new AsyncTask('0.0.0.0:12345', 100);  // 100 个工作进程

$taskServer->onTask(function ($data, $type) {
    // 处理繁重任务
    if ($type === 'send_mail') {
        // 发送邮件...
        return ['status' => 'sent'];
    }
    
    return ['status' => 'done'];
});

$taskServer->start();

// 任务客户端
$client = new TaskClient('127.0.0.1:12345');

// 异步发送任务
$client->sendAsync('send_mail', [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'World'
]);

// 同步发送并获取结果
$client->send('send_mail', $data, function ($result) {
    echo "任务完成: " . json_encode($result);
});
```

## 🔒 SSL/TLS 支持

```php
use Kode\Process\Ssl\SslContext;

// 从证书文件创建
$ssl = SslContext::fromFiles('/path/to/cert.pem', '/path/to/key.pem');

// 或从目录创建
$ssl = SslContext::fromPath('/etc/ssl/certs');

// 配置选项
$ssl->setVerifyPeer(true)
    ->setVerifyHost(true)
    ->allowSelfSigned(false)
    ->setCiphers('HIGH:!aNULL:!MD5');

// 创建 SSL 服务
$socket = $ssl->createServerSocket('0.0.0.0', 443);
```

## 🔄 平滑重载

```php
use Kode\Process\Reload\HotReloader;

$reloader = HotReloader::getInstance();

// 设置最大请求数后自动重载
$reloader->setMaxRequests(10000);

// 重载回调
$reloader->onReload(function () {
    echo "进程重载中...\n";
});

// 在请求处理中递增计数
$reloader->increment();

// 检查是否需要重载
if ($reloader->check()) {
    $reloader->triggerReload();
}

// 获取进度
$progress = $reloader->getProgressPercent();  // 0-100
```

## 🔐 连接认证

```php
use Kode\Process\Auth\ConnectionAuth;

$auth = ConnectionAuth::getInstance();

// 设置超时时间（秒）
$auth->setTimeout(30);

// 注册未认证连接
$auth->register($connection);

// 认证成功
$auth->authenticate($connection);

// 超时回调
$auth->onTimeout(function ($connection) {
    echo "连接超时未认证，已关闭\n";
    $connection->close();
});

// 认证成功回调
$auth->onAuth(function ($connection) {
    echo "连接认证成功\n";
});

// 检查超时连接
$expired = $auth->checkTimeouts();
```

## ⚡ Fiber 协程支持

集成 `kode/fibers` 包，提供高性能协程能力：

### 基础使用

```php
use Kode\Fibers\Fibers;
use Kode\Process\Compat\Worker;

$worker = new Worker('websocket://0.0.0.0:8080');

$worker->onMessage = function ($connection, $data) {
    Fibers::go(function () use ($connection, $data) {
        $result = heavyOperation($data);
        $connection->send($result);
    });
};

Worker::runAll();
```

### 批量处理

```php
use Kode\Fibers\Fibers;

$results = Fibers::batch(
    [1, 2, 3, 4, 5],
    fn(int $item) => $item * 2,
    3
);
```

### 更多功能

```php
use Kode\Fibers\Fibers;

Fibers::go(fn() => 'hello');
Fibers::withContext(['trace_id' => '123'], fn() => doSomething());
Fibers::sleep(1.5);
Fibers::retry(fn() => riskyOperation(), 3, 0.5);
```

## 📊 性能压测对比

### 测试环境
- **PHP 版本**: 8.3.30
- **CPU**: 4 核心
- **内存**: 8GB
- **OPcache**: 开启

### Kode Process vs Workerman

| 指标 | Kode Process | Workerman | 性能提升 |
|------|--------------|-----------|----------|
| **HTTP QPS** | 55,000+ | 45,000+ | **+22.2%** |
| **WebSocket 连接数** | 120,000+ | 100,000+ | **+20%** |
| **TCP QPS** | 80,000+ | 70,000+ | **+14.3%** |
| **Fiber 创建** | 139,013/s | ~100,000/s | **+39%** |
| **上下文切换** | 187,928/s | ~150,000/s | **+25.3%** |
| **内存/进程** | ~8MB | ~10MB | **-20%** |

### 详细压测数据

| 测试项 | 迭代次数 | 耗时(ms) | 操作/秒 |
|--------|----------|-----------|---------|
| **Response 格式化** | 10,000 | 7.97 | 1,253,603 |
| **JSON 序列化** | 10,000 | 5.79 | 1,726,477 |
| **进程 Fork** | 100 | 144.72 | 691 forks/s |
| **Fibers::go()** | 10,000 | 71.94 | 139,013 |
| **上下文切换** | 100,000 | 532.12 | 187,928 |

### 内存使用对比

| 场景 | Kode Process | Workerman | 差异 |
|------|--------------|-----------|------|
| 空闲进程 | 6MB | 8MB | -25% |
| 1000 连接 | 8MB | 10MB | -20% |
| 10000 连接 | 15MB | 20MB | -25% |

> 💡 运行 `php examples/18-benchmark-compare.php` 获取实时压测数据
> 📄 详见 [benchmark.md](docs/benchmark.md)

## 📁 项目结构

```
src/
├── Kode.php                 # 静态入口类
├── Application.php          # 应用主类
├── Server.php               # 服务器类
├── GlobalProcessManager.php # 全局进程管理器
├── Response.php             # 标准响应格式
├── Version.php              # 版本管理
├── PhpCompat.php            # PHP 版本兼容
├── Protocol/                # 协议系统
│   ├── ProtocolInterface.php
│   ├── HttpProtocol.php
│   ├── WebSocketProtocol.php
│   ├── TcpProtocol.php
│   ├── TextProtocol.php
│   ├── LengthPrefix.php     # 长度前缀协议
│   └── BinaryFile.php       # 二进制文件协议
├── Server/                  # 服务器组件
│   ├── UdpServer.php
│   └── UdpClient.php
├── Channel/                 # 分布式通讯
│   ├── Server.php
│   └── Client.php
├── GlobalData/              # 全局数据共享
│   ├── Server.php
│   └── Client.php
├── Broadcast/               # 广播系统
│   └── Broadcaster.php
├── Task/                    # 异步任务
│   ├── AsyncTask.php
│   └── TaskClient.php
├── Ssl/                     # SSL/TLS
│   └── SslContext.php
├── Auth/                    # 连接认证
│   └── ConnectionAuth.php
├── Reload/                  # 平滑重载
│   └── HotReloader.php
├── Crontab/                 # 定时任务
│   └── Crontab.php
├── Monitor/                 # 监控组件
│   ├── FileMonitor.php
│   ├── Heartbeat.php
│   └── ConnectionHeartbeat.php
├── Debug/                   # 调试工具
│   ├── StatusMonitor.php
│   └── ProcessDebugger.php
├── Async/                   # 异步工具
│   ├── Async.php
│   ├── EventEmitter.php
│   ├── Promise.php
│   └── HttpClient.php
├── Cluster/                 # 分布式集群
├── Integration/             # 框架集成
├── Compat/                  # Workerman 兼容层
├── Worker/                  # Worker 进程池
├── Master/                  # Master 进程管理
├── IPC/                     # 进程间通信
├── Signal/                  # 信号处理
└── Benchmark/               # 性能测试
```

## ✅ 测试

```bash
./vendor/bin/phpunit tests/
```

## 📄 许可证

Apache License 2.0
