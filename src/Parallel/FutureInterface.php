<?php

declare(strict_types=1);

namespace Kode\Process\Parallel;

/**
 * 并行任务的 Future 抽象
 *
 * 屏蔽 ext-parallel 原生 {@see \parallel\Future} 的细节，使调用方无需关心底层后端。
 */
interface FutureInterface
{
    /**
     * 任务是否已完成（成功或失败均视为完成）
     */
    public function done(): bool;

    /**
     * 阻塞等待并获取结果；任务失败时抛出异常
     */
    public function value(): mixed;

    /**
     * 是否成功完成
     */
    public function isSuccessful(): bool;

    /**
     * 是否因异常失败
     */
    public function isFailed(): bool;

    /**
     * 失败时的异常，成功时为 null
     */
    public function getException(): ?\Throwable;
}
