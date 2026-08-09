<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Balancer\ConsistentHashBalancer;
use Kode\Process\Cluster\Balancer\WeightedRoundRobinBalancer;
use Kode\Process\Cluster\Node;
use PHPUnit\Framework\TestCase;

/**
 * 回归：同一批节点换个顺序喂进来，内部状态必须重建。
 *
 * 注册表返回的节点顺序并不稳定（扫描顺序、心跳先后都会影响）。修复前指纹会先
 * 排序再比较，顺序变化被判为「没变」而跳过重建，但哈希环与加权游标都是按**下标**
 * 索引 $nodes 的——下标没动、节点却换了位置，key 就被静默路由到了别的机器上。
 */
final class BalancerNodeOrderTest extends TestCase
{
    /** @return array<string, Node> */
    private function nodeMap(): array
    {
        return [
            'a' => new Node('a', 'cache', '10.0.0.1', 9501),
            'b' => new Node('b', 'cache', '10.0.0.2', 9501),
            'c' => new Node('c', 'cache', '10.0.0.3', 9501),
        ];
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_map(static fn (int $i): string => 'user:' . $i, range(1, 200));
    }

    public function testConsistentHashMappingSurvivesNodeReordering(): void
    {
        $nodes = $this->nodeMap();

        $lb = new ConsistentHashBalancer([$nodes['a'], $nodes['b'], $nodes['c']]);

        $before = [];
        foreach ($this->keys() as $key) {
            $before[$key] = $lb->select($key)->id;
        }

        // 同一批节点，仅顺序不同
        $lb->setNodes([$nodes['c'], $nodes['b'], $nodes['a']]);

        foreach ($this->keys() as $key) {
            $this->assertSame(
                $before[$key],
                $lb->select($key)->id,
                "节点顺序变化不该改变 {$key} 的归属"
            );
        }
    }

    public function testWeightedRoundRobinKeepsWeightRatioAfterReordering(): void
    {
        $heavy = new Node('heavy', 'api', '10.0.0.1', 9501, 900);
        $light = new Node('light', 'api', '10.0.0.2', 9501, 100);

        $lb = new WeightedRoundRobinBalancer([$heavy, $light]);
        $lb->setNodes([$light, $heavy]);

        $hits = [];
        for ($i = 0; $i < 100; $i++) {
            $id        = $lb->select()->id;
            $hits[$id] = ($hits[$id] ?? 0) + 1;
        }

        $this->assertSame(90, $hits['heavy'], '权重表下标必须跟着顺序一起重建');
        $this->assertSame(10, $hits['light']);
    }

    public function testIdenticalOrderStillSkipsRebuild(): void
    {
        $nodes = array_values($this->nodeMap());
        $lb    = new WeightedRoundRobinBalancer($nodes);

        $first = $lb->select()->id;
        $lb->setNodes($nodes);

        $this->assertNotSame($first, $lb->select()->id, '顺序一致时不该重置游标，否则永远只命中第一个节点');
    }
}
