<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * 线程异常
 */
class ThreadException extends RuntimeException
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

    public static function extensionNotLoaded(): self
    {
        return new self(
            'pthreads 扩展未加载，无法使用多线程功能',
            4001
        );
    }

    public static function threadFailed(int $threadId, string $reason = ''): self
    {
        return new self(
            sprintf('线程 %d 执行失败: %s', $threadId, $reason ?: '未知原因'),
            4002,
            null,
            ['thread_id' => $threadId, 'reason' => $reason]
        );
    }

    public static function threadTimeout(int $threadId, float $seconds): self
    {
        return new self(
            sprintf('线程 %d 超时: %.2f 秒', $threadId, $seconds),
            4003,
            null,
            ['thread_id' => $threadId, 'timeout' => $seconds]
        );
    }

    public static function poolFull(int $maxThreads): self
    {
        return new self(
            sprintf('线程池已满: 最大 %d 个线程', $maxThreads),
            4004,
            null,
            ['max_threads' => $maxThreads]
        );
    }

    public static function noAvailableThread(): self
    {
        return new self('没有可用的线程', 4005);
    }

    public static function joinFailed(int $threadId, string $reason = ''): self
    {
        return new self(
            sprintf('线程 %d join 失败: %s', $threadId, $reason ?: '未知原因'),
            4006,
            null,
            ['thread_id' => $threadId, 'reason' => $reason]
        );
    }

    public static function invalidState(string $expected, string $actual): self
    {
        return new self(
            sprintf('无效的线程状态，期望 %s，实际 %s', $expected, $actual),
            4007,
            null,
            ['expected' => $expected, 'actual' => $actual]
        );
    }
}
