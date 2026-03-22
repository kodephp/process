<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

use Kode\Process\Exceptions\ProcessException;

/**
 * 进程池接口
 * 
 * 定义进程池的标准操作
 */
interface PoolInterface
{
    public const STRATEGY_ROUND_ROBIN = 'round_robin';
    public const STRATEGY_LEAST_CONNECTIONS = 'least_connections';
    public const STRATEGY_LEAST_LOAD = 'least_load';
    public const STRATEGY_RANDOM = 'random';
    public const STRATEGY_WEIGHTED = 'weighted';

    public function start(int $workerCount): void;

    public function stop(bool $graceful = true): void;

    public function restart(): void;

    public function reload(): void;

    public function scale(int $targetCount): void;

    public function getWorkerCount(): int;

    public function getActiveWorkerCount(): int;

    public function getIdleWorkerCount(): int;

    public function getWorkers(): array;

    public function getWorker(int $id): ?WorkerInterface;

    public function selectWorker(string $strategy = self::STRATEGY_ROUND_ROBIN): ?WorkerInterface;

    public function getTotalProcessed(): int;

    public function getTotalErrors(): int;

    public function getAverageLoad(): float;

    public function setStrategy(string $strategy): void;

    public function getStrategy(): string;
}
