# Fiber 协程

集成 `kode/fibers` 包，提供高性能协程能力。

## 基本用法

### 创建协程

```php
use Kode\Fibers\Fibers;

// 创建并启动协程
Fibers::go(function () {
    echo "协程执行\n";
});

// 获取返回值
$result = Fibers::go(function () {
    return 'hello';
});
echo $result;  // hello
```

### 在 Worker 中使用

```php
use Kode\Process\Compat\Worker;
use Kode\Fibers\Fibers;

$worker = new Worker('tcp://0.0.0.0:8080');

$worker->onMessage = function ($connection, $data) {
    // 在协程中处理耗时操作
    Fibers::go(function () use ($connection, $data) {
        // 模拟耗时操作
        $result = slowOperation($data);
        $connection->send($result);
    });
};

Worker::runAll();
```

## 批量处理

```php
use Kode\Fibers\Fibers;

// 并发处理多个任务
$items = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

$results = Fibers::batch($items, function (int $item) {
    // 每个任务在协程中执行
    usleep(100000);  // 模拟 IO
    return $item * 2;
}, 3);  // 并发数为 3

print_r($results);  // [2, 4, 6, 8, 10, 12, 14, 16, 18, 20]
```

## 协程睡眠

```php
use Kode\Fibers\Fibers;

Fibers::go(function () {
    echo "开始\n";
    Fibers::sleep(1.5);  // 睡眠 1.5 秒
    echo "结束\n";
});
```

## 上下文传递

```php
use Kode\Fibers\Fibers;

// 设置上下文
Fibers::withContext(['trace_id' => '123', 'user_id' => 456], function () {
    // 在协程中获取上下文
    $context = Fibers::getContext();
    echo $context['trace_id'];  // 123
    
    // 嵌套协程
    Fibers::go(function () {
        $context = Fibers::getContext();
        echo $context['user_id'];  // 456
    });
});
```

## 重试机制

```php
use Kode\Fibers\Fibers;

$result = Fibers::retry(function () {
    // 可能失败的操作
    $response = apiCall();
    if ($response === false) {
        throw new Exception('API 调用失败');
    }
    return $response;
}, 3, 1.0);  // 重试 3 次，间隔 1 秒
```

## 超时控制

```php
use Kode\Fibers\Fibers;

try {
    $result = Fibers::timeout(function () {
        return slowOperation();
    }, 5.0);  // 5 秒超时
} catch (TimeoutException $e) {
    echo "操作超时\n";
}
```

## Channel 通道

```php
use Kode\Fibers\Channel\Channel;

$channel = new Channel(10);

// 生产者
Fibers::go(function () use ($channel) {
    for ($i = 0; $i < 10; $i++) {
        $channel->push($i);
        echo "生产: {$i}\n";
    }
    $channel->close();
});

// 消费者
Fibers::go(function () use ($channel) {
    while (($data = $channel->pop()) !== null) {
        echo "消费: {$data}\n";
    }
});
```

## WaitGroup 等待组

```php
use Kode\Fibers\Sync\WaitGroup;

$wg = new WaitGroup();

for ($i = 0; $i < 5; $i++) {
    $wg->add();
    Fibers::go(function () use ($wg, $i) {
        defer(fn() => $wg->done());
        echo "任务 {$i} 开始\n";
        usleep(random_int(100000, 500000));
        echo "任务 {$i} 完成\n";
    });
}

// 等待所有任务完成
$wg->wait();
echo "所有任务完成\n";
```

## 完整示例：并发请求

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Fibers\Fibers;

$worker = new Worker('http://0.0.0.0:8080');

$worker->onMessage = function ($connection, $request) {
    Fibers::go(function () use ($connection) {
        // 并发请求多个 API
        $urls = [
            'https://api.example.com/users',
            'https://api.example.com/posts',
            'https://api.example.com/comments',
        ];
        
        $results = Fibers::batch($urls, function ($url) {
            // 模拟 HTTP 请求
            $response = file_get_contents($url);
            return json_decode($response, true);
        }, 3);  // 并发 3 个请求
        
        $connection->send(json_encode([
            'code' => 0,
            'data' => $results
        ]));
    });
};

Worker::runAll();
```

## 性能建议

1. **避免阻塞操作** - 使用协程友好的 IO 操作
2. **控制并发数** - `batch` 的并发数不要过大
3. **合理使用 Channel** - 适合生产者-消费者模式
4. **异常处理** - 协程中的异常需要捕获处理
