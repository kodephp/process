<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * IPC 通信异常
 */
class IPCException extends RuntimeException
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

    public static function connectionFailed(string $type, string $reason = ''): self
    {
        return new self(
            sprintf('IPC 连接失败 [%s]: %s', $type, $reason ?: '未知原因'),
            2001,
            null,
            ['type' => $type, 'reason' => $reason]
        );
    }

    public static function sendFailed(int $targetPid, string $reason = ''): self
    {
        return new self(
            sprintf('发送消息到进程 %d 失败: %s', $targetPid, $reason ?: '未知原因'),
            2002,
            null,
            ['target_pid' => $targetPid, 'reason' => $reason]
        );
    }

    public static function receiveFailed(string $reason = ''): self
    {
        return new self(
            sprintf('接收消息失败: %s', $reason ?: '未知原因'),
            2003,
            null,
            ['reason' => $reason]
        );
    }

    public static function bufferOverflow(int $size, int $maxSize): self
    {
        return new self(
            sprintf('缓冲区溢出: %d 字节 (最大 %d 字节)', $size, $maxSize),
            2004,
            null,
            ['size' => $size, 'max_size' => $maxSize]
        );
    }

    public static function channelClosed(): self
    {
        return new self('IPC 通道已关闭', 2005);
    }

    public static function timeout(float $seconds): self
    {
        return new self(
            sprintf('IPC 操作超时: %.2f 秒', $seconds),
            2006,
            null,
            ['timeout' => $seconds]
        );
    }

    public static function serializationFailed(mixed $data, string $reason = ''): self
    {
        return new self(
            sprintf('数据序列化失败: %s', $reason ?: '未知原因'),
            2007,
            null,
            ['data_type' => gettype($data), 'reason' => $reason]
        );
    }

    public static function sharedMemoryFailed(string $operation, string $reason = ''): self
    {
        return new self(
            sprintf('共享内存操作失败 [%s]: %s', $operation, $reason ?: '未知原因'),
            2008,
            null,
            ['operation' => $operation, 'reason' => $reason]
        );
    }
}
