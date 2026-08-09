<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster;
use Kode\Process\Cluster\Snowflake;
use PHPUnit\Framework\TestCase;

/**
 * 回归：Snowflake 租约丢失后必须**就地换绑**机器 ID。
 *
 * 修复前 renewSnowflake() 只是把门面里的实例换成新的，业务侧早就持有的
 * 旧实例仍拿着已被别人占走的 workerId 继续发号——两台机器同时用同一个
 * workerId，撞号且毫无察觉。
 */
final class SnowflakeRebindTest extends TestCase
{
    private FakeStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeStore();
        Cluster::reset();
        Cluster::useStore($this->store);
    }

    protected function tearDown(): void
    {
        Cluster::reset();
    }

    public function testRenewRebindsTheSameInstanceAfterLeaseLoss(): void
    {
        $sf  = Cluster::snowflake();
        $old = $sf->workerId();

        // 租约被别人抢走
        $this->store->set('snowflake/default/' . $old, 'someone-else');

        $this->assertFalse(Cluster::renewSnowflake(), '丢租约时返回 false');

        $this->assertSame($sf, Cluster::snowflake(), '必须复用同一个实例，否则旧引用继续用废掉的 workerId');
        $this->assertNotSame($old, $sf->workerId(), '业务侧持有的这个对象本身就该看到新机器 ID');
        $this->assertSame($sf->workerId(), Cluster::snowflake()->workerId());
    }

    public function testIdsStayUniqueAndCarryNewWorkerIdAfterRebind(): void
    {
        $sf  = Cluster::snowflake();
        $old = $sf->workerId();

        $before = $sf->batch(50);

        $this->store->set('snowflake/default/' . $old, 'someone-else');
        Cluster::renewSnowflake();

        $after = $sf->batch(50);
        $ids   = array_merge($before, $after);

        $this->assertCount(100, array_unique($ids), '换绑前后生成的 ID 必须整体唯一');
        $this->assertSame($sf->workerId(), Snowflake::parse($after[0])['worker_id'], '新 ID 应带上新机器 ID');
    }

    public function testRebindRejectsOutOfRangeWorkerId(): void
    {
        $sf = new Snowflake(1);

        $this->expectException(\Kode\Process\Exceptions\ClusterException::class);
        $sf->rebind(Snowflake::MAX_WORKER_ID + 1);
    }

    public function testRenewUsesCachedOwnerSoLeaseSurvives(): void
    {
        $sf = Cluster::snowflake();

        $this->assertTrue(Cluster::renewSnowflake(), '持有者标识应与分配时一致，续租不该失败');
        $this->assertTrue(Cluster::renewSnowflake());
        $this->assertSame($sf->workerId(), Cluster::snowflake()->workerId());
    }
}
