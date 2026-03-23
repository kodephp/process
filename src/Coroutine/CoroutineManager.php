<?php

declare(strict_types=1);

namespace Kode\Process\Coroutine;

use Kode\Process\Coroutine\Driver\FibersDriver;
use Kode\Process\Coroutine\Driver\SwowDriver;
use RuntimeException;

/**
 * 协程管理器
 * 
 * 支持多种协程驱动：
 * - kode/fibers: 纯 PHP Fiber 实现（默认）
 * - swow: Swow 扩展实现（需要安装 Swow 扩展）
 * 
 * @example
 * // 使用默认驱动（自动检测）
 * $manager = CoroutineManager::getInstance();
 * 
 * // 指定驱动
 * $manager = CoroutineManager::getInstance('swow');
 * $manager = CoroutineManager::getInstance('fibers');
 * 
 * // 创建协程
 * $manager->go(function () {
 *     echo "Hello Coroutine\n";
 * });
 */
final class CoroutineManager
{
    private static ?self $instance = null;
    private static string $defaultDriver = 'fibers';

    private CoroutineDriverInterface $driver;

    private function __construct(string $driverName)
    {
        $this->driver = self::createDriver($driverName);
    }

    /**
     * 获取单例实例
     * 
     * @param string|null $driver 驱动名称（fibers/swow），null 使用默认驱动
     */
    public static function getInstance(?string $driver = null): self
    {
        if (self::$instance === null || ($driver !== null && self::$defaultDriver !== $driver)) {
            $driverName = $driver ?? self::$defaultDriver;
            self::$instance = new self($driverName);
            self::$defaultDriver = $driverName;
        }

        return self::$instance;
    }

    /**
     * 设置默认驱动
     */
    public static function setDefaultDriver(string $driver): void
    {
        self::$defaultDriver = $driver;
        self::$instance = null;
    }

    /**
     * 创建驱动实例
     */
    private static function createDriver(string $name): CoroutineDriverInterface
    {
        return match ($name) {
            'fibers', 'kode/fibers' => new FibersDriver(),
            'swow' => self::createSwowDriver(),
            default => throw new RuntimeException("不支持的协程驱动: {$name}"),
        };
    }

    /**
     * 创建 Swow 驱动（带检测）
     */
    private static function createSwowDriver(): SwowDriver
    {
        if (!extension_loaded('swow')) {
            throw new RuntimeException(
                "Swow 扩展未安装。请安装后重试：\n" .
                "  pecl install swow\n" .
                "或\n" .
                "  composer require swow/swow\n" .
                "  php vendor/bin/swow-builder --install"
            );
        }

        return new SwowDriver();
    }

    /**
     * 获取当前驱动
     */
    public function getDriver(): CoroutineDriverInterface
    {
        return $this->driver;
    }

    /**
     * 获取驱动名称
     */
    public function getDriverName(): string
    {
        return $this->driver->getName();
    }

    /**
     * 创建并运行协程
     */
    public function go(callable $callback): mixed
    {
        return $this->driver->go($callback);
    }

    /**
     * 批量并发执行
     */
    public function batch(array $items, callable $callback, int $concurrency = 10): array
    {
        return $this->driver->batch($items, $callback, $concurrency);
    }

    /**
     * 协程睡眠
     */
    public function sleep(float $seconds): void
    {
        $this->driver->sleep($seconds);
    }

    /**
     * 获取当前协程ID
     */
    public function getCurrentId(): int
    {
        return $this->driver->getCurrentId();
    }

    /**
     * 检查是否在协程中
     */
    public function inCoroutine(): bool
    {
        return $this->driver->inCoroutine();
    }

    /**
     * 创建通道
     */
    public function createChannel(int $capacity = 0): ChannelInterface
    {
        return $this->driver->createChannel($capacity);
    }

    /**
     * 创建等待组
     */
    public function createWaitGroup(): WaitGroupInterface
    {
        return $this->driver->createWaitGroup();
    }

    /**
     * 检测可用的驱动
     * 
     * @return array 可用驱动列表
     */
    public static function getAvailableDrivers(): array
    {
        $drivers = ['fibers' => true];

        $drivers['swow'] = extension_loaded('swow');

        return $drivers;
    }

    /**
     * 自动选择最佳驱动
     */
    public static function getBestDriver(): string
    {
        // Swow 性能更好，优先使用
        if (extension_loaded('swow')) {
            return 'swow';
        }

        // 默认使用 fibers
        return 'fibers';
    }

    /**
     * 重置实例（用于测试）
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$defaultDriver = 'fibers';
    }
}
