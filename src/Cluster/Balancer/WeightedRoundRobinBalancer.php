<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;

/**
 * 平滑加权轮询（Smooth Weighted Round-Robin，Nginx upstream 同款算法）。
 *
 * 相比「按权重展开成列表再轮询」的朴素做法，本算法在保证长期比例正确的同时，
 * 把高权重节点的请求**均匀摊开**，不会出现 `A A A A B` 这种突发聚集。
 *
 * 权重 5:1:1 时的派发序列：
 *
 * ```
 * 朴素展开： A A A A A B C        ← 前 5 个请求全砸在 A 上
 * 平滑加权： A A B A C A A        ← A 仍占 5/7，但分布均匀
 * ```
 *
 * 算法（每次选择）：
 * 1. 每个节点 `current += weight`
 * 2. 取 `current` 最大者为本次结果
 * 3. 被选中者 `current -= 总权重`
 *
 * @since 5.0.0
 */
final class WeightedRoundRobinBalancer extends AbstractBalancer
{
    /**
     * 各节点的动态当前权重。
     *
     * @var array<int, int>
     */
    private array $current = [];

    /** 权重总和。 */
    private int $total = 0;

    public function name(): string
    {
        return 'weighted';
    }

    public function trySelect(?string $key = null): ?Node
    {
        $count = count($this->nodes);
        if ($count === 0) {
            return null;
        }
        if ($count === 1) {
            return $this->nodes[0];
        }

        $bestIndex = -1;
        $bestValue = PHP_INT_MIN;

        foreach ($this->nodes as $i => $node) {
            $this->current[$i] += $this->weightOf($node);

            if ($this->current[$i] > $bestValue) {
                $bestValue = $this->current[$i];
                $bestIndex = $i;
            }
        }

        if ($bestIndex < 0) {
            return null;
        }

        $this->current[$bestIndex] -= $this->total;

        return $this->nodes[$bestIndex];
    }

    /** 权重下限为 1，避免 0 权重导致节点永不被选中或除零。 */
    private function weightOf(Node $node): int
    {
        return max(1, $node->weight);
    }

    protected function onNodesChanged(): void
    {
        $this->current = [];
        $this->total   = 0;

        foreach ($this->nodes as $i => $node) {
            $this->current[$i] = 0;
            $this->total      += $this->weightOf($node);
        }
    }
}
