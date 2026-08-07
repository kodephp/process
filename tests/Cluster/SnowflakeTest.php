<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Snowflake;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;

/**
 * 分布式 ID 生成器。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class SnowflakeTest extends TestCase
{
    private FileStore $store;

    private string $path;

    protected function setUp(): void
    {
        $this->path  = sys_get_temp_dir() . '/kode-snowflake-test-' . getmypid() . '-' . uniqid();
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

    public function testAccessors(): void
    {
        $sf = new Snowflake(7);

        $this->assertSame(7, $sf->workerId());
        $this->assertSame(Snowflake::DEFAULT_EPOCH, $sf->epoch());
        $this->assertSame(0, $sf->generated());
    }

    public function testRejectsOutOfRangeWorkerId(): void
    {
        $this->expectException(ClusterException::class);

        new Snowflake(Snowflake::MAX_WORKER_ID + 1);
    }

    public function testRejectsNegativeWorkerId(): void
    {
        $this->expectException(ClusterException::class);

        new Snowflake(-1);
    }

    public function testIdsArePositiveAndUnique(): void
    {
        $sf  = new Snowflake(1);
        $ids = [];

        for ($i = 0; $i < 5000; $i++) {
            $id = $sf->next();
            $this->assertGreaterThan(0, $id);
            $ids[$id] = true;
        }

        $this->assertCount(5000, $ids, '同一实例内 ID 必须互不重复');
        $this->assertSame(5000, $sf->generated());
    }

    public function testIdsAreMonotonicallyIncreasing(): void
    {
        $sf   = new Snowflake(1);
        $prev = 0;

        for ($i = 0; $i < 2000; $i++) {
            $id = $sf->next();
            $this->assertGreaterThan($prev, $id, '趋势递增是主键的价值所在（索引友好）');
            $prev = $id;
        }
    }

    public function testDifferentWorkersNeverCollide(): void
    {
        $a = new Snowflake(1);
        $b = new Snowflake(2);

        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[$a->next()] = true;
            $ids[$b->next()] = true;
        }

        $this->assertCount(2000, $ids, '不同机器 ID 的序列必须完全不相交');
    }

    public function testParseRoundTripsComponents(): void
    {
        $sf = new Snowflake(42);
        $id = $sf->next();

        $parsed = Snowflake::parse($id);

        $this->assertSame(42, $parsed['worker_id']);
        $this->assertSame($id, $parsed['id']);
        $this->assertGreaterThanOrEqual(0, $parsed['sequence']);
        $this->assertLessThanOrEqual(Snowflake::MAX_SEQUENCE, $parsed['sequence']);
        $this->assertEqualsWithDelta(microtime(true), $parsed['timestamp'] / 1000, 5.0);
    }

    public function testBatchReturnsRequestedCount(): void
    {
        $sf  = new Snowflake(3);
        $ids = $sf->batch(100);

        $this->assertCount(100, $ids);
        $this->assertCount(100, array_unique($ids));
        $this->assertSame($ids, array_values(array_filter($ids, static fn (int $i): bool => $i > 0)));
    }

    public function testNextHexIsParseable(): void
    {
        $sf  = new Snowflake(5);
        $hex = $sf->nextHex();

        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
        $this->assertSame(5, Snowflake::parse((int) hexdec($hex))['worker_id']);
    }

    public function testSequenceRollsOverIntoNextMillisecond(): void
    {
        $sf = new Snowflake(1);

        // 连续取远超单毫秒容量（4096）的 ID，验证跨毫秒等待逻辑不会重号
        $ids = $sf->batch(9000);

        $this->assertCount(9000, array_unique($ids));
    }

    // -------------------------------------------------- 机器 ID 的集群分配

    public function testAllocateWorkerIdReturnsDistinctIds(): void
    {
        $a = Snowflake::allocateWorkerId($this->store, 'test');
        $b = Snowflake::allocateWorkerId($this->store, 'test');
        $c = Snowflake::allocateWorkerId($this->store, 'test');

        $this->assertCount(3, array_unique([$a, $b, $c]), '同命名空间内不能分到同一个机器 ID');

        foreach ([$a, $b, $c] as $id) {
            $this->assertGreaterThanOrEqual(0, $id);
            $this->assertLessThanOrEqual(Snowflake::MAX_WORKER_ID, $id);
        }
    }

    public function testAllocationStartsAtRandomOffset(): void
    {
        // 从随机位置探测，避免多节点同时启动时都去抢 0 号
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $store = new FileStore(['path' => $this->path, 'prefix' => 'probe' . $i]);
            $ids[] = Snowflake::allocateWorkerId($store, 'test');
            $store->flush();
        }

        $this->assertGreaterThan(1, count(array_unique($ids)), '每次都从 0 开始就失去了抗碰撞意义');
    }

    public function testNamespacesDoNotConsumeEachOthersSlots(): void
    {
        $a = Snowflake::allocateWorkerId($this->store, 'svc-a');

        // svc-a 占了 $a 号，svc-b 的同号槽位应仍然空闲
        $this->assertTrue(
            $this->store->setIfAbsent('snowflake/svc-b/' . $a, 'probe', 10_000),
            '不同命名空间各自独立分配 0~1023，互不占用'
        );
    }

    public function testRenewKeepsLease(): void
    {
        $id = Snowflake::allocateWorkerId($this->store, 'test');

        $this->assertTrue(Snowflake::renewWorkerId($this->store, 'test', $id));
    }

    public function testRenewFailsForForeignLease(): void
    {
        $id = Snowflake::allocateWorkerId($this->store, 'test');

        // 模拟租约被别的进程抢走
        $this->store->set('snowflake/test/' . $id, 'someone-else');

        $this->assertFalse(Snowflake::renewWorkerId($this->store, 'test', $id));
    }

    public function testReleaseFreesIdForReuse(): void
    {
        $first = Snowflake::allocateWorkerId($this->store, 'test');

        $this->assertTrue(Snowflake::releaseWorkerId($this->store, 'test', $first));
        $this->assertFalse($this->store->exists('snowflake/test/' . $first));

        // 槽位真的空出来了：别的持有者能立刻占用同一号
        $this->assertTrue($this->store->setIfAbsent('snowflake/test/' . $first, 'other', 10_000));
    }

    public function testReleaseDoesNotFreeAnotherHoldersId(): void
    {
        $id = Snowflake::allocateWorkerId($this->store, 'test');

        $this->assertFalse(Snowflake::releaseWorkerId($this->store, 'test', $id, owner: 'someone-else'));
        $this->assertTrue($this->store->exists('snowflake/test/' . $id), '不能归还别人持有的机器 ID');
    }
}
