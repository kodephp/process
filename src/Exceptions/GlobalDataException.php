<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * 共享数据（GlobalData）异常
 */
class GlobalDataException extends RuntimeException
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

    public static function unsupported(string $extension): self
    {
        return new self(
            sprintf('GlobalData 需要 %s 扩展，但当前环境未加载', $extension),
            5201,
            null,
            ['extension' => $extension]
        );
    }

    public static function attachFailed(int $key, string $reason = ''): self
    {
        return new self(
            sprintf('共享内存段附加失败 [key=0x%08X]: %s', $key, $reason ?: '未知原因'),
            5202,
            null,
            ['key' => $key, 'reason' => $reason]
        );
    }

    public static function semaphoreFailed(int $key, string $reason = ''): self
    {
        return new self(
            sprintf('信号量创建失败 [key=0x%08X]: %s', $key, $reason ?: '未知原因'),
            5203,
            null,
            ['key' => $key, 'reason' => $reason]
        );
    }

    public static function notNumeric(string $key): self
    {
        return new self(
            sprintf('键 "%s" 的值不是数字，无法执行自增/自减', $key),
            5204,
            null,
            ['key' => $key]
        );
    }

    public static function keyNotFound(string $key): self
    {
        return new self(
            sprintf('键 "%s" 不存在，无法执行 CAS', $key),
            5205,
            null,
            ['key' => $key]
        );
    }

    public static function closed(): self
    {
        return new self('共享数据表已关闭', 5206);
    }
}
