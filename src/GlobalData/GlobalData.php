<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\Exceptions\GlobalDataException;

/**
 * GlobalData 统一入口
 *
 * 一套调用语义（{@see TableInterface}），三种可互换后端，按「已装什么用什么」自动挑选：
 *
 * | 后端                      | 依赖                         | 何时被选中          |
 * |---------------------------|------------------------------|---------------------|
 * | {@see SwooleTable}        | ext-swoole（已装才用）        | auto() 首选         |
 * | {@see ApcuTable}          | ext-apcu（已装才用）          | 次选                |
 * | {@see SharedMemoryTable}  | ext-sysvshm + ext-sysvsem（PHP 内置） | 兜底，零安装 |
 *
 * 设计原则是**零安装优先**：本库从不要求你去装 Swoole 或 APCu，
 * 只在它们恰好存在时顺带用上；否则一律走 PHP 内置 System V 共享内存，
 * 开箱即用、行为一致。跨主机共享请用 {@see Client} / {@see Server} 网络模型。
 */
final class GlobalData
{
    public const string BACKEND_SWOOLE = 'swoole';
    public const string BACKEND_APCU = 'apcu';
    public const string BACKEND_SHM = 'sysvshm';

    /** 优先级从高到低 */
    private const array PRIORITY = [
        self::BACKEND_SWOOLE,
        self::BACKEND_APCU,
        self::BACKEND_SHM,
    ];

    private static ?TableInterface $default = null;

    /**
     * 自动选择当前环境下可用的最快后端。
     *
     * @param int $key  共享内存键（仅共享内存后端使用）
     * @param int $size 容量：共享内存后端为字节数，Swoole 为行数上限
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
            self::BACKEND_SHM => new SharedMemoryTable($key, $size),
            default => throw new GlobalDataException("未知的 GlobalData 后端: {$backend}", context: [
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
     * 跨主机网络客户端。
     */
    public static function client(string $address = '127.0.0.1:2207'): Client
    {
        return new Client($address);
    }

    /**
     * 某后端在当前环境是否可用。
     */
    public static function supports(string $backend): bool
    {
        return match ($backend) {
            self::BACKEND_SWOOLE => SwooleTable::isSupported(),
            self::BACKEND_APCU => ApcuTable::isSupported(),
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
                'note' => '已装 swoole 时自动启用；必须在 fork 之前创建表',
            ],
            self::BACKEND_APCU => [
                'available' => self::supports(self::BACKEND_APCU),
                'requires' => 'ext-apcu（CLI 需 apc.enable_cli=1）',
                'note' => '已装 apcu 时自动启用；可在运行期任意时刻创建',
            ],
            self::BACKEND_SHM => [
                'available' => self::supports(self::BACKEND_SHM),
                'requires' => 'ext-sysvshm + ext-sysvsem（PHP 内置）',
                'note' => '零安装兜底后端，跨进程语义完整',
            ],
        ];
    }
}
