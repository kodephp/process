<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

use Kode\Process\Exceptions\ProcessException;

/**
 * 进程接口
 * 
 * 定义进程的基本生命周期操作
 */
interface ProcessInterface
{
    public const STATE_IDLE = 'idle';
    public const STATE_STARTING = 'starting';
    public const STATE_RUNNING = 'running';
    public const STATE_STOPPING = 'stopping';
    public const STATE_STOPPED = 'stopped';
    public const STATE_ERROR = 'error';

    public function start(): void;

    public function stop(bool $graceful = true): void;

    public function restart(): void;

    public function getState(): string;

    public function getPid(): int;

    public function isRunning(): bool;

    public function isMaster(): bool;

    public function isWorker(): bool;

    public function getStartTime(): float;

    public function getMemoryUsage(): int;

    public function getCpuUsage(): float;
}
