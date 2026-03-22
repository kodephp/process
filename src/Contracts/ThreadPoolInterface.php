<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

/**
 * 线程池接口
 * 
 * 定义线程池的标准操作
 */
interface ThreadPoolInterface
{
    public function submit(callable $task): mixed;

    public function submitAsync(callable $task): string;

    public function map(array $data, callable $transform): array;

    public function reduce(array $data, callable $reducer, mixed $initial = null): mixed;

    public function getSize(): int;

    public function getActiveCount(): int;

    public function getIdleCount(): int;

    public function resize(int $size): void;

    public function shutdown(bool $waitForCompletion = true): void;

    public function awaitCompletion(string $taskId, ?float $timeout = null): mixed;

    public function getQueueSize(): int;

    public function getCompletedCount(): int;

    public function getFailedCount(): int;
}
