<?php

declare(strict_types=1);

use Kode\Process\Async\Async;
use Kode\Process\Async\Promise;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Async 异步工具示例 ===\n\n";

// 1. defer - 延迟执行
echo "1. defer - 延迟执行\n";
Async::defer(function () {
    echo "   延迟执行的任务\n";
});
echo "   defer 已注册\n";

Async::runDeferred();

// 2. queueMicrotask - 微任务队列
echo "\n2. queueMicrotask - 微任务队列\n";
Async::queueMicrotask(function () {
    echo "   微任务 1\n";
});
Async::queueMicrotask(function () {
    echo "   微任务 2\n";
});
echo "   微任务已注册\n";

Async::runMicrotasks();

// 3. nextTick - 下一轮执行
echo "\n3. nextTick - 下一轮执行\n";
Async::nextTick(function () {
    echo "   nextTick 回调\n";
});
Async::runMicrotasks();

// 4. 事件订阅
echo "\n4. 事件订阅\n";
Async::on('custom.event', function ($data) {
    echo "   收到事件: " . json_encode($data) . "\n";
});

Async::once('once.event', function () {
    echo "   一次性事件触发\n";
});

Async::emit('custom.event', ['message' => 'Hello']);
Async::emit('once.event');
Async::emit('once.event'); // 不会再次触发

// 5. promisify 示例
echo "\n5. promisify - 回调风格转 Promise\n";

// 模拟一个回调风格的函数
function fetchData(string $url, callable $callback): void
{
    // 模拟异步获取数据
    $data = ['url' => $url, 'content' => '模拟内容'];
    $callback(null, $data);
}

$fetchDataAsync = Async::promisify('fetchData');

$fetchDataAsync('https://api.example.com/users')
    ->then(fn($data) => print "   获取数据: " . json_encode($data) . "\n")
    ->catch(fn($e) => print "   错误: {$e}\n");

Async::runMicrotasks();

// 6. Promise 组合
echo "\n6. Promise 组合 - 并行请求\n";

function mockHttpRequest(string $url): Promise
{
    return new Promise(function ($resolve) use ($url) {
        // 模拟网络延迟
        Async::queueMicrotask(function () use ($url, $resolve) {
            $resolve([
                'url' => $url,
                'status' => 200,
                'data' => ['result' => 'ok']
            ]);
        });
    });
}

$requests = [
    'api1' => mockHttpRequest('https://api1.example.com'),
    'api2' => mockHttpRequest('https://api2.example.com'),
    'api3' => mockHttpRequest('https://api3.example.com'),
];

Async::all($requests)->then(function ($results) {
    echo "   所有请求完成:\n";
    foreach ($results as $key => $result) {
        echo "   - {$key}: {$result['url']} ({$result['status']})\n";
    }
});

Async::runMicrotasks();

// 7. 错误处理
echo "\n7. 错误处理\n";

function mockFailingRequest(): Promise
{
    return new Promise(function ($_, $reject) {
        Async::queueMicrotask(function () use ($reject) {
            $reject(new \RuntimeException('网络错误'));
        });
    });
}

Async::any([
    mockFailingRequest(),
    mockFailingRequest(),
    Promise::resolve(['fallback' => true]),
])->then(
    fn($result) => print "   至少一个成功: " . json_encode($result) . "\n",
    fn($e) => print "   全部失败: {$e->getMessage()}\n"
);

Async::runMicrotasks();

// 8. 链式异步操作
echo "\n8. 链式异步操作\n";

function step1(): Promise
{
    return Promise::resolve(1);
}

function step2(int $value): Promise
{
    return Promise::resolve($value * 2);
}

function step3(int $value): Promise
{
    return Promise::resolve($value + 10);
}

step1()
    ->then(fn($v) => step2($v))
    ->then(fn($v) => step3($v))
    ->then(fn($v) => print "   链式结果: {$v}\n");

Async::runMicrotasks();

// 9. 超时控制
echo "\n9. 超时控制\n";

function withTimeout(Promise $promise, float $seconds): Promise
{
    return Async::race([
        $promise,
        new Promise(function ($_, $reject) use ($seconds) {
            Async::defer(function () use ($reject, $seconds) {
                $reject(new \RuntimeException("超时 {$seconds} 秒"));
            });
        })
    ]);
}

$slowRequest = new Promise(function ($resolve) {
    // 模拟慢请求
    // 实际使用时会等待
});

withTimeout($slowRequest, 5.0)
    ->then(fn($v) => print "   结果: {$v}\n")
    ->catch(fn($e) => print "   超时处理: {$e->getMessage()}\n");

Async::runDeferred();
Async::runMicrotasks();

echo "\n=== 示例完成 ===\n";
