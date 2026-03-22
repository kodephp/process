<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * 进程异常基类
 */
class ProcessException extends RuntimeException
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

    public static function forkFailed(string $reason = ''): self
    {
        return new self(
            sprintf('进程 fork 失败: %s', $reason ?: '未知原因'),
            1001,
            null,
            ['reason' => $reason]
        );
    }

    public static function processNotFound(int $pid): self
    {
        return new self(
            sprintf('进程不存在: PID %d', $pid),
            1002,
            null,
            ['pid' => $pid]
        );
    }

    public static function processAlreadyRunning(int $pid): self
    {
        return new self(
            sprintf('进程已在运行: PID %d', $pid),
            1003,
            null,
            ['pid' => $pid]
        );
    }

    public static function processNotRunning(int $pid): self
    {
        return new self(
            sprintf('进程未运行: PID %d', $pid),
            1004,
            null,
            ['pid' => $pid]
        );
    }

    public static function signalFailed(int $signal, int $pid, string $reason = ''): self
    {
        return new self(
            sprintf('发送信号 %d 到进程 %d 失败: %s', $signal, $pid, $reason ?: '未知原因'),
            1005,
            null,
            ['signal' => $signal, 'pid' => $pid, 'reason' => $reason]
        );
    }

    public static function invalidState(string $expected, string $actual): self
    {
        return new self(
            sprintf('无效的进程状态，期望 %s，实际 %s', $expected, $actual),
            1006,
            null,
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    public static function timeout(string $operation, float $seconds): self
    {
        return new self(
            sprintf('操作超时: %s (%.2f 秒)', $operation, $seconds),
            1007,
            null,
            ['operation' => $operation, 'timeout' => $seconds]
        );
    }
}
