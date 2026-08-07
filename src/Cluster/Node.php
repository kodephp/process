<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

use JsonSerializable;

/**
 * 集群节点描述（不可变值对象）。
 *
 * ```php
 * $node = new Node(
 *     id:      'order-1',
 *     service: 'order',
 *     host:    '10.0.0.11',
 *     port:    9501,
 *     weight:  200,                       // 机器更强就给更高权重
 *     meta:    ['zone' => 'sh-1', 'version' => '2.3.0'],
 * );
 * ```
 *
 * @since 5.0.0
 */
final readonly class Node implements JsonSerializable
{
    /**
     * @param string               $id       集群内唯一节点 ID
     * @param string               $service  逻辑服务名（同名节点组成一个负载均衡池）
     * @param string               $host     可被其它节点访问的地址
     * @param int                  $port     服务端口
     * @param int                  $weight   负载权重，默认 100
     * @param array<string, mixed> $meta     自定义元数据（机房、版本、标签等）
     * @param NodeStatus           $status   健康状态，由注册表按心跳推导
     * @param float                $registeredAt 注册时间戳
     * @param float                $heartbeatAt  最近心跳时间戳
     */
    public function __construct(
        public string $id,
        public string $service = 'default',
        public string $host = '127.0.0.1',
        public int $port = 0,
        public int $weight = 100,
        public array $meta = [],
        public NodeStatus $status = NodeStatus::Up,
        public float $registeredAt = 0.0,
        public float $heartbeatAt = 0.0,
    ) {
    }

    /** `host:port` 形式的地址。 */
    public function address(): string
    {
        return $this->port > 0 ? $this->host . ':' . $this->port : $this->host;
    }

    /** 距上次心跳的秒数。 */
    public function heartbeatAge(?float $now = null): float
    {
        if ($this->heartbeatAt <= 0.0) {
            return INF;
        }

        return max(0.0, ($now ?? microtime(true)) - $this->heartbeatAt);
    }

    /** 读取元数据项。 */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function withStatus(NodeStatus $status): self
    {
        return new self(
            $this->id,
            $this->service,
            $this->host,
            $this->port,
            $this->weight,
            $this->meta,
            $status,
            $this->registeredAt,
            $this->heartbeatAt,
        );
    }

    /**
     * 记录一次心跳。
     *
     * 状态一并复位为 Up——收到心跳本身就是存活证据，
     * 留着 Down 会造出「刚上报过心跳却是死的」这种自相矛盾的值。
     */
    public function withHeartbeat(float $at): self
    {
        return new self(
            $this->id,
            $this->service,
            $this->host,
            $this->port,
            $this->weight,
            $this->meta,
            NodeStatus::Up,
            $this->registeredAt,
            $at,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self(
            $this->id,
            $this->service,
            $this->host,
            $this->port,
            $this->weight,
            [...$this->meta, ...$meta],
            $this->status,
            $this->registeredAt,
            $this->heartbeatAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'service'       => $this->service,
            'host'          => $this->host,
            'port'          => $this->port,
            'weight'        => $this->weight,
            'meta'          => $this->meta,
            'status'        => $this->status->value,
            'registered_at' => $this->registeredAt,
            'heartbeat_at'  => $this->heartbeatAt,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['service'] ?? 'default'),
            (string) ($data['host'] ?? '127.0.0.1'),
            (int) ($data['port'] ?? 0),
            (int) ($data['weight'] ?? 100),
            (array) ($data['meta'] ?? []),
            NodeStatus::tryFrom((string) ($data['status'] ?? 'up')) ?? NodeStatus::Up,
            (float) ($data['registered_at'] ?? 0.0),
            (float) ($data['heartbeat_at'] ?? 0.0),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return sprintf('%s@%s(%s)', $this->id, $this->address(), $this->status->value);
    }
}
