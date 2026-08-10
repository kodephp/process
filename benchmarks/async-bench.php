<?php

/**
 * 异步子系统微基准（v5.2.26 优化对照）。
 *
 * 测量三项本轮优化的实际收益：
 *   1. Async 定时器扫描：当前 O(1) 提前返回 vs 旧版每 tick 全量 O(N) 扫描
 *   2. Promise 链路分配：subscribe 消除内部一次性 Promise 后，深链分配下降
 *   3. Async::each/map 真实并发上限（行为正确性，非纯速度）
 *
 * 用法：php benchmarks/async-bench.php
 * 依赖：composer 已安装（vendor/autoload.php）。
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Async\Async;
use Kode\Process\Async\Promise;

function bench(string $label, int $n, callable $fn): float
{
    for ($i = 0; $i < 2000; $i++) {
        $fn();
    }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $ms = (hrtime(true) - $t) / 1e6;

    printf("  %-42s %8.3f ms  (%d iters)\n", $label, $ms, $n);

    return $ms;
}

echo "=== 1) Async 定时器扫描：O(1) 提前返回 vs 旧版全量扫描 ===\n";

// 注册 2000 个「远未来」定时器（均不触发），反复调用 processTimers 模拟空闲循环。
$N = 2000;
$M = 20000;

function seedTimers(int $n): void
{
    Async::reset();
    for ($i = 0; $i < $n; $i++) {
        Async::setTimeout(fn() => null, 3600.0 + $i);
    }
}

// 旧版行为的参考实现：每轮全量扫描 timers + intervals，无到期点缓存。
function naiveProcessTimers(array &$timers, array &$intervals, float $now): void
{
    foreach ($timers as $id => $timer) {
        if ($now >= $timer['start_time']) {
            unset($timers[$id]);
        }
    }
    foreach ($intervals as $id => $interval) {
        if ($now >= $interval['next_time']) {
            $intervals[$id]['next_time'] = $now + $interval['interval'];
        }
    }
}

seedTimers($N);
$current = bench("当前 processTimers()（O(1) 提前返回）", $M, function () {
    Async::processTimers();
});

// 参考旧实现：复制 timers/intervals 结构并扫描。
$timers = [];
$intervals = [];
for ($i = 0; $i < $N; $i++) {
    $timers[$i] = ['start_time' => microtime(true) + 3600.0 + $i, 'callback' => fn() => null];
}
$legacy = bench("旧版全量扫描（参考实现）", $M, function () use (&$timers, &$intervals) {
    naiveProcessTimers($timers, $intervals, microtime(true));
});

printf("  → 加速比（旧/新）：%.1f×\n\n", $legacy / max($current, 1e-6));

echo "=== 2) Promise 链路分配：subscribe vs 旧版内部 ->then() ===\n";

$K = 2000;

// 当前路径：真实 Promise，内部转发走 subscribe（不再造一次性 Promise）。
function chainNew(int $k): int
{
    $before = Promise::$instances;
    $p = Promise::resolve(0);
    for ($i = 0; $i < $k; $i++) {
        $p = $p->then(fn($v) => new Promise(fn($r) => $r($v + 1)));
    }
    $p->await();

    return Promise::$instances - $before;
}

// 旧路径复刻：内部转发额外用 ->then() 制造一次性 Promise（即 pre-subscribe 行为）。
function chainOld(int $k): int
{
    $before = Promise::$instances;
    $p = Promise::resolve(0);
    for ($i = 0; $i < $k; $i++) {
        $p = $p->then(function ($v) {
            $child = new Promise(fn($r) => $r($v + 1));
            // 复刻旧 doResolve 的 $value->then(转发, 转发) —— 多造一个一次性 Promise
            $child->then(fn() => null, fn() => null);

            return $child;
        });
    }
    $p->await();

    return Promise::$instances - $before;
}

$newCount = chainNew($K);
$oldCount = chainOld($K);

printf("  %-32s %6d 个 Promise\n", "当前（subscribe）", $newCount);
printf("  %-32s %6d 个 Promise\n", "旧版（内部 ->then）", $oldCount);
printf("  → 深链分配减少：%.1f%%（%.0f → %.0f）\n\n",
    (1 - $newCount / $oldCount) * 100, $oldCount, $newCount);

echo "=== 3) Async::each / map 真实并发上限 ===\n";

Async::reset();
$concurrency = 8;
$items = range(1, 16);
$maxConcurrent = 0;
$running = 0;

// 回调必须返回 Promise（异步 I/O）才会真正占用并发名额；返回普通值会被立即结算，
// 并发限流仅在 Promise 未决时生效。
$promise = Async::map($items, function ($item) use (&$maxConcurrent, &$running) {
    $running++;
    $maxConcurrent = max($maxConcurrent, $running);

    return new Promise(function ($resolve) use (&$running, $item) {
        Async::defer(function () use (&$running, $resolve, $item) {
            $running--;
            $resolve($item);
        });
    });
}, $concurrency);

$promise->await();

printf("  请求并发上限 = %d，实测峰值并发 = %d → %s\n\n",
    $concurrency, $maxConcurrent,
    $maxConcurrent === $concurrency ? '符合预期' : 'BUG：未生效');
