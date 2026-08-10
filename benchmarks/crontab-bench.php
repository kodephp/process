<?php

/**
 * Crontab 字段匹配微基准（v5.2.26 优化对照）。
 *
 * 对照「位掩码匹配」与「in_array 线性扫描」在 searchNext 主循环里的实际耗时。
 * 用反射读取 Crontab 已解析的 $fields，保证两种实现使用完全相同的字段集合与
 * 搜索地平线，仅替换匹配谓词，从而干净归因位运算的收益。
 *
 * 用法：php benchmarks/crontab-bench.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Crontab\Crontab;

function bench(string $label, int $n, callable $fn): float
{
    for ($i = 0; $i < 1000; $i++) {
        $fn();
    }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $ms = (hrtime(true) - $t) / 1e6;
    printf("  %-46s %9.3f ms\n", $label, $ms);

    return $ms;
}

// 读取私有字段的辅助
$fieldsRef = new ReflectionProperty(Crontab::class, 'fields');
$fieldsRef->setAccessible(true);

// 位掩码版 searchNext（与当前实现一致，独立复刻以便对照）
function advanceMask(int $current, int|false $target): int
{
    return ($target !== false && $target > $current) ? $target : $current + 1;
}

function searchNextMask(array $f, int $from, int $horizonDays): int
{
    $limit = $from + $horizonDays * 86400;
    $t = $from;

    while ($t < $limit) {
        $d = getdate($t);

        if ((($f['month_mask'] >> $d['mon']) & 1) === 0) {
            $t = advanceMask($t, mktime(0, 0, 0, $d['mon'] + 1, 1, $d['year']));
            continue;
        }
        if (!dayWeekMask($f, $d)) {
            $t = advanceMask($t, mktime(0, 0, 0, $d['mon'], $d['mday'] + 1, $d['year']));
            continue;
        }
        if ((($f['hour_mask'] >> $d['hours']) & 1) === 0) {
            $t = advanceMask($t, mktime($d['hours'] + 1, 0, 0, $d['mon'], $d['mday'], $d['year']));
            continue;
        }
        if ((($f['minute_mask'] >> $d['minutes']) & 1) === 0) {
            $t = advanceMask($t, mktime($d['hours'], $d['minutes'] + 1, 0, $d['mon'], $d['mday'], $d['year']));
            continue;
        }
        if ((($f['second_mask'] >> $d['seconds']) & 1) === 0) {
            $t++;
            continue;
        }

        return $t;
    }

    return $limit;
}

function dayWeekMask(array $f, array $d): bool
{
    $dayOk = (($f['day_mask'] >> $d['mday']) & 1) === 1;
    $weekdayOk = (($f['weekday_mask'] >> $d['wday']) & 1) === 1;

    if ($f['day_restricted'] && $f['weekday_restricted']) {
        return $dayOk || $weekdayOk;
    }

    return $dayOk && $weekdayOk;
}

// in_array 版 searchNext（旧实现，独立复刻）
function searchNextArray(array $f, int $from, int $horizonDays): int
{
    $limit = $from + $horizonDays * 86400;
    $t = $from;

    while ($t < $limit) {
        $d = getdate($t);

        if (!in_array($d['mon'], $f['month'], true)) {
            $t = max(mktime(0, 0, 0, $d['mon'] + 1, 1, $d['year']), $from + 1);
            continue;
        }
        $dayOk = in_array($d['mday'], $f['day'], true);
        $weekdayOk = in_array($d['wday'], $f['weekday'], true);
        $dayWeekOk = ($f['day_restricted'] && $f['weekday_restricted'])
            ? ($dayOk || $weekdayOk)
            : ($dayOk && $weekdayOk);
        if (!$dayWeekOk) {
            $t = advanceMask($t, mktime(0, 0, 0, $d['mon'], $d['mday'] + 1, $d['year']));
            continue;
        }
        if (!in_array($d['hours'], $f['hour'], true)) {
            $t = max(mktime($d['hours'] + 1, 0, 0, $d['mon'], $d['mday'], $d['year']), $from + 1);
            continue;
        }
        if (!in_array($d['minutes'], $f['minute'], true)) {
            $t = max(mktime($d['hours'], $d['minutes'] + 1, 0, $d['mon'], $d['mday'], $d['year']), $from + 1);
            continue;
        }
        if (!in_array($d['seconds'], $f['second'], true)) {
            $t++;
            continue;
        }

        return $t;
    }

    return $limit;
}

$expressions = [
    '* * * * * *'        => '每秒',          // 最密集：每轮都进秒级推进
    '*/5 * * * * *'      => '每 5 秒',
    '0 * * * * *'        => '每小时第 0 秒',
    '*/15 * * * *'       => '每 15 分钟',
    '0 0 * * 1-5'        => '工作日午夜',
    '0 0 13 * 5'         => '每月 13 号或周五（POSIX 并集）',
];

$n = 20000;

foreach ($expressions as $expr => $desc) {
    $c = new Crontab($expr, fn() => null);
    $f = $fieldsRef->getValue($c);
    $from = time() + 1;

    echo "表达式 {$expr}（{$desc}）\n";
    $maskMs = bench("  位掩码匹配", $n, fn() => searchNextMask($f, $from, 367));
    $arrMs = bench("  in_array 匹配", $n, fn() => searchNextArray($f, $from, 367));
    printf("  → 加速比（旧/新）：%.2f×\n\n", $arrMs / max($maskMs, 1e-6));

    // 校验两种实现结果一致（正确性守护）
    $m = searchNextMask($f, $from, 367);
    $a = searchNextArray($f, $from, 367);
    if ($m !== $a) {
        fprintf(STDERR, "  不一致！mask=%d array=%d\n", $m, $a);
        exit(1);
    }
}
