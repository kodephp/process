<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

/**
 * 信号处理器接口
 * 
 * 定义信号处理的标准方法
 */
interface SignalHandlerInterface
{
    public function register(int $signal, callable $handler): void;

    public function unregister(int $signal): void;

    public function dispatch(int $signal, mixed $info = null): void;

    public function getRegisteredSignals(): array;

    public function hasHandler(int $signal): bool;

    public function clear(): void;

    public function ignore(int $signal): void;

    public function getDefaultHandler(int $signal): ?callable;
}
