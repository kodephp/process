<?php

declare(strict_types=1);

namespace Kode\Process\Parallel;

use parallel\Future as ParallelFuture;
use parallel\Runtime as ParallelRuntime;

/**
 * 基于 ext-parallel 的 Future 实现
 *
 * 包裹 {@see Runtime} 与 {@see Future}，任务在独立 OS 线程中执行，
 * 调用方通过 {@see Parallel::await()} 在协程中等待其结果。
 */
final class Future implements FutureInterface
{
    private ParallelFuture $future;
    private ParallelRuntime $runtime;
    private ?\Throwable $error = null;
    private mixed $result = null;
    private bool $resolved = false;

    public function __construct(ParallelFuture $future, ParallelRuntime $runtime)
    {
        $this->future = $future;
        $this->runtime = $runtime;
    }

    public function done(): bool
    {
        return $this->future->done();
    }

    public function value(): mixed
    {
        if (!$this->resolved) {
            try {
                $this->result = $this->future->value();
            } catch (\Throwable $e) {
                $this->error = $e;
            }

            $this->resolved = true;
            $this->closeRuntime();
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    public function isSuccessful(): bool
    {
        return $this->resolved && $this->error === null;
    }

    public function isFailed(): bool
    {
        return $this->resolved && $this->error !== null;
    }

    public function getException(): ?\Throwable
    {
        return $this->error;
    }

    /**
     * 任务取值后释放运行时，避免线程资源泄漏
     */
    private function closeRuntime(): void
    {
        try {
            $this->runtime->close();
        } catch (\Throwable) {
            // 部分 parallel 版本在 future 完成后 runtime 已自动关闭
        }
    }
}
