<?php

/**
 * Timer::parseCronNext 微基准（v5.2.27）。
 *
 * 测量两件事：
 *   1. 正确性：新实现（字段映射修正 + 位掩码缓存）与「逐轮字符串解析」参考实现
 *      在多个表达式上命中时刻完全一致（掩码由 matchCronPart 构建，故语义不变）。
 *   2. 性能：位掩码缓存稳态热路径 vs 旧版每轮 matchCronPart 字符串解析（不缓存）。
 *
 * 用法：php benchmarks/timer-cron-bench.php
 * 依赖：composer 已安装（vendor/autoload.php）。
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Timer;

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

    printf("  %-46s %9.3f ms  (%d iters)\n", $label, $ms, $n);

    return $ms;
}

$parse = new ReflectionMethod(Timer::class, 'parseCronNext');
$parse->setAccessible(true);

$match = new ReflectionMethod(Timer::class, 'matchCronPart');
$match->setAccessible(true);
$mc = fn(string $p, int $v): bool => $match->invoke(null, $p, $v);

/**
 * 旧版参考实现：正确字段映射 + 每轮字符串解析（不缓存）。
 * 隔离出「缓存/位掩码」消除的解析开销，作为加速比的诚实基线。
 */
function legacyParseCronNext(string $expr, callable $mc): float
{
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) {
        return microtime(true) + 60;
    }

    $now = time();
    $limit = min(46080, 525600);
    for ($i = 1; $i <= $limit; $i++) {
        $candidate = $now + ($i * 60);
        $d = getdate($candidate);

        if ($mc($parts[0], $d['minutes']) &&
            $mc($parts[1], $d['hours']) &&
            $mc($parts[2], $d['mday']) &&
            $mc($parts[3], $d['mon']) &&
            $mc($parts[4], $d['wday'])
        ) {
            return (float) $candidate;
        }
    }

    return microtime(true) + 3600;
}

$expressions = [
    '* * * * *',
    '*/15 * * * *',
    '0 9 * * 1',
    '30 14 28 2 *',
    '0 0 1 1 *',
    '0 0 30 2 *',
    '1-30/5 1-12/2 1,15 * 1-5',
];

echo "=== 1) 正确性：新实现 vs 逐轮字符串解析参考实现（命中时刻须一致） ===\n\n";

$failed = 0;
foreach ($expressions as $expr) {
    $new = (int) $parse->invoke(null, $expr);
    $old = (int) legacyParseCronNext($expr, $mc);
    $a = getdate($new);
    $b = getdate($old);

    $same = $a['minutes'] === $b['minutes']
        && $a['hours'] === $b['hours']
        && $a['mday'] === $b['mday']
        && $a['mon'] === $b['mon']
        && $a['wday'] === $b['wday'];

    printf(
        "  %-26s new=%s old=%s  %s\n",
        $expr,
        date('m-d H:i', $new),
        date('m-d H:i', $old),
        $same ? 'OK' : 'MISMATCH'
    );

    if (!$same) {
        $failed++;
    }
}

echo "\n";
if ($failed > 0) {
    echo "  !! 等价性校验失败，请检查字段映射 / 掩码构建。\n\n";
} else {
    echo "  ✓ 所有表达式命中时刻一致（含字段映射修正后的正确语义）。\n\n";
}

echo "=== 2) 性能：位掩码缓存热路径 vs 逐轮字符串解析 ===\n\n";

// 复杂且罕见的表达式：扫描会跑满上限，最能放大「每轮字符串解析」的成本。
// 新实现首轮构建掩码后，后续调用走缓存热路径；参考实现每轮重新解析。
$hot = '1-30/5 1-12/2 1,15 * 1-5';
$N = 2000;

// 预热：让新实现的掩码缓存填充
for ($i = 0; $i < 50; $i++) {
    $parse->invoke(null, $hot);
}

$newMs = bench("新（位掩码缓存热路径）", $N, fn() => $parse->invoke(null, $hot));
$oldMs = bench("旧（逐轮字符串解析）", $N, fn() => legacyParseCronNext($hot, $mc));

$ratio = $oldMs > 0 ? $newMs / $oldMs : 0;
echo "\n";
printf("  加速比（越小越快）：新 / 旧 = %.3f  →  约为旧版的 %.1f× 速度\n", $ratio, $ratio > 0 ? 1 / $ratio : 0);

// 单字段极简表达式（每轮仅 1 次扫描）：验证无回归、缓存无额外负担
echo "\n";
bench("新（* * * * * 每轮 1 次扫描）", $N, fn() => $parse->invoke(null, '* * * * *'));
bench("旧（* * * * * 每轮 1 次扫描）", $N, fn() => legacyParseCronNext('* * * * *', $mc));

echo "\n";
