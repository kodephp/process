<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Lock\DistributedLock;
use PHPUnit\Framework\TestCase;

/**
 * 回归：重入必须验证「锁真的还在自己手上」。
 *
 * 修复前 tryAcquire() 的重入分支只看本地计数：
 *
 * ```php
 * if ($this->depth > 0) { $this->depth++; return true; }
 * ```
 *
 * 锁按 TTL 自动过期、甚至已被别人抢走之后，这句依旧返回 true——
 * 两个进程同时进临界区，互斥保证直接失效。
 *
 * 用 FakeStore 把 TTL 到期做成确定性场景。
 */
final class LockReentrancyTest extends TestCase
{
    private FakeStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeStore();
    }

    public function testReentrantAcquireIsRejectedWhenLockWasTakenOverAfterExpiry(): void
    {
        $lock = new DistributedLock($this->store, 'job', 0.05, 'node-a');

        $this->assertTrue($lock->tryAcquire());
        $this->assertSame(1, $lock->depth());

        usleep(80_000);     // TTL 到期，锁自动释放

        // 另一个节点抢到了这把锁
        $this->assertTrue($this->store->setIfAbsent('lock/job', 'node-b:1:ffff', 5_000));

        $this->assertFalse(
            $lock->tryAcquire(),
            'TTL 已过且锁被他人接管，重入不能凭本地计数直接放行'
        );
        $this->assertFalse($lock->isHeld());
        $this->assertSame(0, $lock->depth());
        $this->assertSame('node-b:1', $lock->owner(), '锁仍应属于新的持有者');
    }

    public function testReentrantAcquireRedoesAFreshAcquireWhenLockSilentlyExpired(): void
    {
        $lock = new DistributedLock($this->store, 'job', 0.05, 'node-a');

        $this->assertTrue($lock->tryAcquire());

        usleep(80_000);
        $this->assertFalse($this->store->exists('lock/job'), 'TTL 到期后键应已消失');

        $this->assertTrue($lock->tryAcquire());
        $this->assertSame(1, $lock->depth(), '过期后是一次全新获取，不该叠加到旧计数上');
        $this->assertSame($lock->token(), $this->store->get('lock/job'), '必须重新把键写回存储');
        $this->assertGreaterThan(0.0, $lock->remaining());
    }

    public function testReentrancyStillWorksWithinTtl(): void
    {
        $lock = new DistributedLock($this->store, 'job', 30.0, 'node-a');

        $this->assertTrue($lock->tryAcquire());
        $this->assertTrue($lock->tryAcquire());
        $this->assertSame(2, $lock->depth());

        $this->assertTrue($lock->release());
        $this->assertTrue($this->store->exists('lock/job'), '内层释放不应真的删键');

        $this->assertTrue($lock->release());
        $this->assertFalse($this->store->exists('lock/job'));
    }
}
