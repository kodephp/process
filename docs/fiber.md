# Fiber 协程

集成 `kode/fibers` 包，提供高性能协程能力。

## 基本用法

### 创建协程

```php
use Kode\Process\Kode;

Kode::go(function () {
    echo "协程执行\n";
});

Kode::go(function () {
    return 'hello';
});
```

### 在 Worker 中使用

```php
use Kode\Process\Kode;

Kode::worker('tcp://0.0.0.0:8080', 4)
    ->onMessage(function ($conn, $data) {
        Kode::go(function () use ($conn, $data) {
            $result = slowOperation($data);
            $conn->send($result);
        });
    })
    ->start();
```

## 批量处理

```php
use Kode\Process\Kode;

$items = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

$results = Kode::batch($items, function (int $item) {
    usleep(100000);
    return $item * 2;
}, 3);

print_r($results);
```

## 协程睡眠

```php
use Kode\Process\Kode;

Kode::go(function () {
    echo "开始\n";
    Kode::sleep(1.5);
    echo "结束\n";
});
```

## 上下文传递

```php
use Kode\Process\Kode;

Kode::withContext(['trace_id' => '123', 'user_id' => 456], function () {
    $context = Kode::getContext();
    echo $context['trace_id'];

    Kode::go(function () {
        $context = Kode::getContext();
        echo $context['user_id'];
    });
});
```

## 重试机制

```php
use Kode\Process\Kode;

$result = Kode::retry(function () {
    $response = apiCall();
    if ($response === false) {
        throw new Exception('API 调用失败');
    }
    return $response;
}, 3, 1.0);
```

## 超时控制

```php
use Kode\Process\Kode;

try {
    $result = Kode::timeout(function () {
        return slowOperation();
    }, 5.0);
} catch (TimeoutException $e) {
    echo "操作超时\n";
}
```

## Channel 通道

```php
use Kode\Process\Channel\Channel;

$channel = new Channel(10);

Kode::go(function () use ($channel) {
    for ($i = 0; $i < 10; $i++) {
        $channel->push($i);
        echo "生产: {$i}\n";
    }
    $channel->close();
});

Kode::go(function () use ($channel) {
    while (($data = $channel->pop()) !== null) {
        echo "消费: {$data}\n";
    }
});
```

## WaitGroup 等待组

```php
use Kode\Process\Sync\WaitGroup;

$wg = new WaitGroup();

for ($i = 0; $i < 5; $i++) {
    $wg->add();
    Kode::go(function () use ($wg, $i) {
        defer(fn() => $wg->done());
        echo "任务 {$i} 开始\n";
        usleep(random_int(100000, 500000));
        echo "任务 {$i} 完成\n";
    });
}

$wg->wait();
echo "所有任务完成\n";
```

## 完整示例：并发请求

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

Kode::worker('http://0.0.0.0:8080', 4)
    ->onMessage(function ($conn, $request) {
        Kode::go(function () use ($conn) {
            $urls = [
                'https://api.example.com/users',
                'https://api.example.com/posts',
                'https://api.example.com/comments',
            ];

            $results = Kode::batch($urls, function ($url) {
                return json_decode(file_get_contents($url), true);
            }, 3);

            $conn->send(json_encode([
                'code' => 0,
                'data' => $results
            ]));
        });
    })
    ->start();
```

## 性能建议

1. **避免阻塞操作** - 使用协程友好的 IO 操作
2. **控制并发数** - `batch` 的并发数不要过大
3. **合理使用 Channel** - 适合生产者-消费者模式
4. **异常处理** - 协程中的异常需要捕获处理
