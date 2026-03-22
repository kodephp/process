<?php

declare(strict_types=1);

use Kode\Process\Compat\Timer;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Timer 定时器示例 ===\n\n";

// 1. 一次性定时器 - 只执行一次
echo "1. 一次性定时器 (once)\n";
Timer::once(1.0, function () {
    echo "   [一次性] 1秒后执行，只执行一次\n";
});

// 2. 永久定时器 - 持续执行
echo "\n2. 永久定时器 (forever)\n";
$counter = 0;
$timer2 = Timer::forever(0.5, function () use (&$counter) {
    $counter++;
    echo "   [永久] 第 {$counter} 次执行\n";

    if ($counter >= 3) {
        Timer::del($GLOBALS['timer2']);
        echo "   [永久] 已停止\n";
    }
});
$GLOBALS['timer2'] = $timer2;

// 3. 指定次数定时器
echo "\n3. 指定次数定时器 (repeat)\n";
Timer::repeat(0.3, function () {
    static $times = 0;
    $times++;
    echo "   [重复] 第 {$times}/3 次执行\n";
}, 3);

// 4. 立即执行
echo "\n4. 立即执行 (immediate)\n";
Timer::immediate(function () {
    echo "   [立即] 马上执行\n";
});

// 5. 延迟执行
echo "\n5. 延迟执行 (delay)\n";
Timer::delay(0.5, function () {
    echo "   [延迟] 0.5秒后执行\n";
});

// 6. 暂停和恢复
echo "\n6. 暂停和恢复定时器\n";
$timer6 = Timer::forever(0.2, function () {
    static $n = 0;
    $n++;
    echo "   [可暂停] 第 {$n} 次执行\n";
});

// 模拟时间流逝
echo "\n--- 开始执行定时器 ---\n";

for ($i = 0; $i < 20; $i++) {
    Timer::tick();
    usleep(100000);

    if ($i === 5) {
        echo "\n>>> 暂停 timer6\n";
        Timer::pause($timer6);
    }

    if ($i === 10) {
        echo "\n>>> 恢复 timer6\n";
        Timer::resume($timer6);
    }

    if ($i === 15) {
        echo "\n>>> 删除 timer6\n";
        Timer::del($timer6);
    }
}

// 7. Cron 表达式定时器
echo "\n7. Cron 表达式定时器\n";
Timer::cron('* * * * *', function () {
    echo "   [Cron] 每分钟执行一次\n";
});

// 8. 获取定时器状态
echo "\n8. 定时器状态\n";
$stats = Timer::getStats();
echo "   总创建: {$stats['total_created']}\n";
echo "   总执行: {$stats['total_executed']}\n";
echo "   活跃定时器: {$stats['active_timers']}\n";

// 9. 清除所有定时器
echo "\n9. 清除所有定时器\n";
echo "   清除前: " . Timer::count() . " 个定时器\n";
Timer::delAll();
echo "   清除后: " . Timer::count() . " 个定时器\n";

echo "\n=== 示例完成 ===\n";
