<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * Worker 异常
 */
class WorkerException extends RuntimeException
{
    protected array $context = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public static function workerNotFound(int $workerId): self
    {
        return new self(
            sprintf('Worker 不存在: ID %d', $workerId),
            3001,
            null,
            ['worker_id' => $workerId]
        );
    }

    public static function workerFailed(int $workerId, string $reason = ''): self
    {
        return new self(
            sprintf('Worker %d 执行失败: %s', $workerId, $reason ?: '未知原因'),
            3002,
            null,
            ['worker_id' => $workerId, 'reason' => $reason]
        );
    }

    public static function workerOverloaded(int $workerId, float $load): self
    {
        return new self(
            sprintf('Worker %d 过载: %.2f%%', $workerId, $load * 100),
            3003,
            null,
            ['worker_id' => $workerId, 'load' => $load]
        );
    }

    public static function taskFailed(string $taskId, string $reason = ''): self
    {
        return new self(
            sprintf('任务执行失败 [%s]: %s', $taskId, $reason ?: '未知原因'),
            3004,
            null,
            ['task_id' => $taskId, 'reason' => $reason]
        );
    }

    public static function poolFull(int $maxWorkers): self
    {
        return new self(
            sprintf('Worker 池已满: 最大 %d 个 Worker', $maxWorkers),
            3005,
            null,
            ['max_workers' => $maxWorkers]
        );
    }

    public static function noAvailableWorker(): self
    {
        return new self('没有可用的 Worker', 3006);
    }

    public static function invalidTask(string $taskId, string $reason = ''): self
    {
        return new self(
            sprintf('无效的任务 [%s]: %s', $taskId, $reason ?: '未知原因'),
            3007,
            null,
            ['task_id' => $taskId, 'reason' => $reason]
        );
    }
}
