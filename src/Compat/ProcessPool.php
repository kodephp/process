<?php

declare(strict_types=1);

namespace Kode\Process\Compat;

use Kode\Process\Worker\WorkerPool;
use Kode\Process\Worker\WorkerFactory;
use Psr\Log\NullLogger;

class ProcessPool
{
    private WorkerPool $pool;

    private array $callbacks = [];

    public function __construct(int $workerCount = 0, int $IPCType = 0, ?string $sockType = null)
    {
        $this->pool = new WorkerPool($workerCount > 0 ? $workerCount : 4);
    }

    public static function create(int $workerCount, int $IPCType = 0, ?string $sockType = null): self
    {
        return new self($workerCount, $IPCType, $sockType);
    }

    public function on(string $event, callable $callback): self
    {
        $this->callbacks[$event] = $callback;
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->callbacks['WorkerStart'] = $callback;
        return $this;
    }

    public function onWorkerStop(callable $callback): self
    {
        $this->callbacks['WorkerStop'] = $callback;
        return $this;
    }

    public function onMessage(callable $callback): self
    {
        $this->callbacks['Message'] = $callback;
        return $this;
    }

    public function start(): void
    {
        if (isset($this->callbacks['WorkerStart'])) {
            $callback = $this->callbacks['WorkerStart'];
            $this->pool->setWorkerCallback(function ($taskId, $data) use ($callback) {
                $worker = new PoolWorker($taskId, $this->pool);
                $callback($this->pool, $taskId);
                return null;
            });
        }

        if (isset($this->callbacks['Message'])) {
            $callback = $this->callbacks['Message'];
            $this->pool->setWorkerCallback(function ($taskId, $data) use ($callback) {
                $callback($this->pool, $data);
                return null;
            });
        }

        $this->pool->start();
    }

    public function shutdown(): void
    {
        $this->pool->stop();
    }

    public function getWorkerCount(): int
    {
        return $this->pool->getWorkerCount();
    }

    public function getIdleWorkerCount(): int
    {
        return $this->pool->getIdleWorkerCount();
    }

    public function scale(int $targetCount): void
    {
        $this->pool->scale($targetCount);
    }

    public function getPool(): WorkerPool
    {
        return $this->pool;
    }

    public function selectWorker(): ?PoolWorker
    {
        $worker = $this->pool->selectWorker();
        if ($worker === null) {
            return null;
        }
        return new PoolWorker($worker->getId(), $this->pool);
    }
}

class PoolWorker
{
    private int $id;

    private WorkerPool $pool;

    public function __construct(int $id, WorkerPool $pool)
    {
        $this->id = $id;
        $this->pool = $pool;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function send(mixed $data): void
    {
        $worker = $this->pool->getWorker($this->id);
        if ($worker) {
            $worker->send($data);
        }
    }

    public function exit(int $code = 0): void
    {
        exit($code);
    }
}
