<?php

declare(strict_types=1);

namespace Kode\Process\Master;

use Kode\Process\Contracts\PoolInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Exceptions\WorkerException;
use Kode\Process\Worker\WorkerFactory;
use Kode\Process\Worker\WorkerPool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 进程管理器
 * 
 * 统一管理 Master-Worker 模型的进程架构
 */
class ProcessManager
{
    private ?MasterProcess $master = null;

    private ?WorkerPool $workerPool = null;

    private WorkerFactory $workerFactory;

    private LoggerInterface $logger;

    private array $config;

    private bool $started = false;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'worker_count' => 4,
            'min_workers' => 2,
            'max_workers' => 16,
            'auto_scale' => true,
            'scale_up_threshold' => 0.8,
            'scale_down_threshold' => 0.3,
            'max_requests_per_worker' => 10000,
            'max_memory_per_worker' => 128 * 1024 * 1024,
            'heartbeat_interval' => 5.0,
            'worker_timeout' => 60.0,
            'restart_delay' => 1.0,
            'max_restart_attempts' => 5,
        ], $config);

        $this->logger = $logger ?? new NullLogger();
        $this->workerFactory = new WorkerFactory($this->logger);
    }

    public function start(callable $workerCallback, ?callable $masterCallback = null): void
    {
        if ($this->started) {
            throw ProcessException::processAlreadyRunning($this->master?->getPid() ?? 0);
        }

        $this->logger->info('进程管理器启动中...');

        $this->master = new MasterProcess($this->config, $this->logger);

        $this->workerPool = new WorkerPool(
            $this->config['worker_count'],
            $this->workerFactory,
            $this->logger
        );

        $this->workerPool->setWorkerCallback($workerCallback);

        $this->workerPool->start();

        foreach ($this->workerPool->getWorkers() as $worker) {
            $this->master->addWorker($worker);
        }

        if ($masterCallback !== null) {
            $this->master->onHeartbeat($masterCallback);
        }

        $this->started = true;

        $this->master->start();
    }

    public function stop(bool $graceful = true): void
    {
        if (!$this->started || $this->master === null) {
            return;
        }

        $this->logger->info('进程管理器停止中...', ['graceful' => $graceful]);

        $this->master->stop($graceful);

        if ($this->workerPool !== null) {
            $this->workerPool->stop($graceful);
        }

        $this->started = false;

        $this->logger->info('进程管理器已停止');
    }

    public function restart(): void
    {
        $this->logger->info('进程管理器重启中...');

        $this->stop(true);

        usleep(100000);

        $this->started = false;
    }

    public function reload(): void
    {
        if ($this->master !== null) {
            $this->master->reload();
        }
    }

    public function scale(int $targetCount): void
    {
        if ($this->workerPool === null) {
            throw WorkerException::noAvailableWorker();
        }

        $currentCount = $this->workerPool->getWorkerCount();

        if ($targetCount > $currentCount) {
            $this->scaleUp($targetCount - $currentCount);
        } elseif ($targetCount < $currentCount) {
            $this->scaleDown($currentCount - $targetCount);
        }
    }

    private function scaleUp(int $count): void
    {
        $this->logger->info('扩容 Worker', ['count' => $count]);

        for ($i = 0; $i < $count; $i++) {
            $worker = $this->workerPool->addWorker();

            if ($this->master !== null) {
                $this->master->addWorker($worker);
            }
        }
    }

    private function scaleDown(int $count): void
    {
        $this->logger->info('缩容 Worker', ['count' => $count]);

        for ($i = 0; $i < $count; $i++) {
            $worker = $this->workerPool->removeWorker();

            if ($this->master !== null && $worker !== null) {
                $this->master->removeWorker($worker->getId());
            }
        }
    }

    public function autoScale(): void
    {
        if (!$this->config['auto_scale'] || $this->workerPool === null) {
            return;
        }

        $avgLoad = $this->workerPool->getAverageLoad();
        $currentCount = $this->workerPool->getWorkerCount();

        if ($avgLoad > $this->config['scale_up_threshold'] && $currentCount < $this->config['max_workers']) {
            $this->scaleUp(1);
        } elseif ($avgLoad < $this->config['scale_down_threshold'] && $currentCount > $this->config['min_workers']) {
            $this->scaleDown(1);
        }
    }

    public function getMaster(): ?MasterProcess
    {
        return $this->master;
    }

    public function getWorkerPool(): ?WorkerPool
    {
        return $this->workerPool;
    }

    public function getWorker(int $id): ?WorkerInterface
    {
        return $this->workerPool?->getWorker($id);
    }

    public function getWorkers(): array
    {
        return $this->workerPool?->getWorkers() ?? [];
    }

    public function getWorkerCount(): int
    {
        return $this->workerPool?->getWorkerCount() ?? 0;
    }

    public function getActiveWorkerCount(): int
    {
        return $this->workerPool?->getActiveWorkerCount() ?? 0;
    }

    public function getIdleWorkerCount(): int
    {
        return $this->workerPool?->getIdleWorkerCount() ?? 0;
    }

    public function isRunning(): bool
    {
        return $this->started && ($this->master?->isRunning() ?? false);
    }

    public function getStatus(): array
    {
        return [
            'started' => $this->started,
            'master' => $this->master?->getPid(),
            'workers' => [
                'total' => $this->getWorkerCount(),
                'active' => $this->getActiveWorkerCount(),
                'idle' => $this->getIdleWorkerCount(),
            ],
            'config' => $this->config,
        ];
    }

    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
