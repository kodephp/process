<?php

declare(strict_types=1);

use Kode\Process\Async\Promise;
use Kode\Process\Async\Async;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Promise 异步承诺示例 ===\n\n";

// 1. 创建 Promise
echo "1. 创建 Promise\n";
$promise = new Promise(function ($resolve, $reject) {
    $success = true;

    if ($success) {
        $resolve('操作成功');
    } else {
        $reject('操作失败');
    }
});

$promise->then(
    fn($value) => print "   成功: {$value}\n",
    fn($reason) => print "   失败: {$reason}\n"
);

Async::runMicrotasks();

// 2. 静态方法
echo "\n2. 静态方法\n";
$resolved = Promise::resolve('已解决');
$rejected = Promise::reject('已拒绝');

echo "   resolved 状态: " . $resolved->getState() . "\n";
echo "   rejected 状态: " . $rejected->getState() . "\n";

// 3. 链式调用
echo "\n3. 链式调用\n";
Promise::resolve(1)
    ->then(fn($v) => $v * 2)
    ->then(fn($v) => $v + 10)
    ->then(fn($v) => print "   链式结果: {$v}\n")
    ->finally(fn() => print "   finally 执行\n");

Async::runMicrotasks();

// 4. 错误处理
echo "\n4. 错误处理\n";
Promise::reject('出错了')
    ->then(fn($v) => print "不会执行\n")
    ->catch(fn($e) => print "   捕获错误: {$e}\n")
    ->finally(fn() => print "   清理资源\n");

Async::runMicrotasks();

// 5. Promise.all - 并行执行
echo "\n5. Promise.all - 并行执行\n";
$promises = [
    'user' => Promise::resolve(['id' => 1, 'name' => '张三']),
    'posts' => Promise::resolve([['id' => 1, 'title' => '文章1']]),
    'comments' => Promise::resolve([['id' => 1, 'content' => '评论1']]),
];

Promise::all($promises)->then(function ($results) {
    echo "   用户: " . json_encode($results['user']) . "\n";
    echo "   文章: " . json_encode($results['posts']) . "\n";
    echo "   评论: " . json_encode($results['comments']) . "\n";
});

Async::runMicrotasks();

// 6. Promise.race - 竞速
echo "\n6. Promise.race - 竞速\n";
Promise::race([
    Promise::resolve('第一个'),
    Promise::resolve('第二个'),
])->then(fn($v) => print "   最快的结果: {$v}\n");

Async::runMicrotasks();

// 7. Promise.allSettled - 全部完成
echo "\n7. Promise.allSettled - 全部完成\n";
Promise::allSettled([
    Promise::resolve('成功'),
    Promise::reject('失败'),
    Promise::resolve('另一个成功'),
])->then(function ($results) {
    foreach ($results as $i => $result) {
        echo "   [{$i}] {$result['status']}\n";
    }
});

Async::runMicrotasks();

// 8. promisify - 回调转 Promise
echo "\n8. promisify - 回调转 Promise\n";
$asyncFileGetContents = Async::promisify(function ($url, $callback) {
    // 模拟异步操作
    $callback(null, "内容: {$url}");
});

$asyncFileGetContents('https://example.com')
    ->then(fn($content) => print "   获取到: {$content}\n");

Async::runMicrotasks();

echo "\n=== 示例完成 ===\n";
