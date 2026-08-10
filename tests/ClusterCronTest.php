<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Cluster;
use Kode\Process\Crontab\ClusterCron;
use Kode\Process\Crontab\Crontab;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * ClusterCron 多进程安全守卫测试。
 *
 * 用 Cluster::make('file', ...) 的真实存储后端模拟「同机多进程」的协调（file 后端在单机上对多
 * worker 进程具备互斥语义），验证集群内每调度时刻「至多执行一次」的语义；并验证 Leader 选举推进。
 */
final class ClusterCronTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = \sys_get_temp_dir() . '/kode-cluster-cron-' . \uniqid();
        \mkdir($this->storeDir, 0o777, true);
        Cluster::make('file', ['path' => $this->storeDir]);
    }

    protected function tearDown(): void
    {
        Crontab::destroyAll();
        Cluster::reset();
        foreach (\glob($this->storeDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->storeDir);
    }

    /** 把 cron 的 nextRunTime 强制设为过去，使下一次 tick() 必定触发。 */
    private function forcePast(Crontab $cron): void
    {
        $p = new ReflectionProperty(Crontab::class, 'nextRunTime');
        $p->setAccessible(true);
        $p->setValue($cron, \time() - 1);
    }

    /**
     * 锁空闲时（无其他 worker 持有），守卫 cron 应正常执行回调一次。
     */
    public function testRunsWhenLockFree(): void
    {
        $count = 0;
        $cron = ClusterCron::create('* * * * *', static function () use (&$count): void {
            $count++;
        });
        $this->forcePast($cron);

        Crontab::tickAll();

        $this->assertSame(1, $count, '锁空闲时回调应执行一次');
    }

    /**
     * 当「另一 worker」已持有该 cron 的分布式锁时，本进程应跳过 —— 这正是多进程下去重的核心。
     */
    public function testSkipsWhenAnotherWorkerHoldsLock(): void
    {
        $count = 0;
        $cron = ClusterCron::create('* * * * *', static function () use (&$count): void {
            $count++;
        });
        $this->forcePast($cron);

        $key = 'kode:cron:' . \md5('* * * * *');
        $holder = Cluster::lock($key, 30.0);
        $this->assertTrue($holder->tryAcquire(), '模拟另一 worker 抢到锁');

        try {
            Crontab::tickAll();
            $this->assertSame(0, $count, '锁被其他 worker 持有时本进程应跳过，避免重复执行');
        } finally {
            $holder->release();
        }
    }

    /**
     * 存储层对同名锁应互斥：一个 worker 持有时，另一个 worker 的 tryAcquire 必须失败，
     * 释放后才可获取。这是「至多执行一次」的底层保证。
     */
    public function testLockIsMutuallyExclusiveAcrossWorkers(): void
    {
        $key = 'kode:cron:' . \md5('0 0 * * *');
        $a = Cluster::lock($key, 30.0);
        $this->assertTrue($a->tryAcquire());

        $b = Cluster::lock($key, 30.0);
        $this->assertFalse($b->tryAcquire(), '同名锁在存储层应互斥');

        $a->release();
        $this->assertTrue($b->tryAcquire(), '释放后另一 worker 可获取');
        $b->release();
    }

    /**
     * tickOnLeader 在单进程（本节点即 Leader）下应推进并执行 cron。
     */
    public function testTickOnLeaderRunsCronsWhenLeader(): void
    {
        $count = 0;
        $cron = ClusterCron::create('* * * * *', static function () use (&$count): void {
            $count++;
        });
        $this->forcePast($cron);

        ClusterCron::tickOnLeader('test-sched', 15.0);

        $this->assertSame(1, $count, 'Leader 进程应推进并执行 cron');
    }

    /**
     * create() 返回的是可用 Crontab 实例，可正常 destroy()。
     */
    public function testReturnsUsableCrontab(): void
    {
        $cron = ClusterCron::create('*/5 * * * *', static fn() => null);
        $this->assertInstanceOf(Crontab::class, $cron);
        $cron->destroy();
        $this->assertSame(0, Crontab::count());
    }
}
