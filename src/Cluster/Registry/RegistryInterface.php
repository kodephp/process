<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Registry;

use Kode\Process\Cluster\Node;

/**
 * 服务注册与发现契约。
 *
 * @since 5.0.0
 */
interface RegistryInterface
{
    /**
     * 注册（或覆盖注册）一个节点，同时写入首次心跳。
     *
     * @return Node 补齐了 registeredAt / heartbeatAt 的节点副本
     */
    public function register(Node $node): Node;

    /** 主动注销节点（优雅下线时调用，比等 TTL 过期快得多）。 */
    public function deregister(string $id, string $service = 'default'): bool;

    /**
     * 续约心跳。
     *
     * @return bool 节点已被摘除时返回 false，调用方应重新 register()
     */
    public function heartbeat(string $id, string $service = 'default'): bool;

    /**
     * 列出节点。
     *
     * @param  string|null $service     指定服务名；null 表示全部服务
     * @param  bool        $healthyOnly 仅返回 {@see \Kode\Process\Cluster\NodeStatus::Up} 的节点
     * @return list<Node>
     */
    public function nodes(?string $service = null, bool $healthyOnly = true): array;

    /** 按 ID 查找节点，不存在返回 null。 */
    public function find(string $id, string $service = 'default'): ?Node;

    /**
     * 列出当前所有服务名。
     *
     * @return list<string>
     */
    public function services(): array;

    /** 心跳有效期（秒）。 */
    public function ttl(): float;

    /**
     * 与上次快照比对，返回节点变更。
     *
     * 非阻塞，适合放进事件循环定时器里轮询；返回的三个列表分别是新增、移除、状态变化。
     *
     * @return array{added: list<Node>, removed: list<Node>, changed: list<Node>}
     */
    public function diff(?string $service = null): array;
}
