<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * 信号异常
 */
class SignalException extends RuntimeException
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

    public static function unsupportedSignal(int $signal): self
    {
        return new self(
            sprintf('不支持的信号: %d', $signal),
            5001,
            null,
            ['signal' => $signal]
        );
    }

    public static function handlerRegistrationFailed(int $signal, string $reason = ''): self
    {
        return new self(
            sprintf('注册信号处理器失败 [%d]: %s', $signal, $reason ?: '未知原因'),
            5002,
            null,
            ['signal' => $signal, 'reason' => $reason]
        );
    }

    public static function dispatchFailed(int $signal, string $reason = ''): self
    {
        return new self(
            sprintf('信号分发失败 [%d]: %s', $signal, $reason ?: '未知原因'),
            5003,
            null,
            ['signal' => $signal, 'reason' => $reason]
        );
    }

    public static function extensionNotLoaded(): self
    {
        return new self(
            'pcntl 扩展未加载，无法使用信号处理功能',
            5004
        );
    }
}
