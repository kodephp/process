<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

/**
 * 集群节点健康状态。
 *
 * 状态由心跳新鲜度推导，不需要业务显式维护：
 *
 * ```
 * 心跳年龄 <= ttl            → Up       正常参与负载
 * ttl < 心跳年龄 <= ttl * 2  → Suspect  疑似故障，暂停接新流量但不摘除
 * 心跳年龄 > ttl * 2         → Down     判定下线，从注册表摘除
 * ```
 *
 * 设 Suspect 这一档是为了避免网络抖动导致的「摘除—重注册」抖动风暴。
 *
 * @since 5.0.0
 */
enum NodeStatus: string
{
    /** 心跳正常，可接收流量。 */
    case Up = 'up';

    /** 心跳超期但未达摘除阈值，暂停派发新流量。 */
    case Suspect = 'suspect';

    /** 判定下线。 */
    case Down = 'down';

    /** 是否可以接收新流量。 */
    public function isHealthy(): bool
    {
        return $this === self::Up;
    }

    /** 是否仍存在于集群视图中（Suspect 仍在，只是不派新流量）。 */
    public function isAlive(): bool
    {
        return $this !== self::Down;
    }

    /** 中文描述。 */
    public function label(): string
    {
        return match ($this) {
            self::Up      => '在线',
            self::Suspect => '疑似故障',
            self::Down    => '已下线',
        };
    }

    /**
     * 依据心跳年龄与 TTL 推导状态。
     *
     * @param float $ageSeconds 距上次心跳的秒数
     * @param float $ttlSeconds 心跳有效期
     */
    public static function fromHeartbeatAge(float $ageSeconds, float $ttlSeconds): self
    {
        if ($ageSeconds <= $ttlSeconds) {
            return self::Up;
        }

        return $ageSeconds <= $ttlSeconds * 2 ? self::Suspect : self::Down;
    }
}
