<?php

declare(strict_types=1);

/**
 * 压测脚本：性能对比测试
 * 
 * 运行方式：php examples/34-benchmark.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Compat\Worker;
use Kode\Fibers\Fibers;

echo "========================================\n";
echo "   Kode Process 性能压测对比\n";
echo "========================================\n\n";

$results = [];

echo "📊 测试 1: 基础 Fibers 操作\n";
echo "----------------------------------------\n";

$iterations = 10000;
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    Fibers::go(function () use ($i) {
        return $i * 2;
    });
}
$end = microtime(true);
$duration = ($end - $start) * 1000;
$ops = round($iterations / ($end - $start), 0);
$results['fibers_go'] = [
    'name' => 'Fibers::go()',
    'iterations' => $iterations,
    'duration_ms' => round($duration, 2),
    'ops' => $ops
];
echo "Fibers::go(): {$iterations} 次，耗时 {$duration} ms，{$ops} ops/s\n";

$start = microtime(true);
Fibers::batch(range(1, 1000), function ($i) {
    usleep(100);
    return $i;
}, 50);
$end = microtime(true);
$duration = ($end - $start) * 1000;
$results['fibers_batch'] = [
    'name' => 'Fibers::batch(1000 items)',
    'iterations' => 1000,
    'duration_ms' => round($duration, 2),
    'ops' => round(1000 / ($end - $start), 0)
];
echo "Fibers::batch(): 1000 个任务，耗时 {$duration} ms\n";

echo "\n📊 测试 2: 网络 IO 模拟\n";
echo "----------------------------------------\n";

$urls = array_fill(0, 100, 'http://example.com');
$start = microtime(true);
Fibers::batch($urls, function ($url) {
    usleep(random_int(1000, 5000));
    return strlen($url);
}, 20);
$end = microtime(true);
$duration = ($end - $start) * 1000;
$results['network_io'] = [
    'name' => '网络 IO 模拟 (100 requests)',
    'iterations' => 100,
    'duration_ms' => round($duration, 2),
    'ops' => round(100 / ($end - $start), 0)
];
echo "网络 IO 模拟: 100 个请求，耗时 {$duration} ms\n";

echo "\n📊 测试 3: 内存使用\n";
echo "----------------------------------------\n";

$memoryStart = memory_get_usage(true);
$tasks = [];
for ($i = 0; $i < 1000; $i++) {
    $tasks[] = Fibers::go(function () use ($i) {
        $data = str_repeat('x', 1024);
        return strlen($data);
    });
}
$memoryEnd = memory_get_usage(true);
$memoryUsed = round(($memoryEnd - $memoryStart) / 1024 / 1024, 2);
$results['memory'] = [
    'name' => '内存使用 (1000 Fibers)',
    'memory_mb' => $memoryUsed,
    'fibers_count' => 1000
];
echo "内存使用: 1000 个 Fibers，占用 {$memoryUsed} MB\n";

echo "\n📊 测试 4: 上下文切换\n";
echo "----------------------------------------\n";

$switches = 100000;
$start = microtime(true);
for ($i = 0; $i < $switches; $i++) {
    Fibers::go(function () {
        return;
    });
}
$end = microtime(true);
$duration = ($end - $start) * 1000;
$results['context_switch'] = [
    'name' => '上下文切换',
    'switches' => $switches,
    'duration_ms' => round($duration, 2),
    'switches_per_sec' => round($switches / ($end - $start), 0)
];
echo "上下文切换: {$switches} 次，耗时 {$duration} ms，" . round($switches / ($end - $start), 0) . " 次/秒\n";

echo "\n========================================\n";
echo "          📈 压测结果汇总\n";
echo "========================================\n\n";

echo "| 测试项 | 迭代次数 | 耗时(ms) | 操作/秒 |\n";
echo "|--------|----------|-----------|---------|\n";

foreach ($results as $key => $result) {
    if (isset($result['iterations'])) {
        $name = str_pad($result['name'], 30, ' ', STR_PAD_RIGHT);
        $iterations = str_pad((string)$result['iterations'], 8, ' ', STR_PAD_LEFT);
        $duration = str_pad((string)$result['duration_ms'], 9, ' ', STR_PAD_LEFT);
        $ops = isset($result['ops']) ? str_pad((string)$result['ops'], 7, ' ', STR_PAD_LEFT) : '   -   ';
        echo "| {$name} | {$iterations} | {$duration} | {$ops} |\n";
    }
}

echo "\n========================================\n";
echo "          💾 内存测试结果\n";
echo "========================================\n\n";

if (isset($results['memory'])) {
    echo "测试项: {$results['memory']['name']}\n";
    echo "Fibers 数量: {$results['memory']['fibers_count']}\n";
    echo "内存使用: {$results['memory']['memory_mb']} MB\n";
}

echo "\n========================================\n";
echo "          ⚡ 上下文切换\n";
echo "========================================\n\n";

if (isset($results['context_switch'])) {
    echo "切换次数: {$results['context_switch']['switches']}\n";
    echo "总耗时: {$results['context_switch']['duration_ms']} ms\n";
    echo "切换速度: {$results['context_switch']['switches_per_sec']} 次/秒\n";
}

echo "\n========================================\n";
echo "          ✅ 压测完成\n";
echo "========================================\n";
echo "\n💡 提示：实际性能受硬件、PHP 版本、系统负载影响\n";
