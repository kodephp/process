<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;

/**
 * 加权随机：按权重比例随机命中。
 *
 * 无状态——多进程、多机之间不需要同步游标，天然适合无共享的部署形态。
 * 单次调用不保证均匀，请求量上来后趋近权重比例。
 *
 * @since 5.0.0
 */
final class RandomBalancer extends AbstractBalancer
{
    /**
     * 权重前缀和，用于二分查找。
     *
     * @var list<int>
     */
    private array $cumulative = [];

    private int $total = 0;

    public function name(): string
    {
        return 'random';
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

        $point = random_int(1, $this->total);

        // 前缀和 + 二分，O(log n) 而非逐个累减
        $lo = 0;
        $hi = $count - 1;

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->cumulative[$mid] < $point) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $this->nodes[$lo];
    }

    protected function onNodesChanged(): void
    {
        $this->cumulative = [];
        $this->total      = 0;

        foreach ($this->nodes as $node) {
            $this->total       += max(1, $node->weight);
            $this->cumulative[] = $this->total;
        }
    }
}
