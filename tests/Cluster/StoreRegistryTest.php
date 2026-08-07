<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Node;
use Kode\Process\Cluster\NodeStatus;
use Kode\Process\Cluster\Registry\StoreRegistry;
use Kode\Process\Cluster\Store\FileStore;
use PHPUnit\Framework\TestCase;

/**
 * 服务注册与发现。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class StoreRegistryTest extends TestCase
{
    private FileStore $store;

    private StoreRegistry $registry;

    private string $path;

    protected function setUp(): void
    {
        $this->path     = sys_get_temp_dir() . '/kode-registry-test-' . getmypid() . '-' . uniqid();
        $this->store    = new FileStore(['path' => $this->path]);
        $this->registry = new StoreRegistry($this->store, 15.0);
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

    public function testTtlAndStoreAccessors(): void
    {
        $this->assertSame(15.0, $this->registry->ttl());
        $this->assertSame($this->store, $this->registry->store());
    }

    public function testRegisterStampsTimestampsAndStatus(): void
    {
        $before = microtime(true);
        $node   = $this->registry->register(new Node('n1', 'api', '10.0.0.1', 9501));

        $this->assertSame(NodeStatus::Up, $node->status);
        $this->assertGreaterThanOrEqual($before, $node->registeredAt);
        $this->assertGreaterThanOrEqual($before, $node->heartbeatAt);
    }

    public function testRegisterPreservesOriginalRegisteredAtOnReRegister(): void
    {
        $first = $this->registry->register(new Node('n1', 'api'));
        usleep(5_000);
        $again = $this->registry->register($first);

        $this->assertSame($first->registeredAt, $again->registeredAt, '重注册不应刷新首次上线时间');
        $this->assertGreaterThan($first->heartbeatAt, $again->heartbeatAt);
    }

    public function testFindReturnsRegisteredNode(): void
    {
        $this->registry->register(new Node('n1', 'api', '10.0.0.1', 9501, 200, ['zone' => 'sh']));

        $found = $this->registry->find('n1', 'api');

        $this->assertNotNull($found);
        $this->assertSame('n1', $found->id);
        $this->assertSame(9501, $found->port);
        $this->assertSame(200, $found->weight);
        $this->assertSame('sh', $found->meta('zone'));
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $this->assertNull($this->registry->find('ghost', 'api'));
    }

    public function testNodesFilterByService(): void
    {
        $this->registry->register(new Node('a1', 'api'));
        $this->registry->register(new Node('a2', 'api'));
        $this->registry->register(new Node('w1', 'web'));

        $this->assertCount(2, $this->registry->nodes('api'));
        $this->assertCount(1, $this->registry->nodes('web'));
        $this->assertCount(3, $this->registry->nodes(), '不传 service 应返回全部');
    }

    public function testNodesAreStablySorted(): void
    {
        $this->registry->register(new Node('z', 'web'));
        $this->registry->register(new Node('b', 'api'));
        $this->registry->register(new Node('a', 'api'));

        $ids = array_map(static fn (Node $n): string => $n->service . '/' . $n->id, $this->registry->nodes());

        $this->assertSame(['api/a', 'api/b', 'web/z'], $ids, '排序必须稳定，否则轮询会乱跳');
    }

    public function testServicesListsDistinctNames(): void
    {
        $this->registry->register(new Node('a1', 'api'));
        $this->registry->register(new Node('a2', 'api'));
        $this->registry->register(new Node('w1', 'web'));

        $services = $this->registry->services();
        sort($services);

        $this->assertSame(['api', 'web'], $services);
    }

    public function testHeartbeatRefreshesTimestamp(): void
    {
        $node = $this->registry->register(new Node('n1', 'api'));
        usleep(5_000);

        $this->assertTrue($this->registry->heartbeat('n1', 'api'));

        $found = $this->registry->find('n1', 'api');
        $this->assertNotNull($found);
        $this->assertGreaterThan($node->heartbeatAt, $found->heartbeatAt);
    }

    public function testHeartbeatReturnsFalseWhenNodeWasPruned(): void
    {
        $this->assertFalse($this->registry->heartbeat('never-registered', 'api'));
    }

    public function testDeregisterRemovesNode(): void
    {
        $this->registry->register(new Node('n1', 'api'));

        $this->assertTrue($this->registry->deregister('n1', 'api'));
        $this->assertNull($this->registry->find('n1', 'api'));
        $this->assertSame([], $this->registry->nodes('api'));
    }

    public function testStaleNodeBecomesSuspectThenIsHiddenFromHealthyList(): void
    {
        // ttl 极小，直接把心跳年龄推进 Suspect 区间
        $registry = new StoreRegistry($this->store, 0.02);
        $registry->register(new Node('n1', 'api'));

        usleep(30_000);   // 1×ttl < age < 2×ttl → Suspect

        $this->assertSame([], $registry->nodes('api'), '仅健康节点时 Suspect 不返回');

        $all = $registry->nodes('api', false);
        $this->assertCount(1, $all);
        $this->assertSame(NodeStatus::Suspect, $all[0]->status);
    }

    public function testDiffDetectsAddedRemovedAndChanged(): void
    {
        $this->registry->register(new Node('a', 'api', '10.0.0.1', 9501));

        // 首次 diff 建立基线：现存节点全部报成 added
        $first = $this->registry->diff('api');
        $this->assertCount(1, $first['added']);

        // 紧接着再 diff：拓扑未变，三项皆空
        $noChange = $this->registry->diff('api');
        $this->assertSame([], $noChange['added']);
        $this->assertSame([], $noChange['removed']);
        $this->assertSame([], $noChange['changed']);

        $this->registry->register(new Node('b', 'api', '10.0.0.2', 9502));
        $this->registry->deregister('a', 'api');

        $diff = $this->registry->diff('api');

        $this->assertCount(1, $diff['added']);
        $this->assertSame('b', $diff['added'][0]->id);
        $this->assertSame(['a'], array_map(static fn (Node $n): string => $n->id, $diff['removed']));
    }

    public function testResetSnapshotReplaysEverythingAsAdded(): void
    {
        $this->registry->register(new Node('a', 'api'));
        $this->registry->diff('api');

        $this->registry->resetSnapshot('api');

        $this->assertCount(1, $this->registry->diff('api')['added']);
    }

    public function testDiffReportsAddressChangeAsChanged(): void
    {
        $this->registry->register(new Node('a', 'api', '10.0.0.1', 9501));
        $this->registry->diff('api');

        $this->registry->register(new Node('a', 'api', '10.0.0.9', 9501));
        $diff = $this->registry->diff('api');

        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('10.0.0.9', $diff['changed'][0]->host);
    }

    public function testDiffIgnoresHeartbeatOnlyUpdates(): void
    {
        $this->registry->register(new Node('a', 'api', '10.0.0.1', 9501));
        $this->registry->diff('api');

        usleep(3_000);
        $this->registry->heartbeat('a', 'api');

        $diff = $this->registry->diff('api');

        $this->assertSame([], $diff['changed'], '心跳刷新不算拓扑变化，否则每秒都在「变」');
    }
}
