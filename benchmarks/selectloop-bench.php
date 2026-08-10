<?php

/**
 * SelectLoop 惰性 prune 微基准（v5.2.28）。
 *
 * 对比两种 tick 策略在「所有流均有效」这一最常见情形下的每 tick 用户态开销：
 *   - 旧实现：每 tick 先全量 pruneInvalidStreams()（O(N) is_resource 扫描），再 stream_select
 *   - 新实现：惰性 prune——正常情形直接 stream_select，零额外扫描（O(1) 用户态）
 *
 * 仅当流被外部 fclose 却未调用 off* 时，stream_select 抛 ValueError 才触发一次 prune + 重试，
 * 因此「稳态每 tick 扫描成本」从 O(N) 降为 0。
 *
 * 为可移植（避免 macOS 在少量 socket 后把 fd 推到 >= FD_SETSIZE 使 stream_select 抛错），
 * 扫描成本用「N 元 is_resource 判定」直接量化，新实现的 stream_select 部分用少量低 fd 流实跑。
 *
 * 用法：php benchmarks/selectloop-bench.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Reactor\SelectLoop;

$N = 2000;          // 模拟并发连接数（稳态下均有效）
$K = 100000;        // tick 次数

// ---- 参考实现：旧版每 tick 全量 prune 扫描（被新实现省掉的 O(N) 成本） ----
function legacyPruneScan(array $streams): void
{
    foreach ($streams as $id => $stream) {
        if (!\is_resource($stream)) {
            unset($streams[$id]);
        }
    }
}

// 构造一个含 N 个有效资源引用的数组（复用少量真实低 fd socket，使 is_resource 扫描成本等价于 N 个真实流）。
$pool = [];
$fdTooHigh = false;
for ($i = 0; $i < 8; $i++) {
    $s = @\stream_socket_server('tcp://127.0.0.1:0');
    if ($s === false || (int) $s >= 1024) {
        $fdTooHigh = true;
        break;
    }
    $pool[] = $s;
}
if ($pool === []) {
    $pool[] = \fopen('php://memory', 'r');
}
$scanArray = [];
for ($i = 0; $i < $N; $i++) {
    $scanArray[$i] = $pool[$i % \count($pool)];
}

echo "=== SelectLoop 惰性 prune（v5.2.28） ===\n\n";
printf("  模拟连接数 N = %d，tick 次数 K = %d\n\n", $N, $K);

// 参考：旧实现每 tick 的 O(N) prune 扫描成本（这正是新实现省掉的）
$t = \hrtime(true);
for ($i = 0; $i < $K; $i++) {
    legacyPruneScan($scanArray);
}
$legacyMs = (\hrtime(true) - $t) / 1e6;
$legacyPerTick = $legacyMs / $K;

// 新实现：真实 SelectLoop::select(0) 在少量低 fd 流上实跑 K 次（无效流不出现 → 零扫描路径）
$select = new \ReflectionMethod(SelectLoop::class, 'select');
$select->setAccessible(true);

$newSelectMs = 0.0;
$newPreserved = null;
if (!$fdTooHigh) {
    $loop = new SelectLoop();
    $pairs = [];
    for ($i = 0; $i < 4; $i++) {
        $pair = @\stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false || (int) $pair[0] >= 1024) {
            break;
        }
        $pairs[] = $pair;
        $loop->onReadable($pair[0], static fn() => null);
    }
    if ($pairs !== []) {
        $t = \hrtime(true);
        for ($i = 0; $i < $K; $i++) {
            $select->invoke($loop, 0.0);
        }
        $newSelectMs = (\hrtime(true) - $t) / 1e6;
        $newPreserved = $loop->stats()['read'];
    }
}

printf("  旧每 tick 用户态扫描（O(N) 参考）：%9.3f ms   每 tick %9.5f ms\n", $legacyMs, $legacyPerTick);
if ($newSelectMs > 0) {
    printf("  新每 tick 实跑（零扫描，仅 select）：%9.3f ms   每 tick %9.5f ms\n", $newSelectMs, $newSelectMs / $K);
    printf("  断言：K 次 tick 后有效流数 = %d（期望 %d）%s\n\n",
        $newPreserved, \count($pairs), $newPreserved === \count($pairs) ? '✓ 通过' : '✗ 失败');
} else {
    echo "  （本平台 fd 偏高，跳过真实 select 实跑，仅量化被省掉的扫描成本）\n\n";
}

printf("  结论：稳态每 tick 用户态扫描 旧 O(N) ≈ %.5f ms → 新 0 ms；\n", $legacyPerTick);
printf("        连接越多该扫描越贵，新实现每 tick 省掉一次 N 元 is_resource 扫描。\n");
printf("        N=%d 时每 %d tick 累计省 %.1f ms（纯用户态，不含 kernel 侧 fd 拷贝）。\n",
    $N, $K, $legacyMs);

echo "\n  注：惰性 prune 仅在流被外部 fclose 未 off* 时触发一次 prune + 重试（见 SelectLoopTest）。\n\n";

foreach ($pool as $s) {
    @\fclose($s);
}
