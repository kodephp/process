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

    private ?\Closure $workerCallback = null;

    private ?\Closure $masterCallback = null;

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

        $this->workerCallback = $workerCallback(...);

        if ($masterCallback !== null) {
            $this->masterCallback = $masterCallback(...);
        }

        // 配置键名对齐：ProcessManager 侧叫 *_per_worker/worker_timeout/restart_delay，
        // WorkerFactory 与 MasterProcess 读的是另一套键——此前从不传递，自定义配置静默失效。
        $this->workerFactory->setDefaults([
            'max_requests' => $this->config['max_requests_per_worker'],
            'max_memory' => $this->config['max_memory_per_worker'],
            'heartbeat_timeout' => $this->config['worker_timeout'],
        ]);

        $masterConfig = array_merge($this->config, [
            'max_requests' => $this->config['max_requests_per_worker'],
            'max_restart_attempts' => $this->config['max_restart_attempts'],
            'restart_backoff_base' => (int) ($this->config['restart_delay'] * 1000000),
        ]);

        $this->master = new MasterProcess($masterConfig, $this->logger);

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

        // 注入 worker 重生器：worker 异常退出时由 Master 自动拉起新实例，维持池容量。
        // 未注入时（直接 new MasterProcess 使用）保持旧行为——退出不重生。
        $this->master->setWorkerSpawner(fn() => $this->workerPool->addWorker());

        if ($this->masterCallback !== null) {
            $this->master->onHeartbeat($this->masterCallback);
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

        if ($this->workerCallback === null) {
            $this->logger->warning('restart() 前从未成功 start()，无回调可复用，仅完成停止');
            return;
        }

        $this->start($this->workerCallback, $this->masterCallback);
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
