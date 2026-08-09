<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;
use Kode\Process\Exceptions\ClusterException;

/**
 * 负载均衡策略基类：统一节点集合管理与空集处理。
 *
 * @since 5.0.0
 */
abstract class AbstractBalancer implements BalancerInterface
{
    /** @var list<Node> */
    protected array $nodes = [];

    /**
     * @param list<Node> $nodes
     */
    public function __construct(array $nodes = [])
    {
        $this->setNodes($nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    public function setNodes(array $nodes): static
    {
        $changed     = $this->fingerprint($nodes) !== $this->fingerprint($this->nodes);
        $this->nodes = array_values($nodes);

        // 节点集合真的变了才重建内部状态，避免每次刷新都打乱轮询游标
        if ($changed) {
            $this->onNodesChanged();
        }

        return $this;
    }

    /** @return list<Node> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    public function select(?string $key = null): Node
    {
        $node = $this->trySelect($key);

        if ($node === null) {
            throw ClusterException::emptyNodeSet();
        }

        return $node;
    }

    /**
     * 节点集合指纹，用于判断是否需要重建内部状态。
     *
     * 顺序敏感——刻意不排序：哈希环、加权游标这些内部状态都是**按下标**索引到
     * $nodes 的，同一批节点换个顺序喂进来若不重建，下标就会指向另一台机器，
     * 一致性哈希会静默地把 key 路由错。
     *
     * @param list<Node> $nodes
     */
    protected function fingerprint(array $nodes): string
    {
        return implode('|', array_map(
            static fn (Node $n): string => $n->id . '@' . $n->address() . '#' . $n->weight,
            $nodes
        ));
    }

    /** 子类在节点集合变化时重建内部状态（游标、哈希环、权重表等）。 */
    protected function onNodesChanged(): void
    {
    }
}
