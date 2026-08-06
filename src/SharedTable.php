<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Exceptions\GlobalDataException;
use Kode\Process\GlobalData\ApcuTable;
use Kode\Process\GlobalData\SwooleTable;
use Kode\Process\GlobalData\SharedMemoryTable;
use Kode\Process\GlobalData\TableInterface;
use Kode\Process\GlobalData\WorkermanTable;

/**
 * 同主机多进程共享数据表（本地表门面）
 *
 * 与「跨主机网络 GlobalData」({@see \Kode\Process\GlobalData\Client} /
 * {@see \Kode\Process\GlobalData\Server}) 是两套**独立**能力：
 * 本门面只负责**同主机、fork 出来的多进程**之间的共享内存数据，
 * 不依赖网络、不依赖任何第三方服务。
 *
 * 一套调用语义 ({@see TableInterface})，多后端可互换，按「已装什么用什么」自动挑选：
 *
 * | 后端        | 依赖                                  | 何时被选中                  |
 * |-------------|---------------------------------------|-----------------------------|
 * | swoole      | ext-swoole（已装才用）                  | auto() 首选，最快           |
 * | apcu        | ext-apcu（已装才用）                    | 次选，运行期任意时刻可用    |
 * | workerman   | Workerman\Table（旧版 v3 + ext-swoole） | 仅当该类存在时             |
 * | sysvshm     | ext-sysvshm + ext-sysvsem（PHP 内置）   | 兜底，零安装、跨进程完整    |
 *
 * 设计原则：**零安装优先**。本库从不要求安装 Swoole / APCu / Workerman，
 * 只在它们恰好存在时顺带用上；否则一律走 PHP 内置 System V 共享内存，开箱即用。
 *
 * 如果你只想用「同主机的多进程共享数据」，直接用本门面即可，不必经过 GlobalData。
 */
final class SharedTable
{
    public const string BACKEND_SWOOLE = 'swoole';
    public const string BACKEND_APCU = 'apcu';
    public const string BACKEND_WORKERMAN = 'workerman';
    public const string BACKEND_SHM = 'sysvshm';

    /** 优先级从高到低 */
    private const array PRIORITY = [
        self::BACKEND_SWOOLE,
        self::BACKEND_APCU,
        self::BACKEND_WORKERMAN,
        self::BACKEND_SHM,
    ];

    private static ?TableInterface $default = null;

    /**
     * 自动选择当前环境下可用的最快后端。
     *
     * @param int $key  共享内存键（仅共享内存后端使用）
     * @param int $size 容量：共享内存后端为字节数，Swoole/Workerman 为行数上限
     */
    public static function auto(int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        foreach (self::PRIORITY as $backend) {
            if (self::supports($backend)) {
                return self::make($backend, $key, $size);
            }
        }

        throw GlobalDataException::unsupported('sysvshm/sysvsem');
    }

    /**
     * 进程内共享的默认表（首次调用时按 {@see self::auto()} 创建）。
     */
    public static function default(int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        return self::$default ??= self::auto($key, $size);
    }

    /**
     * 覆盖默认表（便于测试或显式指定后端）。
     */
    public static function useTable(TableInterface $table): TableInterface
    {
        return self::$default = $table;
    }

    /**
     * 释放默认表（fork 后子进程重建、或测试清理时调用）。
     */
    public static function reset(): void
    {
        self::$default?->close();
        self::$default = null;
    }

    /**
     * 按名字构建指定后端。
     */
    public static function make(string $backend, int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        return match ($backend) {
            self::BACKEND_SWOOLE => new SwooleTable($size > 1_000_000 ? 65536 : max(1024, $size)),
            self::BACKEND_APCU => new ApcuTable('kode' . dechex($key)),
            self::BACKEND_WORKERMAN => new WorkermanTable($size > 1_000_000 ? 65536 : max(1024, $size)),
            self::BACKEND_SHM => new SharedMemoryTable($key, $size),
            default => throw new GlobalDataException("未知的共享表后端: {$backend}", context: [
                'backend' => $backend,
                'available' => self::available(),
            ]),
        };
    }

    /**
     * 零安装的共享内存表（同主机多进程，行为最稳定）。
     */
    public static function table(int $key, int $size = 4 * 1024 * 1024): SharedMemoryTable
    {
        return new SharedMemoryTable($key, $size);
    }

    /**
     * 由文件路径派生键的共享内存表，同主机各进程传相同路径即可共享。
     */
    public static function open(string $path, string $project = 'g', int $size = 4 * 1024 * 1024): SharedMemoryTable
    {
        return SharedMemoryTable::open($path, $project, $size);
    }

    /**
     * 某后端在当前环境是否可用。
     */
    public static function supports(string $backend): bool
    {
        return match ($backend) {
            self::BACKEND_SWOOLE => SwooleTable::isSupported(),
            self::BACKEND_APCU => ApcuTable::isSupported(),
            self::BACKEND_WORKERMAN => WorkermanTable::isSupported(),
            self::BACKEND_SHM => SharedMemoryTable::isSupported(),
            default => false,
        };
    }

    /**
     * 当前环境可用的后端列表（按优先级排序）。
     *
     * @return string[]
     */
    public static function available(): array
    {
        return array_values(array_filter(self::PRIORITY, self::supports(...)));
    }

    /**
     * auto() 会选中的后端名；一个都没有时返回 null。
     */
    public static function preferred(): ?string
    {
        return self::available()[0] ?? null;
    }

    /**
     * 后端可用性明细，便于启动自检 / 诊断输出。
     *
     * @return array<string, array{available: bool, requires: string, note: string}>
     */
    public static function diagnose(): array
    {
        return [
            self::BACKEND_SWOOLE => [
                'available' => self::supports(self::BACKEND_SWOOLE),
                'requires' => 'ext-swoole',
                'note' => '已装 swoole 时自动启用；须在 fork 前创建',
            ],
            self::BACKEND_APCU => [
                'available' => self::supports(self::BACKEND_APCU),
                'requires' => 'ext-apcu（CLI 需 apc.enable_cli=1）',
                'note' => '已装 apcu 时自动启用；运行期任意时刻可用',
            ],
            self::BACKEND_WORKERMAN => [
                'available' => self::supports(self::BACKEND_WORKERMAN),
                'requires' => 'Workerman\\Table（仅旧版 Workerman v3 + ext-swoole）',
                'note' => 'Workerman\\Table 即 Swoole\\Table 的封装，需 ext-swoole',
            ],
            self::BACKEND_SHM => [
                'available' => self::supports(self::BACKEND_SHM),
                'requires' => 'ext-sysvshm + ext-sysvsem（PHP 内置）',
                'note' => '零安装兜底，跨进程语义完整',
            ],
        ];
    }
}
