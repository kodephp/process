# 性能优化

本文档介绍如何优化 Kode Process 的性能。

## 压测工具

### 内置五维硬门槛压测

本包提供可复现的压测框架，用于对比候选运行时与 Workerman / Swoole：

```bash
php benchmarks/gate/run-gate.php 3 8   # 3 轮，8 个压测连接并发
```

判定标准与实测结论见 [五维硬门槛判定报告](gate-report.md)。

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
use Kode\Process\Kode;

// 根据 CPU 核心数设置
$workers = function_exists('swoole_cpu_num')
    ? swoole_cpu_num()
    : ((int) shell_exec('nproc') ?: 4);

// 通过 listen 选项的 workers 控制进程数
Kode::serve('http://0.0.0.0:8080', ['workers' => $workers])
    ->on('message', fn($conn, $req) => $conn->send('Hello'))
    ->start();
```

### 2. 内存优化

```php
use Kode\Process\Kode;
use Kode\Process\Timer;

// 设置内存限制
ini_set('memory_limit', '512M');

// 定期重启防止内存泄漏：maxRequest 选项让 worker 处理若干请求后自动重启
Kode::serve('http://0.0.0.0:8080', ['workers' => 4, 'maxRequest' => 10000])
    ->on('workerStart', function (int $workerId) {
        Timer::add(60, function () use ($workerId) {
            $memory = memory_get_usage(true) / 1024 / 1024;
            if ($memory > 256) {
                echo "Worker {$workerId} 内存过高: {$memory}MB\n";
            }
        });
    })
    ->on('message', fn($conn, $req) => $conn->send('Hello'))
    ->start();
```

### 3. 连接优化

```php
use Kode\Process\Kode;
use Kode\Process\Timer;

Kode::serve('tcp://0.0.0.0:9000', ['workers' => 4])
    ->on('connect', function ($connection) {
        // 60 秒无活动则关闭
        $connection->setContext('timeoutTimer', Timer::add(60, fn() => $connection->close('timeout')));
    })
    ->on('message', function ($connection, $data) {
        // 重置超时
        Timer::del($connection->getContext('timeoutTimer'));
        // ... 处理消息
    })
    ->start();
```

### 4. IO 优化

```php
// 使用协程处理 IO
use Kode\Process\Kode;

Kode::serve('http://0.0.0.0:8080', ['workers' => 4])
    ->on('message', function ($connection, $data) {
        Kode::go(function () use ($connection, $data) {
            // 异步处理
            $result = asyncOperation($data);
            $connection->send($result);
        });
    })
    ->start();

// 批量处理
$results = Kode::batch($items, function ($item) {
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

> **重要**：端到端吞吐主要由「所选运行时 + 操作系统内核网络栈」决定，而非本包的应用代码。
> 五维硬门槛实测显示，在 PHP 事件驱动服务器领域，Workerman 5 + ext-event 已把 PHP 层压缩到
> 约 13%，余下 87% 是内核网络栈的固有成本——任何新实现（含全 C 的 Swoole）都撞在同一堵墙上。
> 因此本包不承诺「比 Workerman 更快」，而是提供「一套 API 在 Swoole / Workerman 间可移植」的进程编排内核。
> 具体实测数字与判定方法见 [五维硬门槛判定报告](gate-report.md)。

### 测试环境（示例）
- PHP 8.3
- 4~11 核心 CPU
- 8GB+ 内存

### 可优化的方向

| 方向 | 说明 |
|------|------|
| 运行时选择 | 装了 Swoole 优先用 Swoole（自动择优）；否则用 Workerman（纯 PHP，开箱即用），Linux 上装 `ext-event` 吞吐更高 |
| 进程数 | 通常等于 CPU 核心数；IO 密集型可略高 |
| 协议 | 二进制 / 长度前缀协议优于 JSON 文本 |
| 协程 | IO 密集用 `Kode::go` / `Kode::batch` 提升并发 |
| 系统 | OPcache + JIT + 文件描述符 / 内核参数调优 |

## 最佳实践

1. **合理设置进程数** - 通常等于 CPU 核心数
2. **避免阻塞操作** - 使用协程处理 IO
3. **控制内存使用** - 通过 `maxRequest` 定期重启 Worker
4. **监控性能指标** - 及时发现问题
5. **优化数据库查询** - 使用连接池、缓存
6. **使用 OPcache** - 生产环境必须开启
