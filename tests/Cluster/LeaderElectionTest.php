<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Election\LeaderElection;
use Kode\Process\Cluster\Store\FileStore;
use PHPUnit\Framework\TestCase;

/**
 * Leader 选举——让定时任务在全集群只跑一次。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class LeaderElectionTest extends TestCase
{
    private FileStore $store;

    private string $path;

    protected function setUp(): void
    {
        $this->path  = sys_get_temp_dir() . '/kode-election-test-' . getmypid() . '-' . uniqid();
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

    private function election(string $nodeId, float $ttl = 30.0, string $name = 'cron'): LeaderElection
    {
        return new LeaderElection($this->store, $name, $nodeId, $ttl);
    }

    public function testAccessors(): void
    {
        $e = $this->election('n1', 15.0);

        $this->assertSame('cron', $e->name());
        $this->assertSame('n1', $e->nodeId());
        $this->assertSame(15.0, $e->ttl());
        $this->assertFalse($e->isLeader());
        $this->assertSame(0, $e->terms());
    }

    public function testSuggestedIntervalIsOneThirdOfTtl(): void
    {
        $this->assertEqualsWithDelta(5.0, $this->election('n1', 15.0)->suggestedInterval(), 0.001);
    }

    public function testFirstTickWins(): void
    {
        $e = $this->election('n1');

        $this->assertTrue($e->tick());
        $this->assertTrue($e->isLeader());
        $this->assertSame(1, $e->terms());
    }

    public function testOnlyOneLeaderAtATime(): void
    {
        $a = $this->election('node-a');
        $b = $this->election('node-b');

        $this->assertTrue($a->tick());
        $this->assertFalse($b->tick(), '同一时刻只能有一个 Leader');

        $this->assertTrue($a->isLeader());
        $this->assertFalse($b->isLeader());
    }

    public function testLeaderIdVisibleToFollowers(): void
    {
        $a = $this->election('node-a');
        $b = $this->election('node-b');

        $this->assertNull($b->leaderId());

        $a->tick();

        $this->assertSame('node-a', $b->leaderId(), '跟随者应能看出谁是 Leader');
        $this->assertSame('node-a', $a->leaderId());
    }

    public function testTickRenewsWithoutIncrementingTerm(): void
    {
        $a = $this->election('node-a');

        $a->tick();
        $a->tick();
        $a->tick();

        $this->assertTrue($a->isLeader());
        $this->assertSame(1, $a->terms(), '连任不算新任期');
    }

    public function testResignReleasesLeadership(): void
    {
        $a = $this->election('node-a');
        $b = $this->election('node-b');

        $a->tick();
        $this->assertTrue($a->resign());

        $this->assertFalse($a->isLeader());
        $this->assertNull($b->leaderId());
        $this->assertTrue($b->tick(), '让位后其它节点应能立刻接手');
    }

    public function testResignByNonLeaderIsNoop(): void
    {
        $a = $this->election('node-a');
        $b = $this->election('node-b');

        $a->tick();

        $this->assertFalse($b->resign());
        $this->assertSame('node-a', $b->leaderId(), '非 Leader 让位不能把别人踢下台');
    }

    public function testFollowerTakesOverAfterLeaseExpires(): void
    {
        $dead = $this->election('node-dead', 0.03);
        $next = $this->election('node-next', 30.0);

        $this->assertTrue($dead->tick());
        $this->assertFalse($next->tick());

        usleep(50_000);   // Leader「崩溃」，租约到期

        $this->assertTrue($next->tick(), '租约到期后必须能重新选主');
        $this->assertSame('node-next', $next->leaderId());
    }

    public function testCallbacksFireOnStateTransitions(): void
    {
        $events = [];

        $a = $this->election('node-a', 0.03);
        $a->onElected(static function () use (&$events): void {
            $events[] = 'elected';
        });
        $a->onResigned(static function () use (&$events): void {
            $events[] = 'resigned';
        });

        $a->tick();
        $this->assertSame(['elected'], $events);

        $a->tick();
        $this->assertSame(['elected'], $events, '连任不应重复触发');

        $a->resign();
        $this->assertSame(['elected', 'resigned'], $events);
    }

    public function testResignedFiresWhenLeadershipIsLost(): void
    {
        $lost = false;

        $a = $this->election('node-a', 0.03);
        $a->onResigned(static function () use (&$lost): void {
            $lost = true;
        });

        $a->tick();

        // 租约到期后被别人抢走
        usleep(50_000);
        $this->election('node-b', 30.0)->tick();

        $this->assertFalse($a->tick(), '续租应失败');
        $this->assertTrue($lost, '丢失领导权必须回调，好让业务停掉只有 Leader 该做的事');
    }

    public function testIfLeaderRunsOnlyForLeader(): void
    {
        $a = $this->election('node-a');
        $b = $this->election('node-b');

        $a->tick();
        $b->tick();

        $this->assertSame('ran', $a->ifLeader(static fn (): string => 'ran'));
        $this->assertNull($b->ifLeader(static fn (): string => 'ran'));
    }

    public function testStatsSnapshot(): void
    {
        $a = $this->election('node-a', 20.0);
        $a->tick();

        $stats = $a->stats();

        $this->assertSame('cron', $stats['name']);
        $this->assertSame('node-a', $stats['node_id']);
        $this->assertTrue($stats['is_leader']);
        $this->assertSame(1, $stats['terms']);
        $this->assertSame('node-a', $stats['leader_id']);
        $this->assertSame(20.0, $stats['ttl']);
    }

    public function testIndependentElectionsDoNotInterfere(): void
    {
        $cron   = new LeaderElection($this->store, 'cron', 'node-a', 30.0);
        $report = new LeaderElection($this->store, 'report', 'node-b', 30.0);

        $this->assertTrue($cron->tick());
        $this->assertTrue($report->tick(), '不同选举名互不影响，可各自选出 Leader');
    }
}
