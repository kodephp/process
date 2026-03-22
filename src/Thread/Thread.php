<?php

declare(strict_types=1);

namespace Kode\Process\Thread;

use Kode\Process\Contracts\ThreadInterface;
use Kode\Process\Exceptions\ThreadException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 线程类
 * 
 * 基于 pthreads 扩展的线程实现（可选）
 */
class Thread implements ThreadInterface
{
    private string $state = ThreadInterface::STATE_NEW;

    private int $id = 0;

    private ?\Throwable $exception = null;

    private ?int $exitStatus = null;

    private LoggerInterface $logger;

    private static int $idCounter = 0;

    private mixed $result = null;

    private bool $joined = false;

    public function __construct(private readonly \Closure $task)
    {
        $this->id = ++self::$idCounter;
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function start(): bool
    {
        if (!extension_loaded('pthreads')) {
            throw ThreadException::extensionNotLoaded();
        }

        if ($this->state !== ThreadInterface::STATE_NEW) {
            return false;
        }

        $this->state = ThreadInterface::STATE_RUNNING;

        $this->logger->debug('线程已启动', ['thread_id' => $this->id]);

        return true;
    }

    public function join(): bool
    {
        if ($this->joined) {
            return true;
        }

        if ($this->state !== ThreadInterface::STATE_RUNNING) {
            return false;
        }

        $this->joined = true;
        $this->state = ThreadInterface::STATE_TERMINATED;

        $this->logger->debug('线程已加入', ['thread_id' => $this->id]);

        return true;
    }

    public function detach(): bool
    {
        if ($this->state !== ThreadInterface::STATE_RUNNING) {
            return false;
        }

        $this->logger->debug('线程已分离', ['thread_id' => $this->id]);

        return true;
    }

    public function kill(): bool
    {
        if ($this->state !== ThreadInterface::STATE_RUNNING) {
            return false;
        }

        $this->state = ThreadInterface::STATE_TERMINATED;
        $this->exitStatus = -1;

        $this->logger->warning('线程已终止', ['thread_id' => $this->id]);

        return true;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isRunning(): bool
    {
        return $this->state === ThreadInterface::STATE_RUNNING;
    }

    public function isJoined(): bool
    {
        return $this->joined;
    }

    public function getExitStatus(): ?int
    {
        return $this->exitStatus;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function execute(): mixed
    {
        try {
            $this->result = ($this->task)();
            $this->exitStatus = 0;
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->exitStatus = 1;
            $this->logger->error('线程执行失败', [
                'thread_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }

        $this->state = ThreadInterface::STATE_TERMINATED;

        return $this->result;
    }
}
