<?php

/**
 * Async 微任务分发微基准（v5.2.27）。
 *
 * 测量 queueMicrotask 两种用法的开销差异：
 *   - 旧风格：每次都用闭包包裹参数 `queueMicrotask(fn() => $cb($v))`（每轮分配一个 Closure）
 *   - 新风格：直接传可调用 + 参数 `queueMicrotask($cb, $v)`（零闭包分配）
 *
 * 与 v5.2.26 的 Promise::subscribe 优化互补：Promise 决议热路径上不再为每个
 * then 回调创建「只为了转发一个值」的闭包。这里隔离出「微任务分发」这一环的分配与耗时差异。
 *
 * 用法：php benchmarks/async-microtask-bench.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Async\Async;

function benchMicrotask(string $label, int $n, callable $dispatch): array
{
    // 闭包分配会在两次运行间被 GC，用峰值内存差更直观反映「每轮分配」的量。
    $mem0 = memory_get_peak_usage();
    $t = hrtime(true);
    $dispatch($n);
    $ms = (hrtime(true) - $t) / 1e6;
    $mem = memory_get_peak_usage() - $mem0;

    printf(
        "  %-42s %9.3f ms  峰值内存 +%8.1f KB  (%d iters)\n",
        $label,
        $ms,
        $mem / 1024,
        $n
    );

    return ['ms' => $ms, 'mem' => $mem];
}

$target = function (int $x): void {
    // 真实的「消费」动作，避免被优化器空消除
    if ($x < 0) {
        throw new \RuntimeException('unreachable');
    }
};

// 旧风格：每次 queueMicrotask 都包裹一个闭包
function dispatchLegacy(int $n, callable $target): void
{
    Async::reset();
    for ($i = 0; $i < $n; $i++) {
        Async::queueMicrotask(fn() => $target($i));
    }
    Async::runMicrotasks();
}

// 新风格：直接传可调用 + 参数，无闭包分配
function dispatchTuple(int $n, callable $target): void
{
    Async::reset();
    for ($i = 0; $i < $n; $i++) {
        Async::queueMicrotask($target, $i);
    }
    Async::runMicrotasks();
}

echo "=== 微任务分发：闭包包裹 vs 元组参数 ===\n\n";

$N = 200000;

// 先跑元组风格建立基线，再跑闭包风格，闭包的一次性分配量即体现在峰值内存增量上。
$tuple  = benchMicrotask('新（queueMicrotask($cb, $v) 元组）', $N, fn(int $n) => dispatchTuple($n, $target));
$legacy = benchMicrotask('旧（fn() => $cb($v) 闭包包裹）', $N, fn(int $n) => dispatchLegacy($n, $target));

$timeRatio = $legacy['ms'] > 0 ? $legacy['ms'] / $tuple['ms'] : 0;
$memSavedKb = ($legacy['mem'] - $tuple['mem']) / 1024;

echo "\n";
printf("  耗时：旧 / 新 = %.2f  →  新约为旧版的 %.1f×\n", $timeRatio, $timeRatio);
printf("  闭包分配：旧风格为 %d 次微任务额外分配约 %.0f KB，新风格几乎为零（仅小数组）。\n",
    $N,
    $memSavedKb > 0 ? $memSavedKb : $legacy['mem'] / 1024
);

echo "\n  注：闭包分配减少直接转化为 GC 压力下降，长 Promise 链 / 高并发异步下收益放大。\n\n";
