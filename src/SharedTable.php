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
 * ## 本库定位：Swoole / Workerman 之外的「第三选择」
 *
 * Swoole、Workerman 运行多年、生态成熟稳定。若你的应用**已经**基于它们，
 * 应优先使用它们自带的共享表（Swoole\Table / Workerman\Table）——
 * 不必引入本库的维护负担。本库内置共享表的真正价值，是**在你不打算引入
 * Swoole / Workerman 时**提供一套**零安装、零依赖**的兜底：
 *
 * | 后端      | 依赖                                | 角色                       |
 * |-----------|-------------------------------------|----------------------------|
 * | apcu      | ext-apcu（已装才用）                | 零安装兜底之一，运行期可用 |
 * | sysvshm   | ext-sysvshm + ext-sysvsem（PHP 内置）| 零安装兜底之一，跨进程完整 |
 *
 * {@see self::auto()} 默认只在这两类零安装后端间择优——这才是本库「自行维护」的部分。
 *
 * ## 与 Swoole / Workerman 的兼容
 *
 * 当你的应用**已经**跑在 Swoole / Workerman 之上，可显式调用
 * {@see self::make('swoole')} / {@see self::make('workerman')}，
 * 让同一套 {@see TableInterface} 语义直接复用它们的共享表，做到「不引入第二个依赖」的兼容共存：
 *
 * | 后端        | 依赖                                  | 角色                |
 * |-------------|---------------------------------------|---------------------|
 * | swoole      | ext-swoole                            | 兼容适配器（可选）  |
 * | workerman   | Workerman\Table（旧版 v3 + ext-swoole）| 兼容适配器（可选）  |
 *
 * 注意：Swoole\Table / Workerman\Table 须**在 fork 之前**创建，否则子进程看不到数据。
 *
 * ## 网络 GlobalData
 *
 * 跨主机的「网络 GlobalData」概念与名称来自 Workerman 的 GlobalData 组件；
 * 本库的 {@see \Kode\Process\GlobalData\Server} / {@see \Kode\Process\GlobalData\Client}
 * 是同一思路的兼容实现（独立进程 + TCP，可跨主机），与本地表正交。
 */
final class SharedTable
{
    public const string BACKEND_SWOOLE = 'swoole';
    public const string BACKEND_APCU = 'apcu';
    public const string BACKEND_WORKERMAN = 'workerman';
    public const string BACKEND_SHM = 'sysvshm';

    /** 本库自行维护、零安装的兜底后端（无 Swoole / Workerman 时的「第三选择」）。auto() 只在这里择优。 */
    private const array ZERO_DEP = [
        self::BACKEND_APCU,
        self::BACKEND_SHM,
    ];

    /** 兼容适配器：应用已运行在 Swoole / Workerman 上时，可让 TableInterface 复用其共享表（不进 auto() 默认链）。 */
    private const array COMPAT = [
        self::BACKEND_SWOOLE,
        self::BACKEND_WORKERMAN,
    ];

    private static ?TableInterface $default = null;

    /**
     * 自动选择当前环境下可用的零安装兜底后端（apcu → sysvshm）。
     *
     * 这是本库「自行维护」的第三选择，只依赖 PHP 内置或常见扩展，
     * 不把 Swoole / Workerman 纳入默认链。若应用已运行在 Swoole / Workerman 之上，
     * 请改用 {@see self::make('swoole')} / {@see self::make('workerman')} 复用其共享表。
     *
     * @param int $key  共享内存键（仅共享内存后端使用）
     * @param int $size 容量：共享内存后端为字节数
     */
    public static function auto(int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): TableInterface
    {
        foreach (self::ZERO_DEP as $backend) {
            if (self::supports($backend)) {
                return self::make($backend, $key, $size);
            }
        }

        throw GlobalDataException::unsupported('apcu/sysvshm');
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
     * 当前环境可用的后端列表（零安装兜底在前，兼容适配器在后）。
     *
     * @return string[]
     */
    public static function available(): array
    {
        return array_values(array_filter([...self::ZERO_DEP, ...self::COMPAT], self::supports(...)));
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
                'note' => '兼容适配器：应用已用 swoole 时 make(\'swoole\') 复用其表；须在 fork 前创建',
            ],
            self::BACKEND_APCU => [
                'available' => self::supports(self::BACKEND_APCU),
                'requires' => 'ext-apcu（CLI 需 apc.enable_cli=1）',
                'note' => '零安装兜底之一，auto() 默认启用；运行期任意时刻可用',
            ],
            self::BACKEND_WORKERMAN => [
                'available' => self::supports(self::BACKEND_WORKERMAN),
                'requires' => 'Workerman\\Table（仅旧版 Workerman v3 + ext-swoole）',
                'note' => '兼容适配器：Workerman\\Table 即 Swoole\\Table 封装（旧版 v3 + ext-swoole）',
            ],
            self::BACKEND_SHM => [
                'available' => self::supports(self::BACKEND_SHM),
                'requires' => 'ext-sysvshm + ext-sysvsem（PHP 内置）',
                'note' => '零安装兜底之一，auto() 默认启用，跨进程语义完整',
            ],
        ];
    }
}
