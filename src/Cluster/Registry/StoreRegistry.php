<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Registry;

use Kode\Process\Cluster\Node;
use Kode\Process\Cluster\NodeStatus;
use Kode\Process\Cluster\Store\StoreInterface;

/**
 * 构建在 {@see StoreInterface} 之上的通用服务注册表。
 *
 * 一套实现同时适配 Redis / GlobalData / File 三种后端——换后端不用换注册表。
 *
 * 存活判定完全由心跳驱动：
 *
 * ```
 * register()  写入节点 + 首次心跳，存储 TTL 设为 ttl * 2
 * heartbeat() 刷新心跳时间戳与 TTL（周期建议 ttl / 3）
 * nodes()     读取时按心跳年龄推导 Up / Suspect，Down 的直接不返回
 * ```
 *
 * 进程崩溃时无需任何人清理：TTL 到期后存储层自动摘除该节点。
 *
 * ```php
 * $registry = new StoreRegistry($store, ttl: 10.0);
 * $registry->register(new Node('order-1', 'order', '10.0.0.11', 9501));
 *
 * Kode::every(3.0, fn () => $registry->heartbeat('order-1', 'order'));
 * ```
 *
 * @since 5.0.0
 */
final class StoreRegistry implements RegistryInterface
{
    /** 注册表键前缀。 */
    private const PREFIX = 'reg/';

    /**
     * 上次 diff() 的快照。
     *
     * @var array<string, array<string, Node>>
     */
    private array $snapshots = [];

    /**
     * @param float $ttl 心跳有效期（秒）。超过 ttl 判 Suspect，超过 ttl*2 判 Down 并摘除。
     */
    public function __construct(
        private readonly StoreInterface $store,
        private readonly float $ttl = 15.0,
    ) {
    }

    public function ttl(): float
    {
        return $this->ttl;
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    private function key(string $service, string $id): string
    {
        return self::PREFIX . $service . '/' . $id;
    }

    /** 存储层 TTL 取心跳 TTL 的两倍——留出 Suspect 观察窗口再摘除。 */
    private function storeTtlMs(): int
    {
        return (int) round($this->ttl * 2 * 1000);
    }

    public function register(Node $node): Node
    {
        $now = microtime(true);

        $stored = new Node(
            $node->id,
            $node->service,
            $node->host,
            $node->port,
            $node->weight,
            $node->meta,
            NodeStatus::Up,
            $node->registeredAt > 0 ? $node->registeredAt : $now,
            $now,
        );

        $this->store->set($this->key($stored->service, $stored->id), $stored->toArray(), $this->storeTtlMs());

        return $stored;
    }

    public function deregister(string $id, string $service = 'default'): bool
    {
        return $this->store->delete($this->key($service, $id));
    }

    public function heartbeat(string $id, string $service = 'default'): bool
    {
        $key = $this->key($service, $id);
        $raw = $this->store->get($key);

        // 节点已被 TTL 摘除（本机长时间卡顿/网络分区），交回调用方重新注册
        if (!is_array($raw)) {
            return false;
        }

        $raw['heartbeat_at'] = microtime(true);
        $raw['status']       = NodeStatus::Up->value;

        return $this->store->set($key, $raw, $this->storeTtlMs());
    }

    public function nodes(?string $service = null, bool $healthyOnly = true): array
    {
        $prefix = self::PREFIX . ($service !== null ? $service . '/' : '');
        $keys   = $this->store->keys($prefix);

        if ($keys === []) {
            return [];
        }

        $now   = microtime(true);
        $nodes = [];

        foreach ($this->store->mget($keys) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $node   = Node::fromArray($raw);
            $status = NodeStatus::fromHeartbeatAge($node->heartbeatAge($now), $this->ttl);

            // Down 一律不出现在集群视图里
            if ($status === NodeStatus::Down) {
                continue;
            }
            if ($healthyOnly && !$status->isHealthy()) {
                continue;
            }

            $nodes[] = $node->withStatus($status);
        }

        // 稳定排序：保证各节点看到一致的顺序，轮询/一致性哈希才可复现
        usort($nodes, static fn (Node $a, Node $b): int => [$a->service, $a->id] <=> [$b->service, $b->id]);

        return $nodes;
    }

    public function find(string $id, string $service = 'default'): ?Node
    {
        $raw = $this->store->get($this->key($service, $id));
        if (!is_array($raw)) {
            return null;
        }

        $node   = Node::fromArray($raw);
        $status = NodeStatus::fromHeartbeatAge($node->heartbeatAge(), $this->ttl);

        return $status === NodeStatus::Down ? null : $node->withStatus($status);
    }

    public function services(): array
    {
        $services = [];

        foreach ($this->store->keys(self::PREFIX) as $key) {
            $rest = substr($key, strlen(self::PREFIX));
            $pos  = strpos($rest, '/');
            if ($pos !== false) {
                $services[substr($rest, 0, $pos)] = true;
            }
        }

        $names = array_keys($services);
        sort($names);

        return $names;
    }

    public function diff(?string $service = null): array
    {
        $bucket  = $service ?? '*';
        $current = [];

        foreach ($this->nodes($service, healthyOnly: false) as $node) {
            $current[$node->service . '/' . $node->id] = $node;
        }

        $previous = $this->snapshots[$bucket] ?? [];

        $added   = [];
        $removed = [];
        $changed = [];

        foreach ($current as $key => $node) {
            if (!isset($previous[$key])) {
                $added[] = $node;
            } elseif (self::topology($previous[$key]) !== self::topology($node)) {
                $changed[] = $node;
            }
        }

        foreach ($previous as $key => $node) {
            if (!isset($current[$key])) {
                $removed[] = $node;
            }
        }

        $this->snapshots[$bucket] = $current;

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * 节点的拓扑指纹——决定 diff() 是否报 changed。
     *
     * 刻意排除心跳时间戳：心跳每几秒刷新一次，若纳入比较则每次 diff 都报「变了」，
     * 下游的负载均衡器会被无谓地反复重建。只有地址、权重、状态、元数据这些
     * 真正影响「往哪派、派多少」的字段变化才值得通知。
     */
    private static function topology(Node $node): string
    {
        return $node->address()
            . '|' . $node->weight
            . '|' . $node->status->value
            . '|' . json_encode($node->meta);
    }

    /** 丢弃 diff 快照，下次 diff() 会把现存节点全部报成 added。 */
    public function resetSnapshot(?string $service = null): void
    {
        if ($service === null) {
            $this->snapshots = [];
            return;
        }

        unset($this->snapshots[$service]);
    }
}
