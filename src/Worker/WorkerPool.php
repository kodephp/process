<?php

declare(strict_types=1);

namespace Kode\Process\Worker;

use Kode\Process\Contracts\PoolInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Exceptions\WorkerException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Worker 进程池
 * 
 * 管理一组 Worker 进程，提供负载均衡和自动伸缩
 */
class WorkerPool implements PoolInterface
{
    private string $strategy = PoolInterface::STRATEGY_ROUND_ROBIN;

    private array $workers = [];

    private WorkerFactory $factory;

    private LoggerInterface $logger;

    private int $initialCount;

    private ?\Closure $workerCallback = null;

    private int $roundRobinIndex = 0;

    private array $weights = [];

    private bool $started = false;

    public function __construct(int $workerCount, ?WorkerFactory $factory = null, ?LoggerInterface $logger = null)
    {
        $this->initialCount = $workerCount;
        $this->factory = $factory ?? new WorkerFactory($logger);
        $this->logger = $logger ?? new NullLogger();
    }

    public function start(int $workerCount = 0): void
    {
        $count = $workerCount > 0 ? $workerCount : $this->initialCount;

        $this->logger->info('启动 Worker 池', ['count' => $count]);

        $workers = $this->factory->createBatch($count);

        foreach ($workers as $worker) {
            if ($this->workerCallback !== null) {
                $worker->setCallback($this->workerCallback);
            }

            $worker->start();
            $this->workers[$worker->getId()] = $worker;
            $this->weights[$worker->getId()] = 1;
        }

        $this->started = true;

        $this->logger->info('Worker 池已启动', ['total' => count($this->workers)]);
    }

    public function stop(bool $graceful = true): void
    {
        $this->logger->info('停止 Worker 池', ['graceful' => $graceful]);

        foreach ($this->workers as $worker) {
            $worker->stop($graceful);
        }

        $this->workers = [];
        $this->weights = [];
        $this->started = false;

        $this->logger->info('Worker 池已停止');
    }

    public function restart(): void
    {
        $this->logger->info('重启 Worker 池');

        $this->stop(true);

        usleep(100000);

        $this->start();
    }

    public function reload(): void
    {
        $this->logger->info('重新加载 Worker 池');

        foreach ($this->workers as $worker) {
            $worker->reload();
        }
    }

    public function scale(int $targetCount): void
    {
        $currentCount = count($this->workers);

        if ($targetCount > $currentCount) {
            $this->addWorkers($targetCount - $currentCount);
        } elseif ($targetCount < $currentCount) {
            $this->removeWorkers($currentCount - $targetCount);
        }
    }

    public function addWorker(): WorkerInterface
    {
        $worker = $this->factory->create();

        if ($this->workerCallback !== null) {
            $worker->setCallback($this->workerCallback);
        }

        $worker->start();
        $this->workers[$worker->getId()] = $worker;
        $this->weights[$worker->getId()] = 1;

        $this->logger->info('Worker 已添加', ['worker_id' => $worker->getId()]);

        return $worker;
    }

    private function addWorkers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addWorker();
        }
    }

    public function removeWorker(): ?WorkerInterface
    {
        $worker = $this->selectWorker(PoolInterface::STRATEGY_LEAST_LOAD);

        if ($worker === null) {
            return null;
        }

        $worker->stop(true);
        unset($this->workers[$worker->getId()]);
        unset($this->weights[$worker->getId()]);

        $this->logger->info('Worker 已移除', ['worker_id' => $worker->getId()]);

        return $worker;
    }

    private function removeWorkers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->removeWorker();
        }
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    public function getActiveWorkerCount(): int
    {
        $count = 0;

        foreach ($this->workers as $worker) {
            if ($worker->getStatus() !== WorkerInterface::STATUS_FREE) {
                $count++;
            }
        }

        return $count;
    }

    public function getIdleWorkerCount(): int
    {
        $count = 0;

        foreach ($this->workers as $worker) {
            if ($worker->getStatus() === WorkerInterface::STATUS_FREE) {
                $count++;
            }
        }

        return $count;
    }

    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function getWorker(int $id): ?WorkerInterface
    {
        return $this->workers[$id] ?? null;
    }

    public function selectWorker(string $strategy = PoolInterface::STRATEGY_ROUND_ROBIN): ?WorkerInterface
    {
        if (empty($this->workers)) {
            return null;
        }

        return match ($strategy) {
            PoolInterface::STRATEGY_ROUND_ROBIN => $this->selectRoundRobin(),
            PoolInterface::STRATEGY_LEAST_CONNECTIONS => $this->selectLeastConnections(),
            PoolInterface::STRATEGY_LEAST_LOAD => $this->selectLeastLoad(),
            PoolInterface::STRATEGY_RANDOM => $this->selectRandom(),
            PoolInterface::STRATEGY_WEIGHTED => $this->selectWeighted(),
            default => $this->selectRoundRobin(),
        };
    }

    private function selectRoundRobin(): ?WorkerInterface
    {
        $ids = array_keys($this->workers);

        if (empty($ids)) {
            return null;
        }

        $this->roundRobinIndex = ($this->roundRobinIndex + 1) % count($ids);

        return $this->workers[$ids[$this->roundRobinIndex]];
    }

    private function selectLeastConnections(): ?WorkerInterface
    {
        $selected = null;
        $minConnections = PHP_INT_MAX;

        foreach ($this->workers as $worker) {
            $connections = $worker->getCurrentConnections();

            if ($connections < $minConnections) {
                $minConnections = $connections;
                $selected = $worker;
            }
        }

        return $selected;
    }

    private function selectLeastLoad(): ?WorkerInterface
    {
        $selected = null;
        $minLoad = PHP_FLOAT_MAX;

        foreach ($this->workers as $worker) {
            $load = $worker->getLoad();

            if ($load < $minLoad) {
                $minLoad = $load;
                $selected = $worker;
            }
        }

        return $selected;
    }

    private function selectRandom(): ?WorkerInterface
    {
        $ids = array_keys($this->workers);

        if (empty($ids)) {
            return null;
        }

        $randomId = $ids[array_rand($ids)];

        return $this->workers[$randomId];
    }

    private function selectWeighted(): ?WorkerInterface
    {
        $totalWeight = array_sum($this->weights);

        if ($totalWeight <= 0) {
            return $this->selectRoundRobin();
        }

        $random = mt_rand(1, $totalWeight);
        $current = 0;

        foreach ($this->workers as $id => $worker) {
            $current += $this->weights[$id] ?? 1;

            if ($random <= $current) {
                return $worker;
            }
        }

        return $this->selectRoundRobin();
    }

    public function getTotalProcessed(): int
    {
        $total = 0;

        foreach ($this->workers as $worker) {
            $total += $worker->getProcessedCount();
        }

        return $total;
    }

    public function getTotalErrors(): int
    {
        $total = 0;

        foreach ($this->workers as $worker) {
            $total += $worker->getErrorCount();
        }

        return $total;
    }

    public function getAverageLoad(): float
    {
        if (empty($this->workers)) {
            return 0.0;
        }

        $totalLoad = 0.0;

        foreach ($this->workers as $worker) {
            $totalLoad += $worker->getLoad();
        }

        return $totalLoad / count($this->workers);
    }

    public function setStrategy(string $strategy): void
    {
        $this->strategy = $strategy;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function setWorkerCallback(callable $callback): void
    {
        $this->workerCallback = $callback;
    }

    public function setWorkerWeight(int $workerId, int $weight): void
    {
        if (isset($this->workers[$workerId])) {
            $this->weights[$workerId] = max(1, $weight);
        }
    }

    public function getWorkerWeight(int $workerId): int
    {
        return $this->weights[$workerId] ?? 1;
    }

    public function checkHealth(): array
    {
        $health = [
            'total' => count($this->workers),
            'healthy' => 0,
            'unhealthy' => 0,
            'details' => [],
        ];

        foreach ($this->workers as $worker) {
            $isHealthy = $worker->isRunning() && $worker->getStatus() !== WorkerInterface::STATUS_OVERLOADED;

            if ($isHealthy) {
                $health['healthy']++;
            } else {
                $health['unhealthy']++;
            }

            $health['details'][$worker->getId()] = [
                'healthy' => $isHealthy,
                'state' => $worker->getState(),
                'status' => $worker->getStatus(),
            ];
        }

        return $health;
    }

    public function restartUnhealthy(): int
    {
        $restarted = 0;

        foreach ($this->workers as $worker) {
            if (!$worker->isRunning()) {
                $worker->start();
                $restarted++;
            }
        }

        if ($restarted > 0) {
            $this->logger->info('重启不健康的 Worker', ['count' => $restarted]);
        }

        return $restarted;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function getStats(): array
    {
        return [
            'total_workers' => $this->getWorkerCount(),
            'active_workers' => $this->getActiveWorkerCount(),
            'idle_workers' => $this->getIdleWorkerCount(),
            'total_processed' => $this->getTotalProcessed(),
            'total_errors' => $this->getTotalErrors(),
            'average_load' => $this->getAverageLoad(),
            'strategy' => $this->strategy,
        ];
    }
}
