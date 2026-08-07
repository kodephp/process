<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;

/**
 * 最小连接数：优先派给当前在途请求最少的节点。
 *
 * 适合请求耗时差异大的场景（有的接口 1ms、有的 3s）——轮询会让慢请求堆在同一台机器上，
 * 最小连接则会自动绕开正在被拖慢的节点。
 *
 * 实际比较的是**加权在途数** `inflight / weight`，因此高权重机器允许承担更多并发。
 *
 * 使用时必须成对调用 {@see acquire()} / {@see release()}，否则计数会泄漏：
 *
 * ```php
 * $node = $balancer->select();
 * $balancer->acquire($node);
 * try {
 *     $response = $client->call($node->address(), $payload);
 * } finally {
 *     $balancer->release($node);
 * }
 * ```
 *
 * 或者用托管写法，异常也不会漏计数：
 *
 * ```php
 * $response = $balancer->run(fn (Node $node) => $client->call($node->address(), $payload));
 * ```
 *
 * 计数是**进程内**的。要做全集群级的最小连接，请把各节点的在途数上报到
 * Node::$meta（如 `inflight`），本策略会优先采信它。
 *
 * @since 5.0.0
 */
final class LeastConnectionsBalancer extends AbstractBalancer
{
    /**
     * 各节点在途请求数，键为节点 ID。
     *
     * @var array<string, int>
     */
    private array $inflight = [];

    public function name(): string
    {
        return 'least-conn';
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

        $best      = null;
        $bestScore = INF;

        foreach ($this->nodes as $node) {
            $score = $this->loadOf($node) / max(1, $node->weight);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best      = $node;
            }
        }

        return $best;
    }

    /**
     * 节点当前负载。
     *
     * 优先采信节点自己上报的 `meta['inflight']`（全集群视角），
     * 没有上报时退回本进程的计数。
     */
    private function loadOf(Node $node): float
    {
        $reported = $node->meta('inflight');

        if (is_int($reported) || is_float($reported)) {
            return (float) $reported;
        }

        return (float) ($this->inflight[$node->id] ?? 0);
    }

    /** 标记一次请求开始。 */
    public function acquire(Node $node): void
    {
        $this->inflight[$node->id] = ($this->inflight[$node->id] ?? 0) + 1;
    }

    /** 标记一次请求结束。 */
    public function release(Node $node): void
    {
        $left = ($this->inflight[$node->id] ?? 0) - 1;

        if ($left <= 0) {
            unset($this->inflight[$node->id]);
            return;
        }

        $this->inflight[$node->id] = $left;
    }

    /**
     * 选节点 → 计数 → 执行 → 保证释放。
     *
     * @template T
     * @param  callable(Node): T $fn
     * @return T
     * @throws \Kode\Process\Exceptions\ClusterException 无可用节点
     */
    public function run(callable $fn, ?string $key = null): mixed
    {
        $node = $this->select($key);
        $this->acquire($node);

        try {
            return $fn($node);
        } finally {
            $this->release($node);
        }
    }

    /**
     * 当前在途计数快照，键为节点 ID。
     *
     * 空闲节点显式记 0（而非省略），这样直接喂给监控就是一张完整的负载表。
     *
     * @return array<string, int>
     */
    public function inflight(): array
    {
        $snapshot = [];

        foreach ($this->nodes as $node) {
            $snapshot[$node->id] = $this->inflight[$node->id] ?? 0;
        }

        return $snapshot;
    }

    protected function onNodesChanged(): void
    {
        // 摘除的节点不再保留计数，避免它重新上线时被历史包袱压住
        $alive          = array_column($this->nodes, 'id');
        $this->inflight = array_intersect_key($this->inflight, array_flip($alive));
    }
}
