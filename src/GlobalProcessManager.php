<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Contracts\ProcessInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Protocol\ProtocolManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class GlobalProcessManager
{
    private static ?self $instance = null;

    public const STATE_IDLE = 'idle';
    public const STATE_STARTING = 'starting';
    public const STATE_RUNNING = 'running';
    public const STATE_STOPPING = 'stopping';
    public const STATE_STOPPED = 'stopped';
    public const STATE_RELOADING = 'reloading';

    private string $state = self::STATE_IDLE;
    private int $masterPid = 0;
    private array $workers = [];
    private array $processes = [];
    private array $callbacks = [];
    private array $config = [];
    private array $stats = [];
    private float $startTime = 0.0;
    private ?LoggerInterface $logger = null;
    private ?ProtocolManager $protocolManager = null;
    private array $sharedMemory = [];
    private array $services = [];
    private array $channels = [];

    private function __construct()
    {
        $this->startTime = microtime(true);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function init(array $config = [], ?LoggerInterface $logger = null): self
    {
        $instance = self::getInstance();
        $instance->config = array_merge($instance->config, $config);
        $instance->logger = $logger ?? new NullLogger();
        $instance->protocolManager = ProtocolManager::getInstance();

        return $instance;
    }

    public function setMasterPid(int $pid): self
    {
        $this->masterPid = $pid;
        return $this;
    }

    public function getMasterPid(): int
    {
        return $this->masterPid;
    }

    public function isMaster(): bool
    {
        return $this->masterPid === Process::getPid();
    }

    public function isWorker(): bool
    {
        return !$this->isMaster() && $this->getCurrentWorker() !== null;
    }

    public function registerWorker(WorkerInterface $worker): self
    {
        $this->workers[$worker->getId()] = $worker;
        $this->processes[$worker->getPid()] = $worker;

        $this->logger?->debug('注册 Worker', [
            'id' => $worker->getId(),
            'pid' => $worker->getPid(),
        ]);

        return $this;
    }

    public function unregisterWorker(int $workerId): self
    {
        if (isset($this->workers[$workerId])) {
            $worker = $this->workers[$workerId];
            unset($this->processes[$worker->getPid()]);
            unset($this->workers[$workerId]);
        }

        return $this;
    }

    public function getWorker(int $id): ?WorkerInterface
    {
        return $this->workers[$id] ?? null;
    }

    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    public function getCurrentWorker(): ?WorkerInterface
    {
        $currentPid = Process::getPid();

        foreach ($this->workers as $worker) {
            if ($worker->getPid() === $currentPid) {
                return $worker;
            }
        }

        return null;
    }

    public function registerProcess(ProcessInterface $process): self
    {
        $this->processes[$process->getPid()] = $process;
        return $this;
    }

    public function unregisterProcess(int $pid): self
    {
        unset($this->processes[$pid]);
        return $this;
    }

    public function getProcess(int $pid): ?ProcessInterface
    {
        return $this->processes[$pid] ?? null;
    }

    public function getProcesses(): array
    {
        return $this->processes;
    }

    public function setState(string $state): self
    {
        $oldState = $this->state;
        $this->state = $state;

        $this->logger?->info('状态变更', [
            'from' => $oldState,
            'to' => $state,
        ]);

        if (isset($this->callbacks['onStateChange'])) {
            ($this->callbacks['onStateChange'])($oldState, $state);
        }

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isRunning(): bool
    {
        return in_array($this->state, [self::STATE_RUNNING, self::STATE_STARTING], true);
    }

    public function isStopped(): bool
    {
        return in_array($this->state, [self::STATE_STOPPED, self::STATE_IDLE], true);
    }

    public function onStateChange(callable $callback): self
    {
        $this->callbacks['onStateChange'] = $callback(...);
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->callbacks['onWorkerStart'] = $callback(...);
        return $this;
    }

    public function onWorkerStop(callable $callback): self
    {
        $this->callbacks['onWorkerStop'] = $callback(...);
        return $this;
    }

    public function onWorkerError(callable $callback): self
    {
        $this->callbacks['onWorkerError'] = $callback(...);
        return $this;
    }

    public function triggerWorkerStart(WorkerInterface $worker): void
    {
        if (isset($this->callbacks['onWorkerStart'])) {
            ($this->callbacks['onWorkerStart'])($worker);
        }
    }

    public function triggerWorkerStop(WorkerInterface $worker): void
    {
        if (isset($this->callbacks['onWorkerStop'])) {
            ($this->callbacks['onWorkerStop'])($worker);
        }
    }

    public function triggerWorkerError(WorkerInterface $worker, \Throwable $error): void
    {
        if (isset($this->callbacks['onWorkerError'])) {
            ($this->callbacks['onWorkerError'])($worker, $error);
        }
    }

    public function setConfig(string $key, mixed $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function setShared(string $key, mixed $value): self
    {
        $this->sharedMemory[$key] = $value;
        return $this;
    }

    public function getShared(string $key, mixed $default = null): mixed
    {
        return $this->sharedMemory[$key] ?? $default;
    }

    public function hasShared(string $key): bool
    {
        return array_key_exists($key, $this->sharedMemory);
    }

    public function removeShared(string $key): self
    {
        unset($this->sharedMemory[$key]);
        return $this;
    }

    public function getSharedAll(): array
    {
        return $this->sharedMemory;
    }

    public function registerService(string $name, object $service): self
    {
        $this->services[$name] = $service;
        return $this;
    }

    public function getService(string $name): ?object
    {
        return $this->services[$name] ?? null;
    }

    public function hasService(string $name): bool
    {
        return isset($this->services[$name]);
    }

    public function createChannel(string $name): self
    {
        if (!isset($this->channels[$name])) {
            $this->channels[$name] = [];
        }

        return $this;
    }

    public function publish(string $channel, mixed $message): self
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        $this->channels[$channel][] = [
            'data' => $message,
            'time' => microtime(true),
        ];

        return $this;
    }

    public function subscribe(string $channel, callable $handler): self
    {
        $this->createChannel($channel);

        $key = spl_object_id((object) $handler);
        $this->channels[$channel]['_handlers'][$key] = $handler;

        return $this;
    }

    public function getChannelMessages(string $channel): array
    {
        $messages = $this->channels[$channel] ?? [];

        unset($messages['_handlers']);

        return $messages;
    }

    public function increment(string $key, int $amount = 1): int
    {
        $current = (int) ($this->sharedMemory[$key] ?? 0);
        $this->sharedMemory[$key] = $current + $amount;

        return $this->sharedMemory[$key];
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    public function record(string $metric, float $value): self
    {
        if (!isset($this->stats[$metric])) {
            $this->stats[$metric] = [
                'count' => 0,
                'sum' => 0.0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN,
                'avg' => 0.0,
            ];
        }

        $this->stats[$metric]['count']++;
        $this->stats[$metric]['sum'] += $value;
        $this->stats[$metric]['min'] = min($this->stats[$metric]['min'], $value);
        $this->stats[$metric]['max'] = max($this->stats[$metric]['max'], $value);
        $this->stats[$metric]['avg'] = $this->stats[$metric]['sum'] / $this->stats[$metric]['count'];

        return $this;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function getStat(string $metric): ?array
    {
        return $this->stats[$metric] ?? null;
    }

    public function getUptime(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getProtocolManager(): ?ProtocolManager
    {
        return $this->protocolManager;
    }

    public function getStatus(): array
    {
        return [
            'state' => $this->state,
            'master_pid' => $this->masterPid,
            'current_pid' => Process::getPid(),
            'is_master' => $this->isMaster(),
            'is_worker' => $this->isWorker(),
            'worker_count' => count($this->workers),
            'process_count' => count($this->processes),
            'uptime' => $this->getUptime(),
            'start_time' => $this->startTime,
            'shared_keys' => count($this->sharedMemory),
            'services' => array_keys($this->services),
            'channels' => array_keys($this->channels),
            'stats' => array_keys($this->stats),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    public function broadcast(string $event, array $data = []): self
    {
        $message = json_encode([
            'event' => $event,
            'data' => $data,
            'time' => microtime(true),
            'from' => Process::getPid(),
        ]);

        foreach ($this->workers as $worker) {
            if ($worker->getPid() !== Process::getPid()) {
                $this->logger?->debug('广播消息到 Worker', [
                    'worker_id' => $worker->getId(),
                    'event' => $event,
                ]);
            }
        }

        return $this;
    }

    public function reset(): void
    {
        $this->workers = [];
        $this->processes = [];
        $this->callbacks = [];
        $this->sharedMemory = [];
        $this->stats = [];
        $this->channels = [];
        $this->state = self::STATE_IDLE;
        $this->masterPid = 0;
    }

    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->reset();
        }

        self::$instance = null;
    }
}
