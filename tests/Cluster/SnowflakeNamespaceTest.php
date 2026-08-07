<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster;
use Kode\Process\Cluster\Snowflake;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;

/**
 * Snowflake 命名空间记忆 + 优雅下线归还机器 ID。
 *
 * 回归点：
 *  - Cluster::snowflake(null, 'order') 用 order 命名空间分配，但 renewSnowflake() 默认 default，
 *    会导致 CAS 失败、ID 抖动——修复后 renew 应记住分配时的命名空间。
 *  - Cluster::leave() 此前未归还 Snowflake workerId，机器 ID 会一直占用到 TTL 过期；
 *    修复后 leave 应主动释放。
 */
final class SnowflakeNamespaceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kode-sf-test-' . getmypid() . '-' . uniqid();

        Cluster::reset();
        Cluster::useStore(new FileStore(['path' => $this->path]));
    }

    protected function tearDown(): void
    {
        Cluster::store()->flush();
        Cluster::reset();

        foreach ((array) glob($this->path . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->path);
    }

    public function testSnowflakeRemembersNamespaceForRenewal(): void
    {
        $sf = Cluster::snowflake(null, 'order');
        $workerId = $sf->workerId();

        // 不传命名空间，应复用分配时的 'order' 命名空间续租成功，而非误用 default
        $this->assertTrue(Cluster::renewSnowflake(), '续租应记住分配时的命名空间');

        // 用相同命名空间再次取用时，应命中缓存、worker ID 不变（没有因 CAS 失败被迫重分配）
        $this->assertSame($workerId, Cluster::snowflake(null, 'order')->workerId());
    }

    public function testSnowflakeDifferentNamespacesAllocateIndependently(): void
    {
        $a = Cluster::snowflake(null, 'ns-a');
        $this->assertInstanceOf(Snowflake::class, $a);
        // 与当前实例匹配的命名空间续租应成功
        $this->assertTrue(Cluster::renewSnowflake('ns-a'), 'ns-a 续租应成功');

        // 切换命名空间会重新分配（ns-b 是独立 0~1023 空间）
        $b = Cluster::snowflake(null, 'ns-b');
        $this->assertInstanceOf(Snowflake::class, $b);
        $this->assertTrue(Cluster::renewSnowflake('ns-b'), 'ns-b 续租应成功');
    }

    public function testLeaveReleasesWorkerId(): void
    {
        Cluster::join(['id' => 'node-1', 'service' => 'api', 'port' => 9501]);
        $sf       = Cluster::snowflake();
        $workerId = $sf->workerId();
        $key      = 'snowflake/default/' . $workerId;

        // 分配后存储里应有该机器 ID
        $this->assertTrue(Cluster::store()->exists($key), '分配后机器 ID 应写入存储');

        $this->assertTrue(Cluster::leave());

        // 优雅下线后机器 ID 必须被归还，不应长期占位到 TTL 过期
        $this->assertFalse(Cluster::store()->exists($key), 'leave() 应主动归还机器 ID');
        $this->assertNull(Cluster::self());
    }

    public function testAllocateWorkerIdThrowsWhenExhausted(): void
    {
        $store = Cluster::store();

        // 占满 0~1023 共 1024 个槽位
        for ($i = 0; $i <= Snowflake::MAX_WORKER_ID; $i++) {
            $ok = $store->setIfAbsent('snowflake/full/' . $i, 'owner', 60_000);
            if (!$ok) {
                $this->fail('setIfAbsent 在空槽位上应成功，i=' . $i);
            }
        }

        $this->expectException(ClusterException::class);
        Snowflake::allocateWorkerId($store, 'full');
    }
}
