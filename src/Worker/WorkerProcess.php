<?php

declare(strict_types=1);

namespace Kode\Process\Worker;

use Kode\Process\Contracts\ProcessInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Exceptions\WorkerException;
use Kode\Process\Signal\SignalDispatcher;
use Kode\Process\Signal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Worker 进程
 * 
 * 表示一个工作进程，负责执行具体任务
 */
class WorkerProcess implements WorkerInterface
{
    private int $id;

    private string $state = ProcessInterface::STATE_IDLE;

    private string $status = WorkerInterface::STATUS_FREE;

    private int $pid = 0;

    private int $parentPid = 0;

    private float $startTime = 0.0;

    private LoggerInterface $logger;

    private SignalDispatcher $signalDispatcher;

    private ?\Closure $callback = null;

    private int $processedCount = 0;

    private int $errorCount = 0;

    private ?string $currentTask = null;

    private int $maxConnections = 1000;

    private int $currentConnections = 0;

    private float $load = 0.0;

    private float $lastHeartbeat = 0.0;

    private float $heartbeatTimeout = 30.0;

    private array $ipcChannels = [];

    private bool $running = false;

    private int $maxRequests = 10000;

    private int $maxMemory = 128 * 1024 * 1024;

    public function __construct(int $id, ?LoggerInterface $logger = null)
    {
        $this->id = $id;
        $this->logger = $logger ?? new NullLogger();
        $this->signalDispatcher = new SignalDispatcher($this->logger);
        $this->parentPid = posix_getppid();
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            throw ProcessException::processAlreadyRunning($this->pid);
        }

        $this->state = ProcessInterface::STATE_STARTING;

        $pid = pcntl_fork();

        if ($pid < 0) {
            throw ProcessException::forkFailed();
        }

        if ($pid === 0) {
            $this->runWorkerProcess();
            exit(0);
        }

        $this->pid = $pid;
        $this->startTime = microtime(true);
        $this->state = ProcessInterface::STATE_RUNNING;
        $this->status = WorkerInterface::STATUS_FREE;
        $this->lastHeartbeat = microtime(true);

        $this->logger->info('Worker 进程已启动', [
            'worker_id' => $this->id,
            'pid' => $this->pid
        ]);
    }

    private function runWorkerProcess(): void
    {
        $this->prepareWorker();

        $this->setupWorkerSignals();

        $this->logger->info('Worker 进程运行中', [
            'worker_id' => $this->id,
            'pid' => $this->pid
        ]);

        $this->runEventLoop();
    }

    /**
     * 在子进程（fork 之后）内初始化进程身份。
     *
     * 关键：必须在 fork 之后、进入事件循环之前把 parentPid 重置为「真正父进程」
     * （master）的 pid。构造函数在 master 上下文运行，记录的 parentPid 是 master 的
     * 父进程（如启动 shell）；若不在此重设，checkParent() 每轮都会误判父进程已退出，
     * 子进程立即自杀并以退出码 0 触发无限重生（fork bomb）。
     *
     * 抽成方法便于在不实际 fork 的情况下用反射做单元测试。
     */
    protected function prepareWorker(): void
    {
        $this->pid = posix_getpid();
        $this->parentPid = posix_getppid();
        $this->state = ProcessInterface::STATE_RUNNING;
        $this->running = true;
    }

    private function setupWorkerSignals(): void
    {
        $this->signalDispatcher->on(Signal::TERM, function () {
            $this->stop(true);
        });

        $this->signalDispatcher->on(Signal::INT, function () {
            $this->stop(true);
        });

        $this->signalDispatcher->on(Signal::QUIT, function () {
            $this->stop(false);
        });

        $this->signalDispatcher->on(Signal::USR1, function () {
            $this->reload();
        });

        $this->signalDispatcher->on(Signal::USR2, function () {
            $this->dumpStatus();
        });

        $this->signalDispatcher->ignore(Signal::PIPE);
    }

    private function runEventLoop(): void
    {
        while ($this->running) {
            pcntl_signal_dispatch();

            $this->checkParent();

            $this->checkResources();

            $this->processTasks();

            $this->updateHeartbeat();

            usleep(10000);
        }

        $this->cleanup();
    }

    private function checkParent(): void
    {
        if (posix_getppid() !== $this->parentPid) {
            $this->logger->warning('父进程已退出，Worker 准备退出');
            $this->stop(true);
        }
    }

    private function checkResources(): void
    {
        if ($this->processedCount >= $this->maxRequests) {
            $this->logger->info('Worker 达到最大请求数，准备退出');
            $this->stop(true);
        }

        if (memory_get_usage(true) > $this->maxMemory) {
            $this->logger->warning('Worker 内存超限，准备退出');
            $this->stop(true);
        }
    }

    private function processTasks(): void
    {
        if ($this->callback === null) {
            return;
        }

        if ($this->status === WorkerInterface::STATUS_BUSY) {
            return;
        }

        $this->status = WorkerInterface::STATUS_FREE;
    }

    private function updateHeartbeat(): void
    {
        $this->lastHeartbeat = microtime(true);
    }

    private function cleanup(): void
    {
        foreach ($this->ipcChannels as $channel) {
            if (is_resource($channel)) {
                fclose($channel);
            }
        }

        $this->logger->info('Worker 进程清理完成', ['worker_id' => $this->id]);
    }

    public function stop(bool $graceful = true): void
    {
        if (!$this->running) {
            return;
        }

        $this->state = ProcessInterface::STATE_STOPPING;
        $this->logger->info('Worker 停止中...', [
            'worker_id' => $this->id,
            'graceful' => $graceful
        ]);

        if ($graceful) {
            $timeout = 10;
            $start = microtime(true);

            while ($this->status === WorkerInterface::STATUS_BUSY && (microtime(true) - $start) < $timeout) {
                usleep(100000);
            }
        }

        $this->running = false;
        $this->state = ProcessInterface::STATE_STOPPED;

        $this->logger->info('Worker 已停止', ['worker_id' => $this->id]);
    }

    public function restart(): void
    {
        $this->stop(true);
        $this->start();
    }

    public function reload(): void
    {
        $this->logger->info('Worker 重新加载配置', ['worker_id' => $this->id]);
    }

    private function dumpStatus(): void
    {
        $status = [
            'worker_id' => $this->id,
            'pid' => $this->pid,
            'state' => $this->state,
            'status' => $this->status,
            'processed' => $this->processedCount,
            'errors' => $this->errorCount,
            'memory' => memory_get_usage(true),
            'load' => $this->load,
        ];

        $this->logger->info('Worker 状态', $status);
    }

    public function setCallback(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function setMaxRequests(int $maxRequests): void
    {
        $this->maxRequests = $maxRequests;
    }

    public function setMaxMemory(int $maxMemory): void
    {
        $this->maxMemory = $maxMemory;
    }

    public function setMaxConnections(int $maxConnections): void
    {
        $this->maxConnections = $maxConnections;
    }

    public function setHeartbeatTimeout(float $timeout): void
    {
        $this->heartbeatTimeout = $timeout;
    }

    public function addIpcChannel($channel): void
    {
        $this->ipcChannels[] = $channel;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getCurrentTask(): ?string
    {
        return $this->currentTask;
    }

    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    public function getCurrentConnections(): int
    {
        return $this->currentConnections;
    }

    public function getLoad(): float
    {
        return $this->load;
    }

    public function heartbeat(): array
    {
        $now = microtime(true);
        $overdue = ($now - $this->lastHeartbeat) > $this->heartbeatTimeout;

        return [
            'worker_id' => $this->id,
            'pid' => $this->pid,
            'state' => $this->state,
            'status' => $this->status,
            'last_heartbeat' => $this->lastHeartbeat,
            'overdue' => $overdue,
            'processed' => $this->processedCount,
            'errors' => $this->errorCount,
            'memory' => memory_get_usage(true),
            'load' => $this->load,
        ];
    }

    public function assignTask(string $taskId, array $data): void
    {
        if ($this->status !== WorkerInterface::STATUS_FREE) {
            throw WorkerException::workerOverloaded($this->id, $this->load);
        }

        $this->currentTask = $taskId;
        $this->status = WorkerInterface::STATUS_BUSY;

        try {
            if ($this->callback !== null) {
                ($this->callback)($taskId, $data);
            }

            $this->processedCount++;
        } catch (\Throwable $e) {
            $this->errorCount++;
            $this->logger->error('任务执行失败', [
                'worker_id' => $this->id,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
        } finally {
            $this->currentTask = null;
            $this->status = WorkerInterface::STATUS_FREE;
        }
    }

    public function completeTask(string $taskId, mixed $result): void
    {
        $this->processedCount++;
        $this->currentTask = null;
        $this->status = WorkerInterface::STATUS_FREE;

        $this->logger->debug('任务完成', [
            'worker_id' => $this->id,
            'task_id' => $taskId
        ]);
    }

    public function failTask(string $taskId, \Throwable $error): void
    {
        $this->errorCount++;
        $this->currentTask = null;
        $this->status = WorkerInterface::STATUS_FREE;

        $this->logger->error('任务失败', [
            'worker_id' => $this->id,
            'task_id' => $taskId,
            'error' => $error->getMessage()
        ]);
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getPid(): int
    {
        return $this->pid ?: posix_getpid();
    }

    public function isRunning(): bool
    {
        if ($this->pid === 0) {
            return false;
        }

        // 仅做存活探测，不得回收退出状态：子进程回收是 MasterProcess::reapChildren
        // 的唯一出口。若此处用 pcntl_waitpid(WNOHANG) 抢收，checkWorkers() 每 tick 调用
        // 后会架空 SIGCHLD 驱动的回收与自动重生，handleWorkerExit 永不触发；且 pid 复用
        // 时可能误杀无关进程。posix_kill(pid, 0) 只探测是否存活、不回收。
        return @posix_kill($this->pid, 0);
    }

    public function isMaster(): bool
    {
        return false;
    }

    public function isWorker(): bool
    {
        return true;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    public function getCpuUsage(): float
    {
        $usage = getrusage();
        return ($usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1000000) +
               ($usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1000000);
    }

    public function updateLoad(float $load): void
    {
        $this->load = $load;

        if ($load > 0.9) {
            $this->status = WorkerInterface::STATUS_OVERLOADED;
        } elseif ($load > 0.5) {
            $this->status = WorkerInterface::STATUS_BUSY;
        } else {
            $this->status = WorkerInterface::STATUS_FREE;
        }
    }
}
