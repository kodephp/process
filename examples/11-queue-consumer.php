<?php

declare(strict_types=1);

/**
 * 示例: 队列消费者 - 最简单的方式
 *
 * 一行代码启动队列消费
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Queue\Consumer;

echo "=== 队列消费者示例 ===\n\n";

Consumer::create(['worker_count' => 4])
    ->on('SendEmail', function (array $data) {
        echo "发送邮件: {$data['to']}\n";
        return ['sent' => true];
    })
    ->on('ProcessImage', function (array $data) {
        echo "处理图片: {$data['path']}\n";
        return ['processed' => true];
    })
    ->on('GenerateReport', function (array $data) {
        echo "生成报告: {$data['report_id']}\n";
        return ['generated' => true];
    })
    ->start();
