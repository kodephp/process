<?php

declare(strict_types=1);

namespace Kode\Process\Reactor;

use InvalidArgumentException;

/**
 * 事件循环工厂：按可用性与权重自动择优，也支持显式指定驱动。
 *
 * 择优顺序（priority 降序）：
 *   event (100) → ev (90) → select (0，永远可用的兜底)
 *
 * 设计原则：**可选加速 + 零扩展兜底**。
 * 未安装任何扩展时自动回落到 SelectLoop，保证包在裸 PHP 8.3 环境即可运行。
 */
final class LoopFactory
{
    /**
     * 已注册驱动，键为驱动名。
     *
     * @var array<string, class-string<LoopInterface>>
     */
    private const DRIVERS = [
        'event'  => EventLoop::class,
        'ev'     => EvLoop::class,
        'select' => SelectLoop::class,
    ];

    private static ?LoopInterface $global = null;

    /**
     * 创建事件循环实例。
     *
     * @param string|null $driver 驱动名；null 表示自动择优
     * @throws InvalidArgumentException 指定的驱动不存在或当前环境不可用
     */
    public static function create(?string $driver = null): LoopInterface
    {
        if ($driver !== null) {
            $driver = strtolower($driver);
            if (!isset(self::DRIVERS[$driver])) {
                throw new InvalidArgumentException(sprintf(
                    '未知事件循环驱动 "%s"，可选：%s',
                    $driver,
                    implode(', ', array_keys(self::DRIVERS))
                ));
            }
            $class = self::DRIVERS[$driver];
            if (!$class::isSupported()) {
                throw new InvalidArgumentException(sprintf(
                    '事件循环驱动 "%s" 在当前环境不可用（缺少对应扩展）',
                    $driver
                ));
            }
            return new $class();
        }

        $class = self::preferredClass();
        return new $class();
    }

    /**
     * 获取全局共享事件循环（首次调用时自动择优创建）。
     */
    public static function global(): LoopInterface
    {
        return self::$global ??= self::create();
    }

    /** 替换全局事件循环，主要用于测试与在既有运行时中复用宿主循环。 */
    public static function setGlobal(?LoopInterface $loop): void
    {
        self::$global = $loop;
    }

    /** 自动择优选中的驱动名。 */
    public static function preferred(): string
    {
        return self::preferredClass()::name();
    }

    /**
     * @return class-string<LoopInterface>
     */
    private static function preferredClass(): string
    {
        $best     = SelectLoop::class;
        $bestPrio = -1;

        foreach (self::DRIVERS as $class) {
            if (!$class::isSupported()) {
                continue;
            }
            if ($class::priority() > $bestPrio) {
                $bestPrio = $class::priority();
                $best     = $class;
            }
        }

        return $best;
    }

    /**
     * 当前环境所有可用驱动（按权重降序）。
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $found = [];
        foreach (self::DRIVERS as $name => $class) {
            if ($class::isSupported()) {
                $found[$name] = $class::priority();
            }
        }
        arsort($found);
        return array_keys($found);
    }

    /**
     * 驱动可用性自检，便于部署前排查。
     *
     * @return array<string, array{supported:bool, priority:int, preferred:bool}>
     */
    public static function diagnose(): array
    {
        $preferred = self::preferred();
        $report    = [];
        foreach (self::DRIVERS as $name => $class) {
            $report[$name] = [
                'supported' => $class::isSupported(),
                'priority'  => $class::priority(),
                'preferred' => $name === $preferred,
            ];
        }
        return $report;
    }
}
