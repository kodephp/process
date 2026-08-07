<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Lock\DistributedLock;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 分布式锁。
 *
 * 重点在三条安全性质：互斥、不误删他人的锁、持有者崩溃后能靠 TTL 自动释放。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class DistributedLockTest extends TestCase
{
    private FileStore $store;

    private string $path;

    protected function setUp(): void
    {
        $this->path  = sys_get_temp_dir() . '/kode-lock-test-' . getmypid() . '-' . uniqid();
        $this->store = new FileStore(['path' => $this->path]);
    }

    protected function tearDown(): void
    {
        $this->store->flush();
        $this->store->close();

        foreach ((array) glob($this->path . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->path);
    }

    private function lock(string $key = 'job', float $ttl = 30.0, ?string $owner = null): DistributedLock
    {
        return new DistributedLock($this->store, $key, $ttl, $owner);
    }

    public function testAccessors(): void
    {
        $lock = $this->lock('job', 12.0, 'node-a');

        $this->assertSame('job', $lock->key());
        $this->assertSame(12.0, $lock->ttl());
        $this->assertStringStartsWith('node-a:', $lock->token(), 'token 应带上持有者便于排查');
        $this->assertFalse($lock->isHeld());
        $this->assertSame(0, $lock->depth());
    }

    public function testTryAcquireSucceedsWhenFree(): void
    {
        $lock = $this->lock();

        $this->assertTrue($lock->tryAcquire());
        $this->assertTrue($lock->isHeld());
        $this->assertSame(1, $lock->depth());
        $this->assertTrue($lock->isLocked());
    }

    public function testSecondHolderIsBlocked(): void
    {
        $a = $this->lock('job', 30.0, 'node-a');
        $b = $this->lock('job', 30.0, 'node-b');

        $this->assertTrue($a->tryAcquire());
        $this->assertFalse($b->tryAcquire(), '互斥：同一把锁不能被两个节点同时持有');
        $this->assertFalse($b->isHeld());
        $this->assertTrue($b->isLocked(), '别人持有时 isLocked 仍为真');
    }

    public function testReentrantAcquireIncrementsDepth(): void
    {
        $lock = $this->lock();

        $this->assertTrue($lock->tryAcquire());
        $this->assertTrue($lock->tryAcquire(), '同一实例可重入');
        $this->assertSame(2, $lock->depth());

        $this->assertTrue($lock->release());
        $this->assertTrue($lock->isHeld(), '深度未归零，锁仍持有');
        $this->assertSame(1, $lock->depth());

        $this->assertTrue($lock->release());
        $this->assertFalse($lock->isHeld());
        $this->assertFalse($lock->isLocked());
    }

    public function testReleaseWithoutHoldingIsNoop(): void
    {
        $this->assertFalse($this->lock()->release());
    }

    public function testReleaseDoesNotDeleteAnotherHoldersLock(): void
    {
        $a = $this->lock('job', 30.0, 'node-a');
        $b = $this->lock('job', 30.0, 'node-b');

        $this->assertTrue($a->tryAcquire());

        // b 从未持有，release 不应把 a 的锁删掉
        $b->release();

        $this->assertTrue($a->isHeld());
        $this->assertSame($a->token(), $this->store->get('lock/job'));
    }

    public function testOwnerReportsCurrentHolder(): void
    {
        $a = $this->lock('job', 30.0, 'node-a');
        $b = $this->lock('job', 30.0, 'node-b');

        $this->assertNull($b->owner());

        $a->tryAcquire();

        $this->assertSame('node-a', $b->owner(), '旁观者也能看出锁被谁拿着');
    }

    public function testLockExpiresSoCrashedHolderDoesNotDeadlock(): void
    {
        $dead = $this->lock('job', 0.03, 'node-dead');
        $next = $this->lock('job', 30.0, 'node-next');

        $this->assertTrue($dead->tryAcquire());
        $this->assertFalse($next->tryAcquire());

        usleep(50_000);   // 持有者「崩溃」，TTL 到期

        $this->assertTrue($next->tryAcquire(), 'TTL 到期后锁必须能被别人拿走，否则死锁');

        $dead->forceRelease();
    }

    public function testAcquireWaitsUntilLockIsReleased(): void
    {
        $holder = $this->lock('job', 0.05, 'node-a');
        $waiter = $this->lock('job', 30.0, 'node-b');

        $holder->tryAcquire();

        $start = microtime(true);
        $ok    = $waiter->acquire(wait: 1.0, retryInterval: 0.005);
        $spent = microtime(true) - $start;

        $this->assertTrue($ok);
        $this->assertGreaterThan(0.01, $spent, '应确实等待过');
        $this->assertLessThan(1.0, $spent, '拿到就该立刻返回，不应等满 wait');
    }

    public function testAcquireGivesUpAfterWaitWindow(): void
    {
        $holder = $this->lock('job', 30.0, 'node-a');
        $waiter = $this->lock('job', 30.0, 'node-b');

        $holder->tryAcquire();

        $start = microtime(true);
        $this->assertFalse($waiter->acquire(wait: 0.05, retryInterval: 0.005));
        $this->assertGreaterThanOrEqual(0.04, microtime(true) - $start);
    }

    public function testAcquireOrFailThrows(): void
    {
        // 变量必须留引用：DistributedLock 析构会兜底释放，临时对象一出语句就把锁放了
        $holder = $this->lock('job', 30.0, 'node-a');
        $holder->tryAcquire();

        $this->expectException(ClusterException::class);

        $this->lock('job', 30.0, 'node-b')->acquireOrFail();
    }

    public function testRefreshExtendsTtl(): void
    {
        $lock = $this->lock('job', 0.05);
        $lock->tryAcquire();

        usleep(30_000);
        $this->assertTrue($lock->refresh(30.0));

        usleep(40_000);   // 原 TTL 早已过，续期后应仍在
        $this->assertTrue($this->store->exists('lock/job'));
        $this->assertSame($lock->token(), $this->store->get('lock/job'));
    }

    public function testRefreshFailsWhenNotHeld(): void
    {
        $this->assertFalse($this->lock()->refresh());
    }

    public function testRefreshFailsAfterLockWasStolen(): void
    {
        $lock = $this->lock('job', 0.03);
        $lock->tryAcquire();

        usleep(50_000);
        $this->store->set('lock/job', 'someone-else');

        $this->assertFalse($lock->refresh(), '锁已易主，续期必须失败');
    }

    public function testRemainingCountsDown(): void
    {
        $lock = $this->lock('job', 30.0);

        $this->assertSame(0.0, $lock->remaining());

        $lock->tryAcquire();

        $this->assertGreaterThan(0.0, $lock->remaining());
        $this->assertLessThanOrEqual(30.0, $lock->remaining());
    }

    public function testWithLockRunsAndReleases(): void
    {
        $lock = $this->lock();

        $result = $lock->withLock(static fn (): string => 'done');

        $this->assertSame('done', $result);
        $this->assertFalse($lock->isHeld(), '回调结束必须释放');
        $this->assertFalse($lock->isLocked());
    }

    public function testWithLockReleasesEvenOnException(): void
    {
        $lock = $this->lock();

        try {
            $lock->withLock(static function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('异常应向上抛出');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse($lock->isLocked(), '异常路径也必须释放，否则锁泄漏');
    }

    public function testWithLockThrowsWhenUnavailable(): void
    {
        $holder = $this->lock('job', 30.0, 'node-a');
        $holder->tryAcquire();

        $this->expectException(ClusterException::class);

        $this->lock('job', 30.0, 'node-b')->withLock(static fn (): string => 'never');
    }

    public function testTryWithLockReturnsDefaultWhenBusy(): void
    {
        $holder = $this->lock('job', 30.0, 'node-a');
        $holder->tryAcquire();

        $result = $this->lock('job', 30.0, 'node-b')->tryWithLock(
            static fn (): string => 'ran',
            default: 'skipped'
        );

        $this->assertSame('skipped', $result);
    }

    public function testTryWithLockRunsWhenFree(): void
    {
        $this->assertSame('ran', $this->lock()->tryWithLock(static fn (): string => 'ran', 'skipped'));
    }

    public function testForceReleaseBreaksAnyLock(): void
    {
        $holder = $this->lock('job', 300.0, 'node-a');
        $holder->tryAcquire();

        $this->assertTrue($this->lock('job', 30.0, 'node-b')->forceRelease());
        $this->assertFalse($this->store->exists('lock/job'));
    }

    public function testDestructorReleasesHeldLock(): void
    {
        $lock = $this->lock('job');
        $lock->tryAcquire();

        unset($lock);

        $this->assertFalse($this->store->exists('lock/job'), '实例销毁应兜底释放，防止进程退出后残留');
    }
}
