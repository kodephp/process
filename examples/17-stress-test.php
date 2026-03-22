<?php

declare(strict_types=1);

/**
 * 压力测试示例
 * 
 * 运行性能基准测试
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Benchmark\StressTest;

echo "=== 压力测试 ===\n\n";

StressTest::quick(10000, 100);
