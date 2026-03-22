# 性能优化

本文档介绍如何优化 Kode Process 的性能。

## 压测工具

### 内置压测

```bash
php examples/18-benchmark-compare.php
```

### ab (Apache Benchmark)

```bash
# 安装
sudo apt install apache2-utils

# 压测
ab -n 10000 -c 100 http://localhost:8080/
```

### wrk

```bash
# 安装
sudo apt install wrk

# 压测
wrk -t 4 -c 100 -d 30s http://localhost:8080/
```

## 性能调优

### 1. 进程数配置

```php
// 根据 CPU 核心数设置
$worker->count = cpu_get_count();

// 或手动设置
$worker->count = 4;

// 获取 CPU 核心数
function cpu_get_count(): int {
    if (function_exists('swoole_cpu_num')) {
        return swoole_cpu_num();
    }
    return (int) shell_exec('nproc') ?: 4;
}
```

### 2. 内存优化

```php
// 设置内存限制
ini_set('memory_limit', '512M');

// 定期重启防止内存泄漏
$worker->maxRequests = 10000;

// 在 Worker 中检查内存
$worker->onWorkerStart = function ($worker) {
    Timer::add(60, function () use ($worker) {
        $memory = memory_get_usage(true) / 1024 / 1024;
        if ($memory > 256) {
            echo "Worker {$worker->id} 内存过高: {$memory}MB\n";
            // 可以选择重启
        }
    });
};
```

### 3. 连接优化

```php
// 设置最大连接数
$worker->maxConnections = 10000;

// 设置连接超时
$worker->onConnect = function ($connection) {
    // 60 秒无活动则关闭
    $connection->timeoutTimer = Timer::add(60, function () use ($connection) {
        $connection->close('timeout');
    });
};

$worker->onMessage = function ($connection, $data) {
    // 重置超时
    Timer::del($connection->timeoutTimer);
    // ... 处理消息
};
```

### 4. IO 优化

```php
// 使用协程处理 IO
use Kode\Fibers\Fibers;

$worker->onMessage = function ($connection, $data) {
    Fibers::go(function () use ($connection, $data) {
        // 异步处理
        $result = asyncOperation($data);
        $connection->send($result);
    });
};

// 批量处理
$results = Fibers::batch($items, function ($item) {
    return processItem($item);
}, 10);  // 并发 10
```

### 5. 协议优化

```php
// 使用二进制协议代替 JSON
// JSON 编码
$json = json_encode($data);  // 较慢

// MessagePack（需要扩展）
$msgpack = msgpack_pack($data);  // 更快

// 长度前缀协议
$packed = pack('N', strlen($body)) . $body;  // 最快
```

## 系统优化

### 文件描述符限制

```bash
# 查看当前限制
ulimit -n

# 临时修改
ulimit -n 65535

# 永久修改（/etc/security/limits.conf）
* soft nofile 65535
* hard nofile 65535
```

### 内核参数调优

```bash
# /etc/sysctl.conf

# 最大连接数
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535

# 快速回收 TIME_WAIT
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_tw_recycle = 1

# 应用配置
sudo sysctl -p
```

### OPcache 优化

```ini
; php.ini
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0  ; 生产环境关闭
opcache.save_comments = 0
```

### JIT 编译（PHP 8.0+）

```ini
; php.ini
opcache.jit_buffer_size = 256M
opcache.jit = 1255
```

## 性能监控

### 内置监控

```php
use Kode\Process\Debug\ProcessDebugger;

// 开启调试
ProcessDebugger::enable();

// 获取内存使用
$memory = ProcessDebugger::getMemoryUsage();

// 性能追踪
$id = ProcessDebugger::startTrace('operation');
// ... 操作
$trace = ProcessDebugger::endTrace($id);

// 获取慢操作
$slowTraces = ProcessDebugger::getSlowTraces(1.0);  // 超过 1 秒
```

### 状态监控

```php
use Kode\Process\Debug\StatusMonitor;

$monitor = new StatusMonitor();

// 注册 Worker
$monitor->registerWorker($pid, 'worker-name', 'http://0.0.0.0:8080');

// 更新状态
$monitor->incrementRequests($pid);
$monitor->updateMemory($pid);

// 显示状态
echo $monitor->display();
```

## 压测数据参考

### 测试环境
- PHP 8.3
- 4 核心 CPU
- 8GB 内存

### HTTP 服务

| 指标 | 数值 |
|------|------|
| QPS | 50,000+ |
| 延迟 | < 1ms |
| 内存/进程 | ~10MB |

### WebSocket 服务

| 指标 | 数值 |
|------|------|
| 连接数 | 100,000+ |
| 消息/秒 | 100,000+ |
| 内存/连接 | ~1KB |

### Fiber 协程

| 指标 | 数值 |
|------|------|
| 创建速度 | 139,000+/秒 |
| 上下文切换 | 188,000+/秒 |

## 最佳实践

1. **合理设置进程数** - 通常等于 CPU 核心数
2. **避免阻塞操作** - 使用协程处理 IO
3. **控制内存使用** - 定期重启 Worker
4. **监控性能指标** - 及时发现问题
5. **优化数据库查询** - 使用连接池、缓存
6. **使用 OPcache** - 生产环境必须开启
