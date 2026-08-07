<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

use Kode\Process\Cluster\Store\StoreInterface;
use Kode\Process\Exceptions\ClusterException;

/**
 * 分布式唯一 ID 生成器（Snowflake）。
 *
 * 生成 64 位正整数，全集群唯一且**整体趋势递增**——直接做数据库主键，
 * 既不会像 UUID 那样打乱 B+ 树插入局部性，也不需要中心化发号服务。
 *
 * 位布局：
 *
 * ```
 *  1        41              10          12
 * ┌─┬───────────────────┬──────────┬────────────┐
 * │0│    毫秒时间戳      │ 机器 ID  │   序列号    │
 * └─┴───────────────────┴──────────┴────────────┘
 *   符号位恒 0    可用 69 年   0~1023    每毫秒 4096 个
 * ```
 *
 * 理论上限：单机每秒 409.6 万个，1024 台机器共约 42 亿/秒。
 *
 * ```php
 * $snowflake = Cluster::snowflake();     // 机器 ID 自动从注册中心分配
 * $orderId   = $snowflake->next();       // 1234567890123456789
 *
 * // 反解排查
 * ['datetime' => $t, 'worker_id' => $w] = Snowflake::parse($orderId);
 * ```
 *
 * **机器 ID 必须集群内唯一**，否则同一毫秒可能撞号。用 {@see allocateWorkerId()}
 * 从协调存储自动领取，比手工配置可靠。
 *
 * @since 5.0.0
 */
final class Snowflake
{
    /** 默认纪元：2025-01-01 00:00:00 UTC（毫秒）。 */
    public const DEFAULT_EPOCH = 1_735_689_600_000;

    public const WORKER_ID_BITS = 10;
    public const SEQUENCE_BITS  = 12;

    public const MAX_WORKER_ID = (1 << self::WORKER_ID_BITS) - 1;   // 1023
    public const MAX_SEQUENCE  = (1 << self::SEQUENCE_BITS) - 1;    // 4095

    private const WORKER_SHIFT    = self::SEQUENCE_BITS;                            // 12
    private const TIMESTAMP_SHIFT = self::SEQUENCE_BITS + self::WORKER_ID_BITS;     // 22

    /** 容忍的时钟回拨上限（毫秒）——在此范围内自旋等待，超出则拒绝生成。 */
    private const MAX_TOLERABLE_BACKWARD_MS = 10;

    private int $lastTimestamp = -1;

    private int $sequence = 0;

    /** 累计生成个数，用于观测。 */
    private int $generated = 0;

    /**
     * @param int $workerId 机器 ID，0 ~ 1023，必须集群内唯一
     * @param int $epoch    自定义纪元（毫秒时间戳），设得越晚可用年限越长；上线后不可再改
     */
    public function __construct(
        private readonly int $workerId = 0,
        private readonly int $epoch = self::DEFAULT_EPOCH,
    ) {
        if ($workerId < 0 || $workerId > self::MAX_WORKER_ID) {
            throw new ClusterException(
                sprintf('机器 ID 必须在 0 ~ %d 之间，收到 %d', self::MAX_WORKER_ID, $workerId)
            );
        }
    }

    public function workerId(): int
    {
        return $this->workerId;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    public function generated(): int
    {
        return $this->generated;
    }

    /**
     * 生成下一个 ID。
     *
     * @throws ClusterException 时钟回拨超过容忍上限
     */
    public function next(): int
    {
        $timestamp = $this->now();

        if ($timestamp < $this->lastTimestamp) {
            $drift = $this->lastTimestamp - $timestamp;

            // 小幅回拨（NTP 校时抖动）自旋等回来；大幅回拨说明系统时间被改过，宁可失败也不发重复号
            if ($drift > self::MAX_TOLERABLE_BACKWARD_MS) {
                throw ClusterException::clockBackwards($drift);
            }

            $timestamp = $this->waitUntil($this->lastTimestamp);
        }

        if ($timestamp === $this->lastTimestamp) {
            $this->sequence = ($this->sequence + 1) & self::MAX_SEQUENCE;

            // 本毫秒 4096 个序列号用尽，等到下一毫秒
            if ($this->sequence === 0) {
                $timestamp = $this->waitUntil($this->lastTimestamp);
            }
        } else {
            // 新的毫秒从随机起点开始，避免低并发下所有 ID 末位都是 0（分库分表取模会倾斜）
            $this->sequence = random_int(0, 1);
        }

        $this->lastTimestamp = $timestamp;
        $this->generated++;

        return (($timestamp - $this->epoch) << self::TIMESTAMP_SHIFT)
            | ($this->workerId << self::WORKER_SHIFT)
            | $this->sequence;
    }

    /**
     * 批量生成。
     *
     * @return list<int>
     */
    public function batch(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->next();
        }

        return $ids;
    }

    /** 生成 16 位十六进制字符串形式的 ID（适合放进 URL / 日志 traceId）。 */
    public function nextHex(): string
    {
        return str_pad(dechex($this->next()), 16, '0', STR_PAD_LEFT);
    }

    /**
     * 反解 ID，排查问题时定位「什么时候、哪台机器」生成的。
     *
     * @return array{id: int, timestamp: int, datetime: string, worker_id: int, sequence: int}
     */
    public static function parse(int $id, int $epoch = self::DEFAULT_EPOCH): array
    {
        $timestamp = ($id >> self::TIMESTAMP_SHIFT) + $epoch;

        return [
            'id'        => $id,
            'timestamp' => $timestamp,
            'datetime'  => date('Y-m-d H:i:s', intdiv($timestamp, 1000)) . sprintf('.%03d', $timestamp % 1000),
            'worker_id' => ($id >> self::WORKER_SHIFT) & self::MAX_WORKER_ID,
            'sequence'  => $id & self::MAX_SEQUENCE,
        ];
    }

    /**
     * 从协调存储领取一个集群内唯一的机器 ID。
     *
     * 以 `setIfAbsent` 逐个探测空位，抢到即持有。ID 带 TTL，进程崩溃后会被回收，
     * 因此持有期间需要周期调用 {@see renewWorkerId()} 续租。
     *
     * ```php
     * $workerId = Snowflake::allocateWorkerId($store, 'order');
     * Kode::every(60.0, fn () => Snowflake::renewWorkerId($store, 'order', $workerId));
     * ```
     *
     * @param  string $namespace 命名空间，不同服务各自独立分配 0~1023
     * @param  int    $ttlMs     租期，默认 5 分钟
     * @throws ClusterException 1024 个机器 ID 全部被占用
     */
    public static function allocateWorkerId(
        StoreInterface $store,
        string $namespace = 'default',
        int $ttlMs = 300_000,
        ?string $owner = null,
    ): int {
        $owner ??= gethostname() . ':' . getmypid();

        // 从随机位置开始探测，降低多节点同时启动时的抢占碰撞
        $start = random_int(0, self::MAX_WORKER_ID);

        for ($i = 0; $i <= self::MAX_WORKER_ID; $i++) {
            $candidate = ($start + $i) % (self::MAX_WORKER_ID + 1);

            if ($store->setIfAbsent(self::workerKey($namespace, $candidate), $owner, $ttlMs)) {
                return $candidate;
            }
        }

        throw new ClusterException(
            sprintf('命名空间 %s 下 1024 个机器 ID 已全部占用，无法分配', $namespace)
        );
    }

    /** 续租机器 ID；返回 false 表示租约已丢失，应重新分配。 */
    public static function renewWorkerId(
        StoreInterface $store,
        string $namespace,
        int $workerId,
        int $ttlMs = 300_000,
        ?string $owner = null,
    ): bool {
        $owner ??= gethostname() . ':' . getmypid();

        return $store->compareAndSet(self::workerKey($namespace, $workerId), $owner, $owner, $ttlMs);
    }

    /** 归还机器 ID（优雅下线）。 */
    public static function releaseWorkerId(
        StoreInterface $store,
        string $namespace,
        int $workerId,
        ?string $owner = null,
    ): bool {
        $owner ??= gethostname() . ':' . getmypid();

        return $store->compareAndDelete(self::workerKey($namespace, $workerId), $owner);
    }

    private static function workerKey(string $namespace, int $workerId): string
    {
        return 'snowflake/' . $namespace . '/' . $workerId;
    }

    /** 当前毫秒时间戳。 */
    private function now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** 自旋直到时间越过 $last 毫秒。 */
    private function waitUntil(int $last): int
    {
        $timestamp = $this->now();

        while ($timestamp <= $last) {
            usleep(100);
            $timestamp = $this->now();
        }

        return $timestamp;
    }
}
