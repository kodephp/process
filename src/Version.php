<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Exceptions\ProcessException;

/**
 * 版本与运行环境信息
 *
 * 自 3.0.0 起最低要求 PHP 8.3。所有在 8.3 已无条件可用的特性探测方法均已移除，
 * 仅保留对 8.4 / 8.5 前瞻特性的运行时探测。
 */
final class Version
{
    public const int MAJOR = 5;
    public const int MINOR = 2;
    public const int PATCH = 34;
    public const string VERSION = '5.2.34';
    public const int VERSION_ID = 50234;

    /** 本库要求的最低 PHP 版本 */
    public const string MINIMUM_PHP_VERSION = '8.3.0';
    public const int MINIMUM_PHP_VERSION_ID = 80300;

    /** 运行所必需的扩展 */
    public const array REQUIRED_EXTENSIONS = ['pcntl', 'posix', 'sockets'];

    /** 可选扩展及其用途 */
    public const array OPTIONAL_EXTENSIONS = [
        'event' => 'Reactor 首选事件循环驱动（libevent）',
        'ev' => 'Reactor 备选事件循环驱动（libev）',
        'apcu' => 'SharedTable 零安装后端（优先于 sysvshm）',
        'sysvshm' => 'SharedTable 零安装后端 / 共享内存 IPC',
        'sysvmsg' => 'System V 消息队列 IPC',
        'sysvsem' => '信号量同步',
        'parallel' => '多线程并行处理（需要 ZTS 线程安全版 PHP + ext-parallel）',
        'swoole' => 'Swoole 运行时适配器',
        'openssl' => 'SSL/TLS 监听',
    ];

    /**
     * 当前 PHP 是否为 ZTS（Zend 线程安全）构建。
     *
     * 真正的多线程（ext-parallel）只能在 ZTS 构建上加载并运行；
     * 普通 NTS 构建无法启用 parallel 扩展。
     */
    public const int IS_ZTS = \PHP_ZTS;

    public static function get(): string
    {
        return self::VERSION;
    }

    public static function getId(): int
    {
        return self::VERSION_ID;
    }

    public static function getMajor(): int
    {
        return self::MAJOR;
    }

    public static function getMinor(): int
    {
        return self::MINOR;
    }

    public static function getPatch(): int
    {
        return self::PATCH;
    }

    public static function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    public static function getPhpVersionId(): int
    {
        return PHP_VERSION_ID;
    }

    public static function getPhpMajorVersion(): int
    {
        return PHP_MAJOR_VERSION;
    }

    public static function getPhpMinorVersion(): int
    {
        return PHP_MINOR_VERSION;
    }

    public static function isPhp83(): bool
    {
        return PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400;
    }

    public static function isPhp84(): bool
    {
        return PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500;
    }

    public static function isPhp85(): bool
    {
        return PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600;
    }

    /**
     * 当前 PHP 是否满足最低版本要求
     */
    public static function isPhpSupported(): bool
    {
        return PHP_VERSION_ID >= self::MINIMUM_PHP_VERSION_ID;
    }

    /**
     * 断言运行环境可用，不满足时抛出异常
     *
     * @throws ProcessException
     */
    public static function requireSupportedEnvironment(): void
    {
        $problems = self::checkEnvironment();

        if ($problems !== []) {
            throw new ProcessException('运行环境不满足要求: ' . implode('; ', $problems));
        }
    }

    /**
     * 检查运行环境，返回问题清单（为空表示通过）
     *
     * @return list<string>
     */
    public static function checkEnvironment(): array
    {
        $problems = [];

        if (!self::isPhpSupported()) {
            $problems[] = sprintf(
                'PHP >= %s，当前 %s',
                self::MINIMUM_PHP_VERSION,
                PHP_VERSION
            );
        }

        $missing = self::getMissingExtensions();

        if ($missing !== []) {
            $problems[] = '缺少扩展: ' . implode(', ', $missing);
        }

        return $problems;
    }

    /**
     * 返回缺失的必需扩展
     *
     * @return list<string>
     */
    public static function getMissingExtensions(): array
    {
        $missing = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    /**
     * 返回已加载的可选扩展及用途
     *
     * @return array<string, string>
     */
    public static function getLoadedOptionalExtensions(): array
    {
        $loaded = [];

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $purpose) {
            if (extension_loaded($extension)) {
                $loaded[$extension] = $purpose;
            }
        }

        return $loaded;
    }

    public static function supportsCloneWith(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function supportsPipeOperator(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function supportsUriExtension(): bool
    {
        return PHP_VERSION_ID >= 80500 && extension_loaded('uri');
    }

    public static function supportsNoDiscard(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function supportsPropertyHooks(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    public static function supportsAsymmetricVisibility(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    public static function supportsLazyObjects(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    public static function compare(string $version): int
    {
        return version_compare(self::VERSION, $version);
    }

    public static function isGreaterThan(string $version): bool
    {
        return self::compare($version) > 0;
    }

    public static function isLessThan(string $version): bool
    {
        return self::compare($version) < 0;
    }

    public static function isEqualTo(string $version): bool
    {
        return self::compare($version) === 0;
    }

    /**
     * 前瞻特性探测结果
     *
     * @return array<string, bool>
     */
    public static function getFeatures(): array
    {
        return [
            'property_hooks' => self::supportsPropertyHooks(),
            'asymmetric_visibility' => self::supportsAsymmetricVisibility(),
            'lazy_objects' => self::supportsLazyObjects(),
            'clone_with' => self::supportsCloneWith(),
            'pipe_operator' => self::supportsPipeOperator(),
            'uri_extension' => self::supportsUriExtension(),
            'no_discard' => self::supportsNoDiscard(),
        ];
    }

    /**
     * 当前 PHP 是否为 ZTS（线程安全）构建
     */
    public static function isZts(): bool
    {
        return self::IS_ZTS === 1;
    }

    /**
     * 是否支持真正的多线程并行（ext-parallel / kode/parallel）。
     *
     * 必须同时满足：ZTS 构建 + 已加载 parallel 扩展。
     */
    public static function supportsParallel(): bool
    {
        return self::IS_ZTS
            && (extension_loaded('parallel') || class_exists('parallel\\Runtime'));
    }

    /**
     * 当前可用的并行后端
     *
     * @return 'ext-parallel'|'kode-parallel'|'none'
     */
    public static function parallelBackend(): string
    {
        if (class_exists('parallel\\Runtime') || extension_loaded('parallel')) {
            return 'ext-parallel';
        }

        if (class_exists(\Kode\Parallel\Parallel::class)) {
            return 'kode-parallel';
        }

        return 'none';
    }

    /**
     * 是否支持 pthreads（仅 PHP < 8，已弃用，请改用 parallel）。
     */
    public static function supportsPthreads(): bool
    {
        return extension_loaded('pthreads');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getInfo(): array
    {
        return [
            'version' => self::VERSION,
            'version_id' => self::VERSION_ID,
            'major' => self::MAJOR,
            'minor' => self::MINOR,
            'patch' => self::PATCH,
            'php_version' => PHP_VERSION,
            'php_version_id' => PHP_VERSION_ID,
            'php_major' => PHP_MAJOR_VERSION,
            'php_minor' => PHP_MINOR_VERSION,
            'minimum_php' => self::MINIMUM_PHP_VERSION,
            'php_supported' => self::isPhpSupported(),
            'missing_extensions' => self::getMissingExtensions(),
            'optional_extensions' => self::getLoadedOptionalExtensions(),
            'features' => self::getFeatures(),
            'zts' => self::isZts(),
            'parallel' => self::supportsParallel(),
            'parallel_backend' => self::parallelBackend(),
            'pthreads' => self::supportsPthreads(),
        ];
    }

    public function __toString(): string
    {
        return self::VERSION;
    }
}
