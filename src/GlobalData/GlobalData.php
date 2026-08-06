<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\SharedTable;

/**
 * GlobalData 统一入口
 *
 * 两类独立能力汇聚于此：
 *
 *  1) **本地多进程共享表** —— 全部委托给 {@see SharedTable}
 *     （Swoole Table / APCu / 共享内存 / Workerman 自动择优，零安装优先）。
 *     如果你只想要「同主机的多进程共享数据」，直接用 {@see SharedTable} 即可，
 *     不必经过本类。
 *
 *  2) **跨主机网络共享** —— {@see self::client()} 与 {@see Server}
 *     （独立进程 + TCP，可跨主机；与本库本地表是正交的两条路）。
 *
 * 设计原则：**零安装优先**。本库从不要求安装 Swoole / APCu / Workerman，
 * 只在它们恰好存在时顺带用上；否则一律走 PHP 内置 System V 共享内存。
 */
final class GlobalData
{
    public const string BACKEND_SWOOLE = SharedTable::BACKEND_SWOOLE;
    public const string BACKEND_APCU = SharedTable::BACKEND_APCU;
    public const string BACKEND_SHM = SharedTable::BACKEND_SHM;
    public const string BACKEND_WORKERMAN = SharedTable::BACKEND_WORKERMAN;

    public static function auto(int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        return SharedTable::auto($key, $size);
    }

    public static function default(int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        return SharedTable::default($key, $size);
    }

    public static function useTable(TableInterface $table): TableInterface
    {
        return SharedTable::useTable($table);
    }

    public static function reset(): void
    {
        SharedTable::reset();
    }

    public static function make(string $backend, int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        return SharedTable::make($backend, $key, $size);
    }

    /**
     * 零安装的共享内存表（同主机多进程）。
     */
    public static function table(int $key, int $size = 4 * 1024 * 1024): SharedMemoryTable
    {
        return SharedTable::table($key, $size);
    }

    /**
     * 由文件路径派生键的共享内存表。
     */
    public static function open(string $path, string $project = 'g', int $size = 4 * 1024 * 1024): SharedMemoryTable
    {
        return SharedTable::open($path, $project, $size);
    }

    /**
     * 跨主机网络客户端。
     */
    public static function client(string $address = '127.0.0.1:2207'): Client
    {
        return new Client($address);
    }

    public static function supports(string $backend): bool
    {
        return SharedTable::supports($backend);
    }

    /**
     * @return string[]
     */
    public static function available(): array
    {
        return SharedTable::available();
    }

    public static function preferred(): ?string
    {
        return SharedTable::preferred();
    }

    /**
     * @return array<string, array{available: bool, requires: string, note: string}>
     */
    public static function diagnose(): array
    {
        return SharedTable::diagnose();
    }
}
