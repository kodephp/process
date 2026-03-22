<?php

declare(strict_types=1);

namespace Kode\Process\Worker;

use Kode\Process\Contracts\WorkerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Worker 工厂
 * 
 * 负责创建 Worker 进程实例
 */
class WorkerFactory
{
    private LoggerInterface $logger;

    private int $nextId = 1;

    private array $defaults = [
        'max_requests' => 10000,
        'max_memory' => 128 * 1024 * 1024,
        'max_connections' => 1000,
        'heartbeat_timeout' => 30.0,
    ];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function create(array $config = []): WorkerInterface
    {
        $id = $config['id'] ?? $this->nextId++;

        $worker = new WorkerProcess($id, $this->logger);

        $maxRequests = $config['max_requests'] ?? $this->defaults['max_requests'];
        $worker->setMaxRequests($maxRequests);

        $maxMemory = $config['max_memory'] ?? $this->defaults['max_memory'];
        $worker->setMaxMemory($maxMemory);

        $maxConnections = $config['max_connections'] ?? $this->defaults['max_connections'];
        $worker->setMaxConnections($maxConnections);

        $heartbeatTimeout = $config['heartbeat_timeout'] ?? $this->defaults['heartbeat_timeout'];
        $worker->setHeartbeatTimeout($heartbeatTimeout);

        if (isset($config['callback'])) {
            $worker->setCallback($config['callback']);
        }

        $this->logger->debug('Worker 实例已创建', ['worker_id' => $id]);

        return $worker;
    }

    public function createBatch(int $count, array $config = []): array
    {
        $workers = [];

        for ($i = 0; $i < $count; $i++) {
            $workerConfig = array_merge($config, ['id' => $this->nextId++]);
            $workers[] = $this->create($workerConfig);
        }

        $this->logger->info('批量创建 Worker 完成', ['count' => $count]);

        return $workers;
    }

    public function setDefaults(array $defaults): void
    {
        $this->defaults = array_merge($this->defaults, $defaults);
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }

    public function resetIdCounter(): void
    {
        $this->nextId = 1;
    }

    public function getNextId(): int
    {
        return $this->nextId;
    }
}
