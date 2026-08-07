<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;

/**
 * 负载均衡策略契约。
 *
 * @since 5.0.0
 */
interface BalancerInterface
{
    /** 策略名（round-robin / weighted / random / least-conn / hash）。 */
    public function name(): string;

    /**
     * 替换候选节点集合。
     *
     * 通常由服务发现驱动：`$balancer->setNodes($registry->nodes('order'))`。
     *
     * @param list<Node> $nodes
     */
    public function setNodes(array $nodes): static;

    /** @return list<Node> */
    public function nodes(): array;

    /** 候选节点数量。 */
    public function count(): int;

    /**
     * 选出一个节点。
     *
     * @param  string|null $key 会话/分片键，仅一致性哈希等有状态策略会用到
     * @throws \Kode\Process\Exceptions\ClusterException 无可用节点
     */
    public function select(?string $key = null): Node;

    /** 同 {@see select()}，但无可用节点时返回 null 而非抛异常。 */
    public function trySelect(?string $key = null): ?Node;
}
