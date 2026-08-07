<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Balancer\ConsistentHashBalancer;
use Kode\Process\Cluster\Balancer\LeastConnectionsBalancer;
use Kode\Process\Cluster\Balancer\RandomBalancer;
use Kode\Process\Cluster\Balancer\RoundRobinBalancer;
use Kode\Process\Cluster\Balancer\WeightedRoundRobinBalancer;
use Kode\Process\Cluster\Node;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 五种负载均衡策略。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class BalancerTest extends TestCase
{
    /** @return list<Node> */
    private function nodes(int ...$weights): array
    {
        $nodes = [];
        foreach ($weights as $i => $weight) {
            $nodes[] = new Node('n' . ($i + 1), 'api', '10.0.0.' . ($i + 1), 9501, $weight);
        }

        return $nodes;
    }

    /**
     * 统计各节点被选中的次数。
     *
     * @param  callable(): ?Node $pick
     * @return array<string, int>
     */
    private function tally(callable $pick, int $times): array
    {
        $hits = [];
        for ($i = 0; $i < $times; $i++) {
            $node = $pick();
            if ($node !== null) {
                $hits[$node->id] = ($hits[$node->id] ?? 0) + 1;
            }
        }

        return $hits;
    }

    // ------------------------------------------------------------ 通用契约

    public function testEmptySetThrowsOnSelectAndReturnsNullOnTrySelect(): void
    {
        foreach ([
            new RoundRobinBalancer(),
            new WeightedRoundRobinBalancer(),
            new RandomBalancer(),
            new LeastConnectionsBalancer(),
            new ConsistentHashBalancer(),
        ] as $lb) {
            $this->assertNull($lb->trySelect(), $lb->name());
            $this->assertSame(0, $lb->count(), $lb->name());

            try {
                $lb->select();
                $this->fail($lb->name() . ' 空节点集应抛异常');
            } catch (ClusterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSingleNodeAlwaysSelected(): void
    {
        foreach ([
            new RoundRobinBalancer($this->nodes(100)),
            new WeightedRoundRobinBalancer($this->nodes(100)),
            new RandomBalancer($this->nodes(100)),
            new LeastConnectionsBalancer($this->nodes(100)),
            new ConsistentHashBalancer($this->nodes(100)),
        ] as $lb) {
            $this->assertSame('n1', $lb->select('k')->id, $lb->name());
        }
    }

    public function testNamesAreDistinct(): void
    {
        $names = [
            (new RoundRobinBalancer())->name(),
            (new WeightedRoundRobinBalancer())->name(),
            (new RandomBalancer())->name(),
            (new LeastConnectionsBalancer())->name(),
            (new ConsistentHashBalancer())->name(),
        ];

        $this->assertSame($names, array_unique($names));
    }

    public function testSetNodesIsChainableAndUpdatesCount(): void
    {
        $lb = new RoundRobinBalancer();

        $this->assertSame($lb, $lb->setNodes($this->nodes(100, 100)));
        $this->assertSame(2, $lb->count());
        $this->assertCount(2, $lb->nodes());
    }

    // -------------------------------------------------------------- 轮询

    public function testRoundRobinCyclesEvenly(): void
    {
        $lb = new RoundRobinBalancer($this->nodes(100, 100, 100));

        $seq = [];
        for ($i = 0; $i < 6; $i++) {
            $seq[] = $lb->select()->id;
        }

        $this->assertSame(['n1', 'n2', 'n3', 'n1', 'n2', 'n3'], $seq);
    }

    public function testRoundRobinIgnoresWeights(): void
    {
        $lb   = new RoundRobinBalancer($this->nodes(1, 1000));
        $hits = $this->tally(static fn (): ?Node => $lb->trySelect(), 100);

        $this->assertSame(50, $hits['n1']);
        $this->assertSame(50, $hits['n2']);
    }

    public function testCursorSurvivesIdenticalSetNodes(): void
    {
        $nodes = $this->nodes(100, 100, 100);
        $lb    = new RoundRobinBalancer($nodes);

        $this->assertSame('n1', $lb->select()->id);

        // 注册表每次轮询都会重新喂一遍节点，指纹一致时不能重置游标，
        // 否则永远只会命中第一个节点
        $lb->setNodes($nodes);

        $this->assertSame('n2', $lb->select()->id);
    }

    public function testCursorResetsWhenNodeSetChanges(): void
    {
        $lb = new RoundRobinBalancer($this->nodes(100, 100, 100));
        $lb->select();
        $lb->select();

        $lb->setNodes($this->nodes(100, 100));

        $this->assertSame('n1', $lb->select()->id);
    }

    // ---------------------------------------------------------- 加权轮询

    public function testWeightedRoundRobinRespectsWeights(): void
    {
        $lb   = new WeightedRoundRobinBalancer($this->nodes(500, 300, 200));
        $hits = $this->tally(static fn (): ?Node => $lb->trySelect(), 1000);

        $this->assertSame(500, $hits['n1']);
        $this->assertSame(300, $hits['n2']);
        $this->assertSame(200, $hits['n3']);
    }

    public function testWeightedRoundRobinIsSmoothNotBursty(): void
    {
        $lb = new WeightedRoundRobinBalancer($this->nodes(400, 200, 100));

        $seq = [];
        for ($i = 0; $i < 7; $i++) {
            $seq[] = $lb->select()->id;
        }

        // 平滑加权：4:2:1 应交错分布，而不是 n1 连打 4 次再轮到别人
        $this->assertSame(['n1', 'n2', 'n1', 'n3', 'n1', 'n2', 'n1'], $seq);
    }

    public function testWeightedRoundRobinSkipsZeroWeightNodes(): void
    {
        $lb   = new WeightedRoundRobinBalancer($this->nodes(100, 0));
        $hits = $this->tally(static fn (): ?Node => $lb->trySelect(), 50);

        $this->assertSame(50, $hits['n1']);
        $this->assertArrayNotHasKey('n2', $hits, '权重 0 表示摘流量，一次都不该命中');
    }

    // -------------------------------------------------------------- 随机

    public function testRandomHitsEveryNode(): void
    {
        $lb   = new RandomBalancer($this->nodes(100, 100, 100));
        $hits = $this->tally(static fn (): ?Node => $lb->trySelect(), 600);

        $this->assertCount(3, $hits);
        foreach ($hits as $id => $count) {
            $this->assertGreaterThan(100, $count, $id . ' 偏离均匀分布过大');
        }
    }

    public function testRandomFollowsWeightDistribution(): void
    {
        $lb   = new RandomBalancer($this->nodes(900, 100));
        $hits = $this->tally(static fn (): ?Node => $lb->trySelect(), 2000);

        // 9:1，允许较宽的统计波动，只验证方向正确
        $this->assertGreaterThan(1500, $hits['n1']);
        $this->assertLessThan(500, $hits['n2'] ?? 0);
    }

    // ---------------------------------------------------------- 最少连接

    public function testLeastConnectionsPrefersIdleNode(): void
    {
        $lb    = new LeastConnectionsBalancer($this->nodes(100, 100));
        $nodes = $lb->nodes();

        $lb->acquire($nodes[0]);
        $lb->acquire($nodes[0]);

        $this->assertSame('n2', $lb->select()->id, '应挑连接数最少的那台');
    }

    public function testLeastConnectionsReleaseRestoresBalance(): void
    {
        $lb    = new LeastConnectionsBalancer($this->nodes(100, 100));
        $nodes = $lb->nodes();

        $lb->acquire($nodes[0]);
        $this->assertSame('n2', $lb->select()->id);

        $lb->release($nodes[0]);
        $this->assertSame(['n1' => 0, 'n2' => 0], $lb->inflight());
    }

    public function testLeastConnectionsInflightNeverGoesNegative(): void
    {
        $lb    = new LeastConnectionsBalancer($this->nodes(100));
        $nodes = $lb->nodes();

        $lb->release($nodes[0]);
        $lb->release($nodes[0]);

        $this->assertSame(0, $lb->inflight()['n1']);
    }

    public function testLeastConnectionsRunTracksAndReleases(): void
    {
        $lb = new LeastConnectionsBalancer($this->nodes(100, 100));

        $result = $lb->run(static fn (Node $n): string => $n->id);

        $this->assertContains($result, ['n1', 'n2']);
        $this->assertSame([0, 0], array_values($lb->inflight()), '调用结束应归还计数');
    }

    public function testLeastConnectionsRunReleasesOnException(): void
    {
        $lb = new LeastConnectionsBalancer($this->nodes(100));

        try {
            $lb->run(static function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('异常应向上抛出');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $lb->inflight()['n1'], '异常路径也必须归还，否则连接数只增不减');
    }

    public function testLeastConnectionsUsesClusterWideInflightFromMeta(): void
    {
        $lb = new LeastConnectionsBalancer([
            new Node('n1', 'api', '10.0.0.1', 9501, 100, ['inflight' => 50]),
            new Node('n2', 'api', '10.0.0.2', 9501, 100, ['inflight' => 2]),
        ]);

        $this->assertSame('n2', $lb->select()->id, '节点自报的全局连接数应参与决策');
    }

    public function testIdleNodeAlwaysWinsRegardlessOfWeight(): void
    {
        $lb    = new LeastConnectionsBalancer($this->nodes(200, 100));
        $nodes = $lb->nodes();

        $lb->acquire($nodes[0]);

        $this->assertSame('n2', $lb->select()->id, '完全空闲的节点优先，权重不该盖过这一点');
    }

    public function testLeastConnectionsWeightsScaleTheLoad(): void
    {
        $lb    = new LeastConnectionsBalancer($this->nodes(200, 100));
        $nodes = $lb->nodes();

        // 两台各扛 1 条：加权负载 n1=1/200 < n2=1/100，下一条该给 n1
        $lb->acquire($nodes[0]);
        $lb->acquire($nodes[1]);

        $this->assertSame('n1', $lb->select()->id, '权重高的机器应能多扛一些');
    }

    // ---------------------------------------------------------- 一致性哈希

    public function testConsistentHashIsStableForSameKey(): void
    {
        $lb    = new ConsistentHashBalancer($this->nodes(100, 100, 100));
        $first = $lb->select('user:1001')->id;

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, $lb->select('user:1001')->id);
        }
    }

    public function testConsistentHashSpreadsKeys(): void
    {
        $lb   = new ConsistentHashBalancer($this->nodes(100, 100, 100));
        $hits = [];

        for ($i = 0; $i < 900; $i++) {
            $hits[$lb->select('key:' . $i)->id] = true;
        }

        $this->assertCount(3, $hits, '足够多的键应覆盖到全部节点');
    }

    public function testConsistentHashMinimizesRemappingOnNodeRemoval(): void
    {
        $lb   = new ConsistentHashBalancer($this->nodes(100, 100, 100, 100));
        $keys = array_map(static fn (int $i): string => 'key:' . $i, range(1, 400));

        $before = [];
        foreach ($keys as $k) {
            $before[$k] = $lb->select($k)->id;
        }

        // 摘掉 n4，理论上只有原本落在 n4 上的约 1/4 需要重新映射
        $lb->setNodes(array_slice($this->nodes(100, 100, 100, 100), 0, 3));

        $moved = 0;
        foreach ($keys as $k) {
            if ($lb->select($k)->id !== $before[$k]) {
                $moved++;
            }
        }

        $this->assertLessThan(
            count($keys) * 0.4,
            $moved,
            '一致性哈希的价值就在于摘节点时只搬动少量键，实际搬动 ' . $moved
        );
    }

    public function testConsistentHashWithoutKeyStillSelects(): void
    {
        $lb = new ConsistentHashBalancer($this->nodes(100, 100));

        $this->assertContains($lb->select()->id, ['n1', 'n2']);
    }

    public function testConsistentHashRingScalesWithWeight(): void
    {
        $light = new ConsistentHashBalancer($this->nodes(100));
        $heavy = new ConsistentHashBalancer($this->nodes(400));

        $this->assertGreaterThan($light->ringSize(), $heavy->ringSize(), '权重越高虚拟节点越多');
    }

    public function testConsistentHashLookupMatchesSelect(): void
    {
        $lb = new ConsistentHashBalancer($this->nodes(100, 100, 100));

        $this->assertSame($lb->select('user:7')->id, $lb->lookup('user:7')?->id);
    }
}
