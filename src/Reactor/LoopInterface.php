<?php

declare(strict_types=1);

namespace Kode\Process\Reactor;

/**
 * 事件循环契约。
 *
 * 实现分两档：
 *  - 可选加速：EventLoop（ext-event）、EvLoop（ext-ev），I/O 多路复用下沉到 C 层
 *  - 零扩展兜底：SelectLoop（stream_select），无任何扩展要求
 *
 * 由 {@see LoopFactory} 按 priority() 自动择优，也可显式指定。
 */
interface LoopInterface
{
    /** 当前环境是否可用（扩展是否已加载）。 */
    public static function isSupported(): bool;

    /** 驱动名，如 event / ev / select。 */
    public static function name(): string;

    /** 择优权重，数值越大越优先。 */
    public static function priority(): int;

    /**
     * 注册可读监听。同一 stream 重复注册会覆盖旧回调。
     *
     * @param resource $stream
     * @param callable(resource):void $callback
     */
    public function onReadable($stream, callable $callback): void;

    /** @param resource $stream */
    public function offReadable($stream): void;

    /**
     * 注册可写监听。
     *
     * @param resource $stream
     * @param callable(resource):void $callback
     */
    public function onWritable($stream, callable $callback): void;

    /** @param resource $stream */
    public function offWritable($stream): void;

    /**
     * 注册信号处理器。
     *
     * @param callable(int):void $callback
     */
    public function onSignal(int $signal, callable $callback): void;

    public function offSignal(int $signal): void;

    /**
     * 添加定时器。
     *
     * @param float $interval 间隔秒数，支持小数
     * @param callable():void $callback
     * @param bool $periodic true=周期触发，false=只触发一次
     * @return int 定时器 ID，用于 delTimer()
     */
    public function addTimer(float $interval, callable $callback, bool $periodic = false): int;

    public function delTimer(int $timerId): bool;

    /**
     * 延迟到下一次事件循环迭代执行。
     *
     * @param callable():void $callback
     */
    public function defer(callable $callback): void;

    /** 进入事件循环（阻塞直到 stop()）。 */
    public function run(): void;

    /** 请求退出事件循环。 */
    public function stop(): void;

    public function isRunning(): bool;

    /** 释放所有监听与底层资源。 */
    public function destroy(): void;

    /**
     * 运行期统计。
     *
     * @return array{driver:string, read:int, write:int, timer:int, signal:int, deferred:int}
     */
    public function stats(): array;
}
