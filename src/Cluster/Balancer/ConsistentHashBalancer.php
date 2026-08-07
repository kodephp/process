<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Balancer;

use Kode\Process\Cluster\Node;

/**
 * 一致性哈希：同一个 key 稳定命中同一个节点。
 *
 * 用于会话保持、本地缓存分片、有状态连接路由——这些场景下「同一用户每次都落到同一台机器」
 * 比「绝对均匀」更重要。
 *
 * 相比 `hash(key) % N` 的取模分片，一致性哈希的价值在**扩缩容时**：
 *
 * ```
 * 取模分片：   节点从 4 台加到 5 台 → 约 80% 的 key 换了归属，缓存几乎全部失效
 * 一致性哈希： 节点从 4 台加到 5 台 → 约 20% 的 key 换了归属，只迁移应该迁移的那部分
 * ```
 *
 * 每个物理节点在哈希环上放置 `replicas × weight / 100` 个虚拟节点，
 * 虚拟节点越多分布越均匀（默认 160，是均匀度与内存占用的常用折中）；
 * 权重越高占据的环弧长越大，因而分到的 key 越多。
 *
 * ```php
 * $balancer = new ConsistentHashBalancer($registry->nodes('cache'));
 * $node = $balancer->select("user:{$userId}");   // 同一 userId 永远同一节点
 * ```
 *
 * @since 5.0.0
 */
final class ConsistentHashBalancer extends AbstractBalancer
{
    /**
     * 哈希环：环上位置 => 节点下标。
     *
     * @var array<int, int>
     */
    private array $ring = [];

    /**
     * 环上位置的升序列表，用于二分查找。
     *
     * @var list<int>
     */
    private array $positions = [];

    /**
     * @param list<Node> $nodes
     * @param int        $replicas 每 100 权重对应的虚拟节点数
     */
    public function __construct(array $nodes = [], private readonly int $replicas = 160)
    {
        parent::__construct($nodes);
    }

    public function name(): string
    {
        return 'hash';
    }

    /** 环上虚拟节点总数。 */
    public function ringSize(): int
    {
        return count($this->positions);
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

        // 没给 key 就退化成随机，避免所有请求都压在环上第一个节点
        if ($key === null || $key === '') {
            return $this->nodes[random_int(0, $count - 1)];
        }

        $target = $this->hash($key);
        $index  = $this->searchRing($target);

        return $this->nodes[$this->ring[$this->positions[$index]]];
    }

    /**
     * 在环上顺时针找到第一个 >= $target 的位置；越过末尾则回绕到起点。
     */
    private function searchRing(int $target): int
    {
        $lo = 0;
        $hi = count($this->positions) - 1;

        if ($target > $this->positions[$hi]) {
            return 0;
        }

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->positions[$mid] < $target) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * 32 位无符号哈希。
     *
     * 取 md5 前 8 个十六进制字符，比 crc32 分布更均匀，代价是慢一点——
     * 建环只在节点变化时发生，查询路径上只做一次，这点开销可以接受。
     */
    private function hash(string $value): int
    {
        return (int) hexdec(substr(md5($value), 0, 8));
    }

    /**
     * 查询某个 key 会落到哪个节点（不改变任何状态，便于做分片规划与验证）。
     */
    public function lookup(string $key): ?Node
    {
        return $this->trySelect($key);
    }

    protected function onNodesChanged(): void
    {
        $this->ring      = [];
        $this->positions = [];

        foreach ($this->nodes as $i => $node) {
            $virtual = max(1, (int) round($this->replicas * max(1, $node->weight) / 100));

            for ($v = 0; $v < $virtual; $v++) {
                // 用 id + 地址做种子：同名节点换了地址应当重新分布
                $this->ring[$this->hash($node->id . '@' . $node->address() . '#' . $v)] = $i;
            }
        }

        $this->positions = array_keys($this->ring);
        sort($this->positions);
    }
}
