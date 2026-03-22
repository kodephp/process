<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Master\ProcessManager;
use Kode\Process\Monitor\ProcessMonitor;
use Kode\Process\Signal\SignalDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Server - 简洁的多进程服务器入口
 * 
 * 更简洁的 API，类似 Workerman/Swoole 的使用方式
 */
class Server
{
    private array $config = [
        'worker_count' => 4,
        'min_workers' => 2,
        'max_workers' => 16,
        'auto_scale' => true,
        'daemonize' => false,
        'pid_file' => null,
        'log_file' => null,
        'user' => null,
        'group' => null,
    ];

    private ?\Closure $onWorkerStart = null;
    private ?\Closure $onWorkerStop = null;
    private ?\Closure $onTask = null;
    private ?\Closure $onMasterStart = null;
    private ?\Closure $onMasterStop = null;
    private ?\Closure $onReload = null;

    private LoggerInterface $logger;
    private ?ProcessManager $manager = null;
    private ?ProcessMonitor $monitor = null;
    private ?SignalDispatcher $dispatcher = null;

    private bool $started = false;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge($this->config, $config);
        $this->logger = $logger ?? new NullLogger();
    }

    public static function create(array $config = [], ?LoggerInterface $logger = null): self
    {
        return new self($config, $logger);
    }

    public function count(int $count): self
    {
        $this->config['worker_count'] = $count;
        return $this;
    }

    public function minCount(int $count): self
    {
        $this->config['min_workers'] = $count;
        return $this;
    }

    public function maxCount(int $count): self
    {
        $this->config['max_workers'] = $count;
        return $this;
    }

    public function autoScale(bool $enable = true): self
    {
        $this->config['auto_scale'] = $enable;
        return $this;
    }

    public function daemon(bool $enable = true): self
    {
        $this->config['daemonize'] = $enable;
        return $this;
    }

    public function pidFile(string $path): self
    {
        $this->config['pid_file'] = $path;
        return $this;
    }

    public function logFile(string $path): self
    {
        $this->config['log_file'] = $path;
        return $this;
    }

    public function runAs(string $user, ?string $group = null): self
    {
        $this->config['user'] = $user;
        $this->config['group'] = $group;
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->onWorkerStart = $callback(...);
        return $this;
    }

    public function onWorkerStop(callable $callback): self
    {
        $this->onWorkerStop = $callback(...);
        return $this;
    }

    public function onTask(callable $callback): self
    {
        $this->onTask = $callback(...);
        return $this;
    }

    public function onMasterStart(callable $callback): self
    {
        $this->onMasterStart = $callback(...);
        return $this;
    }

    public function onMasterStop(callable $callback): self
    {
        $this->onMasterStop = $callback(...);
        return $this;
    }

    public function onReload(callable $callback): self
    {
        $this->onReload = $callback(...);
        return $this;
    }

    public function start(): void
    {
        if ($this->started) {
            throw ProcessException::processAlreadyRunning(Process::getPid());
        }

        $this->started = true;

        $this->setupSignalHandlers();
        $this->setupMonitor();

        $this->manager = new ProcessManager($this->config, $this->logger);

        if ($this->onMasterStart !== null) {
            ($this->onMasterStart)();
        }

        $workerCallback = function ($taskId, $data) {
            if ($this->onTask !== null) {
                return ($this->onTask)($taskId, $data);
            }
            return null;
        };

        $masterCallback = function () {
            if ($this->onReload !== null) {
                ($this->onReload)();
            }
        };

        $this->manager->start($workerCallback, $masterCallback);
    }

    public function stop(bool $graceful = true): void
    {
        if (!$this->started || $this->manager === null) {
            return;
        }

        if ($this->onMasterStop !== null) {
            ($this->onMasterStop)();
        }

        $this->manager->stop($graceful);

        if ($this->monitor !== null) {
            $this->monitor->stop();
        }

        $this->started = false;
    }

    public function restart(): void
    {
        $this->stop(true);
        usleep(100000);
        $this->start();
    }

    public function reload(): void
    {
        if ($this->manager !== null) {
            $this->manager->reload();
        }

        if ($this->onReload !== null) {
            ($this->onReload)();
        }
    }

    public function scale(int $count): void
    {
        if ($this->manager !== null) {
            $this->manager->scale($count);
        }
    }

    public function getWorkers(): array
    {
        return $this->manager?->getWorkers() ?? [];
    }

    public function getWorker(int $id): ?WorkerInterface
    {
        return $this->manager?->getWorker($id);
    }

    public function getWorkerCount(): int
    {
        return $this->manager?->getWorkerCount() ?? 0;
    }

    public function getStatus(): array
    {
        return $this->manager?->getStatus() ?? [];
    }

    public function checkHealth(): array
    {
        return $this->monitor?->checkAll() ?? [];
    }

    public function isRunning(): bool
    {
        return $this->started && ($this->manager?->isRunning() ?? false);
    }

    public function getManager(): ?ProcessManager
    {
        return $this->manager;
    }

    public function getMonitor(): ?ProcessMonitor
    {
        return $this->monitor;
    }

    public function getDispatcher(): ?SignalDispatcher
    {
        return $this->dispatcher;
    }

    private function setupSignalHandlers(): void
    {
        $this->dispatcher = new SignalDispatcher($this->logger);

        $this->dispatcher->on(Signal::TERM, function () {
            $this->logger->info('收到 SIGTERM，准备停止');
            $this->stop(true);
        });

        $this->dispatcher->on(Signal::INT, function () {
            $this->logger->info('收到 SIGINT，准备停止');
            $this->stop(true);
        });

        $this->dispatcher->on(Signal::QUIT, function () {
            $this->logger->info('收到 SIGQUIT，强制停止');
            $this->stop(false);
        });

        $this->dispatcher->on(Signal::HUP, function () {
            $this->logger->info('收到 SIGHUP，重新加载');
            $this->reload();
        });

        $this->dispatcher->on(Signal::USR1, function () {
            $this->logger->info('收到 SIGUSR1，重新加载');
            $this->reload();
        });

        $this->dispatcher->on(Signal::USR2, function () {
            $this->logger->info('收到 SIGUSR2，打印状态');
            print_r($this->getStatus());
        });
    }

    private function setupMonitor(): void
    {
        $this->monitor = new ProcessMonitor($this->logger);

        $this->monitor->onUnhealthy(function ($pid, $status) {
            $this->logger->warning('进程不健康', ['pid' => $pid, 'status' => $status]);
        });

        $this->monitor->onRestart(function ($pid) {
            $this->logger->info('重启进程', ['pid' => $pid]);
        });

        $this->monitor->start();
    }

    public function __destruct()
    {
        if ($this->started) {
            $this->stop(true);
        }
    }
}
