<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\RateLimiter;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;

/**
 * 分布式限流——限的是集群总量，不是每台各限一份。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class RateLimiterTest extends TestCase
{
    private FileStore $store;

    private RateLimiter $limiter;

    private string $path;

    protected function setUp(): void
    {
        $this->path    = sys_get_temp_dir() . '/kode-limiter-test-' . getmypid() . '-' . uniqid();
        $this->store   = new FileStore(['path' => $this->path]);
        $this->limiter = new RateLimiter($this->store);
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

    // ---------------------------------------------------------- 固定窗口

    public function testAttemptAllowsUpToLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->limiter->attempt('user:1', 5, 60.0), "第 {$i} 次应放行");
        }

        $this->assertFalse($this->limiter->attempt('user:1', 5, 60.0), '第 6 次应拒绝');
    }

    public function testAttemptWithZeroLimitAlwaysRejects(): void
    {
        $this->assertFalse($this->limiter->attempt('user:1', 0));
    }

    public function testKeysAreIsolated(): void
    {
        $this->limiter->attempt('user:1', 1, 60.0);

        $this->assertFalse($this->limiter->attempt('user:1', 1, 60.0));
        $this->assertTrue($this->limiter->attempt('user:2', 1, 60.0), '不同维度不该互相影响');
    }

    public function testCostConsumesMultipleSlots(): void
    {
        $this->assertTrue($this->limiter->attempt('api', 10, 60.0, cost: 7));
        $this->assertSame(3, $this->limiter->remaining('api', 10, 60.0));
        $this->assertFalse($this->limiter->attempt('api', 10, 60.0, cost: 5));
    }

    public function testRemainingCountsDown(): void
    {
        $this->assertSame(3, $this->limiter->remaining('user:1', 3, 60.0));

        $this->limiter->attempt('user:1', 3, 60.0);
        $this->assertSame(2, $this->limiter->remaining('user:1', 3, 60.0));

        $this->limiter->attempt('user:1', 3, 60.0);
        $this->limiter->attempt('user:1', 3, 60.0);
        $this->assertSame(0, $this->limiter->remaining('user:1', 3, 60.0));
    }

    public function testRemainingNeverGoesNegative(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->attempt('user:1', 2, 60.0);
        }

        $this->assertSame(0, $this->limiter->remaining('user:1', 2, 60.0));
    }

    public function testWindowRollsOverAndResetsQuota(): void
    {
        $window = 0.2;

        $this->assertTrue($this->limiter->attempt('burst', 1, $window));
        $this->assertFalse($this->limiter->attempt('burst', 1, $window));

        // 睡到下一个窗口槽位
        usleep((int) ($this->limiter->resetsIn($window) * 1_000_000) + 20_000);

        $this->assertTrue($this->limiter->attempt('burst', 1, $window), '新窗口应重新放行');
    }

    public function testResetsInStaysWithinWindow(): void
    {
        $remaining = $this->limiter->resetsIn(60.0);

        $this->assertGreaterThan(0.0, $remaining);
        $this->assertLessThanOrEqual(60.0, $remaining);
    }

    public function testResetClearsCounter(): void
    {
        $this->limiter->attempt('user:1', 1, 60.0);
        $this->assertFalse($this->limiter->attempt('user:1', 1, 60.0));

        $this->assertTrue($this->limiter->reset('user:1', 60.0));
        $this->assertTrue($this->limiter->attempt('user:1', 1, 60.0));
    }

    public function testThrottleRunsCallbackWhenAllowed(): void
    {
        $this->assertSame('ok', $this->limiter->throttle('user:1', 1, 60.0, static fn (): string => 'ok'));
    }

    public function testThrottleThrowsWhenLimitedAndNoFallback(): void
    {
        $ran = 0;
        $fn  = static function () use (&$ran): string {
            $ran++;

            return 'ran';
        };

        $this->limiter->throttle('user:1', 1, 60.0, $fn);

        try {
            $this->limiter->throttle('user:1', 1, 60.0, $fn);
            $this->fail('超限且未提供兜底回调时应抛异常');
        } catch (ClusterException $e) {
            $this->assertStringContainsString('user:1', $e->getMessage());
        }

        $this->assertSame(1, $ran, '超限时业务回调根本不该被执行');
    }

    public function testThrottleUsesFallbackWhenLimited(): void
    {
        $fn = static fn (): string => 'ran';

        $this->limiter->throttle('user:1', 1, 60.0, $fn);

        $result = $this->limiter->throttle('user:1', 1, 60.0, $fn, static fn (): string => 'degraded');

        $this->assertSame('degraded', $result);
    }

    // ---------------------------------------------------------- 令牌桶

    public function testTokenBucketAllowsBurstUpToCapacity(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->limiter->consume('api', 5, 1.0), "突发第 {$i} 个应放行");
        }

        $this->assertFalse($this->limiter->consume('api', 5, 1.0), '桶空后应拒绝');
    }

    public function testTokenBucketRefillsOverTime(): void
    {
        // 容量 1、每秒补 50 个 → 20ms 补一个
        $this->assertTrue($this->limiter->consume('api', 1, 50.0));
        $this->assertFalse($this->limiter->consume('api', 1, 50.0));

        usleep(40_000);

        $this->assertTrue($this->limiter->consume('api', 1, 50.0), '补充后应重新放行');
    }

    public function testTokenBucketRejectsInvalidParameters(): void
    {
        $this->assertFalse($this->limiter->consume('api', 0, 1.0));
        $this->assertFalse($this->limiter->consume('api', 5, 0.0));
        $this->assertFalse($this->limiter->consume('api', 5, 1.0, tokens: 0));
    }

    public function testTokenBucketRejectsRequestLargerThanCapacity(): void
    {
        $this->assertFalse($this->limiter->consume('api', 5, 1.0, tokens: 10), '一次要的比桶还大，永远给不了');
    }

    public function testTokensReportsCurrentLevel(): void
    {
        $this->assertEqualsWithDelta(5.0, $this->limiter->tokens('api', 5, 1.0), 0.01, '未使用时桶是满的');

        $this->limiter->consume('api', 5, 1.0, tokens: 2);

        $this->assertEqualsWithDelta(3.0, $this->limiter->tokens('api', 5, 1.0), 0.1);
    }

    public function testTokenBucketDoesNotOverfill(): void
    {
        $this->limiter->consume('api', 3, 1000.0, tokens: 3);

        usleep(30_000);   // 按 1000/s 早该补满并溢出

        $this->assertLessThanOrEqual(3.0, $this->limiter->tokens('api', 3, 1000.0), '桶不能超过容量');
    }

    public function testFixedWindowAndTokenBucketUseSeparateKeys(): void
    {
        $this->limiter->attempt('shared', 1, 60.0);

        $this->assertTrue($this->limiter->consume('shared', 5, 1.0), '两种算法各用各的计数，不应串台');
    }
}
