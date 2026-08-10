<?php

/**
 * ClusterCron 协调开销微基准（v5.2.28）。
 *
 * 进程内 Crontab / Kode::cron() 是「每 worker 一份」，多进程下每调度时刻会触发 N 次。
 * ClusterCron 用分布式锁把执行收敛到「集群内至多一次」，代价是每次触发多出一次锁往返
 * （tryAcquire + release）。本基准量化这一往返开销，便于在「去重收益」与「协调成本」间权衡。
 *
 * 用法：php benchmarks/cluster-cron-bench.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Cluster;
use Kode\Process\Cluster\Lock\DistributedLock;
use Kode\Process\Crontab\ClusterCron;
use Kode\Process\Crontab\Crontab;

$dir = \sys_get_temp_dir() . '/kode-cluster-cron-bench-' . \getmypid();
\mkdir($dir, 0o777, true);
Cluster::make('file', ['path' => $dir]);

$N = 20000;
$key = 'kode:cron:' . \md5('* * * * *');

// 1) 纯锁往返开销（ClusterCron 每次触发的协调成本）
$t = \hrtime(true);
for ($i = 0; $i < $N; $i++) {
    $lock = Cluster::lock($key, 30.0);
    if ($lock->tryAcquire()) {
        $lock->release();
    }
}
$lockMs = (\hrtime(true) - $t) / 1e6;

// 2) 对比：无任何协调的纯回调分发（Crontab 直接 tick 同一回调 N 次，nextRunTime 强制回拨）
$cron = ClusterCron::create('* * * * *', static fn() => null);
$rp = new \ReflectionProperty(Crontab::class, 'nextRunTime');
$rp->setAccessible(true);

$t = \hrtime(true);
for ($i = 0; $i < $N; $i++) {
    $rp->setValue($cron, \time() - 1);
    Crontab::tickAll();
}
$guardedMs = (\hrtime(true) - $t) / 1e6;

printf("=== ClusterCron 协调开销（v5.2.28，file 后端，N=%d） ===\n\n", $N);
printf("  纯锁往返（tryAcquire+release）：%9.3f ms   每次 %.4f ms\n", $lockMs, $lockMs / $N);
printf("  守卫 cron 实跑 N 次触发：      %9.3f ms   每次 %.4f ms\n", $guardedMs, $guardedMs / $N);
echo "\n";
printf("  结论：集群安全 cron 每次触发的协调成本约 %.4f ms（file 后端，同机多进程）。\n", $lockMs / $N);
echo "  该成本换来「多进程下不再每调度时刻重复 N 次」的确定性；跨机请换 Redis 后端（往返更高但语义一致）。\n";
echo "  若任务本身很重（> 锁 TTL），改用 Kode::tickCronOnLeader() 走 Leader 选举以获得强 exactly-once。\n\n";

foreach (\glob($dir . '/*') ?: [] as $f) {
    @\unlink($f);
}
@\rmdir($dir);
