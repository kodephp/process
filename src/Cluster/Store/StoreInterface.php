<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Store;

/**
 * 集群协调存储契约。
 *
 * 分布式锁、服务注册表、选举、限流器全部构建在这一层之上——
 * 只要实现本接口的 5 个原子原语（setIfAbsent / compareAndSet / compareAndDelete /
 * increment / expire），上层所有分布式能力即可直接复用，无需为每个后端重写。
 *
 * 内置三种后端：
 *
 * | 后端         | 依赖                | 适用场景                         |
 * |--------------|---------------------|----------------------------------|
 * | `redis`      | ext-redis           | 生产多机集群（推荐）             |
 * | `globaldata` | 无（本包自带）      | 零外部依赖的小规模集群           |
 * | `file`       | 无（需共享文件系统）| 单机多实例 / NFS / 开发测试      |
 *
 * TTL 一律以**毫秒**为单位，`0` 表示永不过期。
 *
 * @since 5.0.0
 */
interface StoreInterface
{
    /** 后端是否在当前环境可用。 */
    public static function isAvailable(): bool;

    /** 后端名称（redis / globaldata / file）。 */
    public function name(): string;

    /** 读取键值，不存在返回 null。 */
    public function get(string $key): mixed;

    /**
     * 批量读取。
     *
     * @param  list<string>        $keys
     * @return array<string, mixed> 仅包含存在的键
     */
    public function mget(array $keys): array;

    /** 写入键值（覆盖）。$ttlMs 为 0 表示永久。 */
    public function set(string $key, mixed $value, int $ttlMs = 0): bool;

    /**
     * 仅当键不存在时写入（原子）。
     *
     * 这是分布式锁与选举的基石，等价于 Redis 的 `SET key val NX PX ttl`。
     */
    public function setIfAbsent(string $key, mixed $value, int $ttlMs = 0): bool;

    /** 仅当当前值等于 $expected 时才写入新值（原子 CAS）。 */
    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttlMs = 0): bool;

    /**
     * 仅当当前值等于 $expected 时才删除（原子）。
     *
     * 用于安全释放锁——避免误删他人在本节点超时后重新获得的锁。
     */
    public function compareAndDelete(string $key, mixed $expected): bool;

    /** 删除键。键不存在也返回 true。 */
    public function delete(string $key): bool;

    /** 键是否存在（且未过期）。 */
    public function exists(string $key): bool;

    /** 原子自增，返回新值。$ttlMs 仅在键首次创建时生效。 */
    public function increment(string $key, int $step = 1, int $ttlMs = 0): int;

    /** 重设存活时间（键不存在返回 false）。 */
    public function expire(string $key, int $ttlMs): bool;

    /**
     * 列出指定前缀下的所有键。
     *
     * @return list<string>
     */
    public function keys(string $prefix = ''): array;

    /** 清空本存储命名空间下的全部键（仅测试/运维使用）。 */
    public function flush(): int;

    /** 释放底层连接。 */
    public function close(): void;
}
