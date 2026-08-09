<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\RateLimiter;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;

/**
 * 回归：存储后端故障时限流必须 fail-closed。
 *
 * 修复前 increment() 会把失败强转成 0，`0 <= limit` 恒真 ——
 * Redis 一挂，全集群限流形同虚设，正是刷单/爆破最想要的窗口。
 */
final class RateLimiterFailClosedTest extends TestCase
{
    private FakeStore $store;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->store   = new FakeStore();
        $this->limiter = new RateLimiter($this->store);
    }

    public function testAttemptDeniesWhenStoreIncrementFails(): void
    {
        $this->assertTrue($this->limiter->attempt('user:1', 5, 60.0), '后端正常时应放行');

        $this->store->failIncrement = true;

        $this->assertFalse(
            $this->limiter->attempt('user:1', 5, 60.0),
            '后端故障时必须拒绝，放行等于把限流整个关掉'
        );
    }

    public function testAttemptStaysClosedForEveryRequestWhileStoreIsDown(): void
    {
        $this->store->failIncrement = true;

        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse($this->limiter->attempt('api', 1000, 60.0), "第 {$i} 次仍应拒绝");
        }
    }

    public function testThrottleInheritsFailClosedBehaviour(): void
    {
        $this->store->failIncrement = true;

        $ran = 0;
        $fn  = static function () use (&$ran): string {
            $ran++;

            return 'ran';
        };

        $this->assertSame('degraded', $this->limiter->throttle('api', 100, 60.0, $fn, static fn (): string => 'degraded'));
        $this->assertSame(0, $ran, '后端故障时业务回调不该被执行');

        $this->expectException(ClusterException::class);
        $this->limiter->throttle('api', 100, 60.0, $fn);
    }

    public function testRecoversAfterStoreComesBack(): void
    {
        $this->store->failIncrement = true;
        $this->assertFalse($this->limiter->attempt('api', 3, 60.0));

        $this->store->failIncrement = false;
        $this->assertTrue($this->limiter->attempt('api', 3, 60.0), '后端恢复后应重新放行');
    }
}
