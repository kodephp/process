<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

use Kode\Process\Exceptions\ProcessException;

/**
 * Worker 进程接口
 * 
 * 定义 Worker 进程的具体职责
 */
interface WorkerInterface extends ProcessInterface
{
    public const STATUS_FREE = 'free';
    public const STATUS_BUSY = 'busy';
    public const STATUS_OVERLOADED = 'overloaded';

    public function getId(): int;

    public function getStatus(): string;

    public function getProcessedCount(): int;

    public function getErrorCount(): int;

    public function getCurrentTask(): ?string;

    public function getMaxConnections(): int;

    public function getCurrentConnections(): int;

    public function getLoad(): float;

    public function heartbeat(): array;

    public function assignTask(string $taskId, array $data): void;

    public function completeTask(string $taskId, mixed $result): void;

    public function failTask(string $taskId, \Throwable $error): void;
}
