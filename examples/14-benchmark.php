<?php

declare(strict_types=1);

/**
 * 示例: 性能基准测试
 *
 * 测试进程管理各项性能指标
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Benchmark\ProcessBenchmark;

echo "=== 性能基准测试 ===\n\n";

ProcessBenchmark::quick();
