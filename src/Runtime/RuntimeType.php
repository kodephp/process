<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

/**
 * 支持的运行时类型。
 *
 * 本包**默认使用自研（Native）运行时**——纯 PHP 的 master-worker 多进程服务器，
 * 零扩展依赖即可运行，装了 ext-event / ext-ev 会自动下沉到 C 层事件循环。
 * 已有 Swoole / Workerman 技术栈的项目可显式接入对应运行时，
 * 三者都实现同一套 {@see RuntimeInterface}，切换底层无需改动业务代码。
 *
 * ```php
 * Kode::serve('http://0.0.0.0:8080');                 // 默认 Native
 * Kode::serve('http://0.0.0.0:8080', [], 'swoole');   // 接入 Swoole
 * Kode::serve('http://0.0.0.0:8080', [], 'workerman');// 接入 Workerman
 * ```
 */
enum RuntimeType: string
{
    /** 自研运行时（默认，纯 PHP master-worker，零扩展依赖） */
    case Native = 'native';

    /** 运行在 Swoole 扩展之上（已有 Swoole 栈时接入，具备原生协程） */
    case Swoole = 'swoole';

    /** 运行在 Workerman 之上（已有 Workerman 栈时接入） */
    case Workerman = 'workerman';

    /** 人类可读名称 */
    public function label(): string
    {
        return match ($this) {
            self::Native    => 'Native',
            self::Swoole    => 'Swoole',
            self::Workerman => 'Workerman',
        };
    }

    /**
     * 自动探测时的择优权重，数值越大越优先。
     *
     * Native 权重最高：它是本包自己的实现，行为完全可控、无第三方版本耦合，
     * 且在任何环境都可用。需要 Swoole 协程或已有 Workerman 栈时，
     * 用 `Runtime::make()` / `Runtime::auto(['swoole'])` 显式指定即可。
     */
    public function priority(): int
    {
        return match ($this) {
            self::Native    => 100,
            self::Swoole    => 90,
            self::Workerman => 80,
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
