<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;

/**
 * 轮询：依次派发，最简单也最公平（前提是节点性能一致）。
 *
 * 节点性能不一致时请改用 {@see WeightedRoundRobinBalancer}。
 *
 * @since 5.0.0
 */
final class RoundRobinBalancer extends AbstractBalancer
{
    private int $cursor = 0;

    public function name(): string
    {
        return 'round-robin';
    }

    public function trySelect(?string $key = null): ?Node
    {
        $count = count($this->nodes);
        if ($count === 0) {
            return null;
        }

        $node = $this->nodes[$this->cursor % $count];

        // 游标只增不回绕到负数：取模前先约束，避免长期运行溢出
        $this->cursor = ($this->cursor + 1) % $count;

        return $node;
    }

    protected function onNodesChanged(): void
    {
        $this->cursor = 0;
    }
}
