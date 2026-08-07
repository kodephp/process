<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

/**
 * 支持的运行时类型。
 *
 * 定位说明（依据 docs/gate-report.md 的压测判定）：
 * 本包以 Swoole / Workerman 兼容适配层为默认形态，同时内置一套**自研（Native）运行时**
 * 作为可插拔的第三种实现。Native 是纯 PHP 的 master-worker 多进程服务器
 * （基于 Reactor\SelectLoop 事件循环 + Protocol 协议系统），无需任何 PHP 扩展，
 * 可与 Swoole / Workerman 在 {@see RuntimeInterface} 下无缝切换。
 *
 * 吞吐目标：持平 Workerman（Amdahl 上限 +14.9%，详见 gate-report.md 第五节）；
 * 其差异化价值在于零扩展、功能覆盖更广、可完全掌控的进程编排。
 */
enum RuntimeType: string
{
    /** 运行在 Swoole 扩展之上（最高优先级，吞吐最佳） */
    case Swoole = 'swoole';

    /** 运行在 Workerman 之上（纯 PHP 依赖，开箱即用） */
    case Workerman = 'workerman';

    /** 自研运行时（纯 PHP master-worker，零扩展依赖，可插拔的第三种实现） */
    case Native = 'native';

    /** 人类可读名称 */
    public function label(): string
    {
        return match ($this) {
            self::Swoole    => 'Swoole',
            self::Workerman => 'Workerman',
            self::Native    => 'Native',
        };
    }

    /**
     * 自动探测时的择优权重，数值越大越优先。
     *
     * 优先复用宿主已有的成熟运行时，避免在同一应用中引入第二套 I/O 栈。
     * Native 优先级最低——仅当用户显式指定 `Kode::serve($a, [], 'native')` 时选用。
     */
    public function priority(): int
    {
        return match ($this) {
            self::Swoole    => 100,
            self::Workerman => 90,
            self::Native    => 80,
        };
    }

    /** 是否第三方运行时（需要额外安装）。Native 为本包自研实现，非外部依赖。 */
    public function isExternal(): bool
    {
        return match ($this) {
            self::Swoole, self::Workerman => true,
            self::Native                  => false,
        };
    }
}
