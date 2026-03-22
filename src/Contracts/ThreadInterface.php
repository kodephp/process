<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

/**
 * 线程接口
 * 
 * 定义线程的基本操作（基于 pthreads 扩展）
 */
interface ThreadInterface
{
    public const STATE_NEW = 'new';
    public const STATE_RUNNING = 'running';
    public const STATE_WAITING = 'waiting';
    public const STATE_TERMINATED = 'terminated';

    public function start(): bool;

    public function join(): bool;

    public function detach(): bool;

    public function kill(): bool;

    public function getState(): string;

    public function getId(): int;

    public function isRunning(): bool;

    public function isJoined(): bool;

    public function getExitStatus(): ?int;

    public function getException(): ?\Throwable;
}
