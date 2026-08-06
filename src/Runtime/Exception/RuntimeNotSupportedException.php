<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Exception;

use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\RuntimeType;

/**
 * 运行时不支持所请求的能力或配置时抛出。
 */
class RuntimeNotSupportedException extends ProcessException
{
    public static function capability(RuntimeType $type, Capability $capability): self
    {
        return new self(sprintf(
            '运行时 %s 不支持能力「%s」，请改用其他运行时或调整实现',
            $type->label(),
            $capability->label()
        ));
    }

    public static function scheme(RuntimeType $type, string $scheme): self
    {
        return new self(sprintf(
            '运行时 %s 不支持协议 "%s"',
            $type->label(),
            $scheme
        ));
    }

    public static function unavailable(RuntimeType $type, string $reason = ''): self
    {
        return new self(rtrim(sprintf(
            '运行时 %s 在当前环境不可用。%s',
            $type->label(),
            $reason
        )));
    }
}
