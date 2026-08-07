<?php

declare(strict_types=1);

namespace Kode\Process;

use InvalidArgumentException;
use Kode\Process\Runtime\Driver\SwooleRuntime;
use Kode\Process\Runtime\Driver\WorkermanRuntime;
use Kode\Process\Runtime\Driver\NativeRuntime;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeInterface;
use Kode\Process\Runtime\RuntimeType;

/**
 * 运行时门面：一套 API，三种实现（Native 自研 / Swoole / Workerman）。
 *
 * ```php
 * use Kode\Process\Runtime;
 *
 * $rt = Runtime::auto();                                  // 默认自研 Native，零扩展依赖
 * $rt->listen('http://0.0.0.0:8080', ['workers' => 8])
 *    ->on('message', fn($conn, $req) => $conn->send('Hello'))
 *    ->start();
 *
 * // 已有 Swoole / Workerman 技术栈时显式接入，业务代码一行不用改
 * $rt = Runtime::make('swoole');
 * $rt = Runtime::make('workerman');
 * ```
 *
 * 择优顺序：native(100) → swoole(90) → workerman(80)。
 *
 * 设计立场：
 * 本包**默认使用自研（Native）运行时**——纯 PHP 的 master-worker 多进程服务器，
 * 基于可插拔事件循环（ext-event / ext-ev / stream_select 自动择优）+ Protocol 协议系统，
 * 零扩展依赖即可运行，同时支持 TaskWorker、UDP、SSL、HTTP keep-alive、异步发送缓冲、
 * 平滑重载、心跳回收、守护进程等生产级能力。
 *
 * Swoole / Workerman 作为**可选接入**保留，供已有技术栈的项目复用宿主生态。
 * 三者实现同一套 {@see RuntimeInterface}（含 workerId / connections / broadcast / task），
 * 应用面向接口编程，切换底层无需改动任何业务代码。
 */
final class Runtime
{
    /**
     * 已注册驱动。
     *
     * @var array<string, class-string<RuntimeInterface>>
     */
    private const DRIVERS = [
        'native'    => NativeRuntime::class,
        'swoole'    => SwooleRuntime::class,
        'workerman' => WorkermanRuntime::class,
    ];

    /** @var array<string, class-string<RuntimeInterface>> 运行期注册的自定义驱动 */
    private static array $custom = [];

    private function __construct()
    {
    }

    /**
     * 自动择优创建运行时。
     *
     * @param list<RuntimeType|string> $prefer 显式偏好顺序，命中第一个可用的即返回；
     *                                         留空则按 RuntimeType::priority() 降序
     */
    public static function auto(array $prefer = []): RuntimeInterface
    {
        foreach ($prefer as $candidate) {
            $type = self::normalize($candidate);
            if (self::isSupported($type)) {
                return self::make($type);
            }
        }

        return self::make(self::preferred());
    }

    /**
     * 显式创建指定运行时。
     *
     * @throws InvalidArgumentException      驱动名未注册
     * @throws RuntimeNotSupportedException  驱动在当前环境不可用
     */
    public static function make(RuntimeType|string $type): RuntimeInterface
    {
        $name  = self::normalize($type);
        $class = self::resolve($name);

        if (!$class::isAvailable()) {
            throw RuntimeNotSupportedException::unavailable(
                RuntimeType::tryFrom($name) ?? RuntimeType::Workerman,
                self::installHint($name)
            );
        }

        return new $class();
    }

    /**
     * 当前环境自动择优选中的运行时。
     */
    public static function preferred(): RuntimeType
    {
        $best     = null;
        $bestPrio = -1;

        foreach (RuntimeType::cases() as $type) {
            if (!self::isSupported($type)) {
                continue;
            }
            if ($type->priority() > $bestPrio) {
                $bestPrio = $type->priority();
                $best     = $type;
            }
        }

        if ($best === null) {
            throw RuntimeNotSupportedException::unavailable(
                RuntimeType::Native,
                '自研 Native 运行时需要 CLI SAPI 与 pcntl/posix 扩展；'
                . '也可改用 Swoole（pecl install swoole）或 Workerman（composer require workerman/workerman）'
            );
        }

        return $best;
    }

    /** 指定运行时在当前环境是否可用 */
    public static function isSupported(RuntimeType|string $type): bool
    {
        try {
            $class = self::resolve(self::normalize($type));
        } catch (InvalidArgumentException) {
            return false;
        }

        return $class::isAvailable();
    }

    /**
     * 当前环境所有可用运行时（按权重降序）。
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $found = [];

        foreach (self::allDrivers() as $name => $class) {
            if (!$class::isAvailable()) {
                continue;
            }
            $found[$name] = RuntimeType::tryFrom($name)?->priority() ?? 0;
        }

        arsort($found);

        return array_keys($found);
    }

    /**
     * 部署前自检：谁可用、版本多少、事件循环用的哪个驱动。
     *
     * @return array{
     *     preferred: string,
     *     loop: array<string, array{supported:bool, priority:int, preferred:bool}>,
     *     runtimes: array<string, array{available:bool, version:string|null, priority:int, preferred:bool}>,
     *     recommendation: string|null
     * }
     */
    public static function diagnose(): array
    {
        $preferred = self::preferred()->value;
        $runtimes  = [];

        foreach (self::allDrivers() as $name => $class) {
            $available        = $class::isAvailable();
            $runtimes[$name]  = [
                'available' => $available,
                'version'   => $available ? $class::version() : null,
                'priority'  => RuntimeType::tryFrom($name)?->priority() ?? 0,
                'preferred' => $name === $preferred,
            ];
        }

        // Workerman 在 Linux 上强烈建议 ext-event；若当前可用且未装则给出安装提示
        $recommendation = null;
        if (isset($runtimes['workerman']) && $runtimes['workerman']['available']) {
            $recommendation = WorkermanRuntime::eventLoopRecommendation();
        }

        return [
            'preferred'      => $preferred,
            'loop'           => Reactor\LoopFactory::diagnose(),
            'runtimes'       => $runtimes,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * 注册自定义运行时驱动，便于接入 RoadRunner、FrankenPHP 等宿主。
     *
     * @param class-string<RuntimeInterface> $class
     * @throws InvalidArgumentException 类未实现 RuntimeInterface
     */
    public static function register(string $name, string $class): void
    {
        if (!is_a($class, RuntimeInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                '运行时驱动 %s 必须实现 %s',
                $class,
                RuntimeInterface::class
            ));
        }

        self::$custom[strtolower($name)] = $class;
    }

    /** 清空自定义驱动，主要用于测试隔离 */
    public static function reset(): void
    {
        self::$custom = [];
    }

    /**
     * @return array<string, class-string<RuntimeInterface>>
     */
    private static function allDrivers(): array
    {
        return self::DRIVERS + self::$custom;
    }

    /**
     * @return class-string<RuntimeInterface>
     * @throws InvalidArgumentException
     */
    private static function resolve(string $name): string
    {
        $drivers = self::allDrivers();

        if (!isset($drivers[$name])) {
            throw new InvalidArgumentException(sprintf(
                '未知运行时 "%s"，可选：%s',
                $name,
                implode(', ', array_keys($drivers))
            ));
        }

        return $drivers[$name];
    }

    private static function normalize(RuntimeType|string $type): string
    {
        return $type instanceof RuntimeType ? $type->value : strtolower($type);
    }

    private static function installHint(string $name): string
    {
        return match ($name) {
            'swoole'    => '请安装 ext-swoole：pecl install swoole',
            'workerman' => '请执行 composer require workerman/workerman ^5.0',
            default     => '当前环境不满足该运行时的依赖',
        };
    }
}
