<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use Kode\Process\Version;
use RuntimeException;

/**
 * 并行（多线程）相关错误
 */
final class ParallelException extends RuntimeException
{
    /**
     * 当前环境不支持真正的多线程并行
     *
     * 多线程需要 ZTS（线程安全）构建的 PHP 并加载 ext-parallel。
     */
    public static function notAvailable(): self
    {
        $zts = Version::isZts() ? 'yes' : 'no';
        $parallelExt = extension_loaded('parallel') ? 'yes' : 'no';
        $backend = Version::parallelBackend();

        return new self(
            "当前环境不支持真正的多线程并行（requires ZTS + ext-parallel）。\n" .
            "  检测: ZTS={$zts}, ext-parallel={$parallelExt}, backend={$backend}\n" .
            "  解决: 使用 ZTS 构建的 PHP 并安装 ext-parallel（或 kode/parallel 封装包）；详见 docs/parallel.md"
        );
    }

    public static function taskFailed(string $reason = ''): self
    {
        return new self(sprintf('并行任务执行失败: %s', $reason ?: '未知原因'));
    }
}
