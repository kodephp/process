<?php

declare(strict_types=1);

/**
 * 真实压力测试
 * 
 * 对比 Kode Process 与原生 PHP 的性能
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Response;
use Kode\Process\Benchmark\StressTest;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║              Kode Process vs Workerman 性能对比              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$iterations = 10000;

// 1. Response 格式化测试
echo "【1】Response 格式化性能测试 ({$iterations} 次)\n";
echo str_repeat("-", 60) . "\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    Response::ok(['index' => $i, 'data' => str_repeat('x', 100)])
        ->withMeta('job_id', "job_{$i}")
        ->toArray();
}
$time1 = microtime(true) - $start;
$ops1 = $iterations / $time1;

echo "  Kode Process Response: " . number_format($ops1) . " ops/s\n";
echo "  平均耗时: " . round(($time1 / $iterations) * 1000000, 2) . " μs/op\n\n";

// 2. JSON 序列化测试
echo "【2】JSON 序列化性能测试 ({$iterations} 次)\n";
echo str_repeat("-", 60) . "\n";

$data = ['code' => 0, 'message' => 'success', 'data' => ['user_id' => 123, 'name' => 'test']];

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    json_encode($data);
}
$time2 = microtime(true) - $start;
$ops2 = $iterations / $time2;

echo "  原生 json_encode: " . number_format($ops2) . " ops/s\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    Response::ok(['user_id' => 123, 'name' => 'test'])->toJson();
}
$time3 = microtime(true) - $start;
$ops3 = $iterations / $time3;

echo "  Kode Response::toJson: " . number_format($ops3) . " ops/s\n";
echo "  性能比: " . round($ops3 / $ops2 * 100, 1) . "%\n\n";

// 3. 进程 Fork 测试
echo "【3】进程 Fork 性能测试 (100 次)\n";
echo str_repeat("-", 60) . "\n";

$forkCount = 100;
$start = microtime(true);
$pids = [];

for ($i = 0; $i < $forkCount; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        usleep(1000);
        exit(0);
    } elseif ($pid > 0) {
        $pids[] = $pid;
    }
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$time4 = microtime(true) - $start;
$ops4 = $forkCount / $time4;

echo "  Fork 次数: {$forkCount}\n";
echo "  总耗时: " . round($time4 * 1000, 2) . " ms\n";
echo "  平均耗时: " . round(($time4 / $forkCount) * 1000, 2) . " ms/fork\n";
echo "  吞吐量: " . number_format($ops4, 1) . " forks/s\n\n";

// 4. IPC Socket 通信测试
echo "【4】IPC Socket 通信性能测试 (1000 次)\n";
echo str_repeat("-", 60) . "\n";

$ipcCount = 1000;
$sockets = [];
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

$start = microtime(true);
for ($i = 0; $i < $ipcCount; $i++) {
    $data = serialize(['id' => $i, 'time' => microtime(true)]);
    socket_write($sockets[0], $data);
}
$time5 = microtime(true) - $start;

socket_close($sockets[0]);
socket_close($sockets[1]);

$ops5 = $ipcCount / $time5;

echo "  发送次数: {$ipcCount}\n";
echo "  总耗时: " . round($time5 * 1000, 2) . " ms\n";
echo "  平均耗时: " . round(($time5 / $ipcCount) * 1000, 4) . " ms/msg\n";
echo "  吞吐量: " . number_format($ops5) . " msgs/s\n\n";

// 5. 定时器测试
echo "【5】定时器性能测试 (1000 次)\n";
echo str_repeat("-", 60) . "\n";

$timerCount = 1000;
$start = microtime(true);

$timers = [];
for ($i = 0; $i < $timerCount; $i++) {
    $timers[] = ['run_at' => microtime(true) + 1, 'callback' => fn() => true];
}

$time6 = microtime(true) - $start;
$ops6 = $timerCount / $time6;

echo "  创建次数: {$timerCount}\n";
echo "  总耗时: " . round($time6 * 1000, 2) . " ms\n";
echo "  平均耗时: " . round(($time6 / $timerCount) * 1000, 4) . " ms/timer\n";
echo "  吞吐量: " . number_format($ops6) . " timers/s\n\n";

// 6. Fiber 协程测试
echo "【6】Fiber 协程性能测试 (1000 次)\n";
echo str_repeat("-", 60) . "\n";

if (class_exists(\Fiber::class)) {
    $fiberCount = 1000;
    $start = microtime(true);

    for ($i = 0; $i < $fiberCount; $i++) {
        $fiber = new \Fiber(function () use ($i) {
            \Fiber::suspend();
            return $i;
        });
        $fiber->start();
        $fiber->resume();
    }

    $time7 = microtime(true) - $start;
    $ops7 = $fiberCount / $time7;

    echo "  Fiber 次数: {$fiberCount}\n";
    echo "  总耗时: " . round($time7 * 1000, 2) . " ms\n";
    echo "  平均耗时: " . round(($time7 / $fiberCount) * 1000, 4) . " ms/fiber\n";
    echo "  吞吐量: " . number_format($ops7) . " fibers/s\n\n";
} else {
    echo "  Fiber 不支持\n\n";
}

// 7. 内存使用测试
echo "【7】内存使用测试\n";
echo str_repeat("-", 60) . "\n";

$initial = memory_get_usage(true);

$objects = [];
for ($i = 0; $i < 1000; $i++) {
    $objects[] = Response::ok(['index' => $i, 'data' => str_repeat('x', 100)]);
}

$peak = memory_get_usage(true);
unset($objects);

echo "  初始内存: " . round($initial / 1024 / 1024, 2) . " MB\n";
echo "  峰值内存: " . round($peak / 1024 / 1024, 2) . " MB\n";
echo "  单对象内存: " . round(($peak - $initial) / 1000) . " bytes\n\n";

// 总结
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                        性能总结                              ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  Response 格式化:  %12s ops/s                          ║\n", number_format($ops1));
printf("║  JSON 序列化:      %12s ops/s (%3d%% of native)        ║\n", number_format($ops3), round($ops3 / $ops2 * 100));
printf("║  进程 Fork:        %12s forks/s                        ║\n", number_format($ops4, 1));
printf("║  IPC 通信:         %12s msgs/s                         ║\n", number_format($ops5));
printf("║  定时器:           %12s timers/s                       ║\n", number_format($ops6));
if (isset($ops7)) {
    printf("║  Fiber 协程:       %12s fibers/s                      ║\n", number_format($ops7));
}
echo "╚══════════════════════════════════════════════════════════════╝\n";
