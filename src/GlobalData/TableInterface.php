<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

/**
 * 跨进程共享数据表契约
 *
 * 所有后端（共享内存 / Swoole Table / APCu）实现同一套语义，可互换：
 *  - 值以原生 PHP 类型进出，false / null 也能正确区分「存在但为假值」与「不存在」；
 *  - TTL 为秒级惰性过期，0 表示永不过期；
 *  - increment / decrement / cas 为跨进程原子操作。
 */
interface TableInterface
{
    /**
     * 当前运行环境是否支持该后端（缺扩展时为 false）。
     */
    public static function isSupported(): bool;

    /**
     * 后端标识，如 sysvshm / swoole / apcu。
     */
    public function backend(): string;

    /**
     * 写入键值；$ttl > 0 时设置秒级生存时间。
     */
    public function set(string $key, mixed $value, int $ttl = 0): void;

    /**
     * 仅当键不存在时写入；已存在返回 false。
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * 仅当键已存在时写入；不存在返回 false。
     */
    public function replace(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * 批量写入。
     *
     * @param array<string, mixed> $items
     */
    public function setMultiple(array $items, int $ttl = 0): void;

    /**
     * 读取键值；键不存在或已过期返回 null。
     */
    public function get(string $key): mixed;

    /**
     * 批量读取，返回 键 => 值（缺失为 null）。
     *
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    /**
     * 原子自增，返回自增后的值。
     */
    public function increment(string $key, int|float $step = 1): int|float;

    /**
     * 原子自减，返回自减后的值。
     */
    public function decrement(string $key, int|float $step = 1): int|float;

    /**
     * 比较并交换：仅当当前值全等于 $oldValue 时写入 $newValue。
     */
    public function cas(string $key, mixed $oldValue, mixed $newValue): bool;

    /**
     * @return string[]
     */
    public function keys(): array;

    public function count(): int;

    /**
     * 清空所有键（表本身仍可用）。
     */
    public function clear(): void;

    /**
     * 清空并释放底层资源。
     */
    public function destroy(): void;

    /**
     * @return array<string, mixed>
     */
    public function stats(): array;

    public function close(): void;
}
