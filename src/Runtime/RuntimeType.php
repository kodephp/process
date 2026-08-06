<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

/**
 * 支持的运行时类型。
 *
 * 定位说明（依据 docs/gate-report.md 的压测判定）：
 * 本包**不自带服务器实现**，只做 Swoole / Workerman 的兼容适配层。
 * 两者都是成熟、久经生产验证的运行时，性能处于同一量级；应用面向
 * {@see RuntimeInterface} 编程即可在两者间无缝切换，不引入相互竞争的 I/O 栈。
 */
enum RuntimeType: string
{
    /** 运行在 Swoole 扩展之上（最高优先级，吞吐最佳） */
    case Swoole = 'swoole';

    /** 运行在 Workerman 之上（纯 PHP 依赖，开箱即用） */
    case Workerman = 'workerman';

    /** 人类可读名称 */
    public function label(): string
    {
        return match ($this) {
            self::Swoole    => 'Swoole',
            self::Workerman => 'Workerman',
        };
    }

    /**
     * 自动探测时的择优权重，数值越大越优先。
     *
     * 优先复用宿主已有的成熟运行时，避免在同一应用中引入第二套 I/O 栈。
     */
    public function priority(): int
    {
        return match ($this) {
            self::Swoole    => 100,
            self::Workerman => 90,
        };
    }

    /** 是否第三方运行时（需要额外安装） */
    public function isExternal(): bool
    {
        return true;
    }
}
