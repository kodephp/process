<?php

declare(strict_types=1);

namespace Kode\Process\Master;

use Kode\Process\Contracts\ProcessInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Signal\SignalDispatcher;
use Kode\Process\Signal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Master 进程管理器
 * 
 * 负责 Master-Worker 模型中的主进程管理，包括：
 * - 端口监听
 * - 信号处理
 * - 日志轮转
 * - Worker 进程管理
 */
class MasterProcess implements ProcessInterface
{
    private string $state = ProcessInterface::STATE_IDLE;

    private int $pid = 0;

    private float $startTime = 0.0;

    private LoggerInterface $logger;

    private SignalDispatcher $signalDispatcher;

    private array $workers = [];

    private array $config;

    private ?int $serverSocket = null;

    private ?string $pidFile = null;

    private ?string $logFile = null;

    private bool $daemonize = false;

    private array $callbacks = [];

    private bool $running = false;

    private float $heartbeatInterval = 5.0;

    private float $lastHeartbeat = 0.0;

    private int $maxRequests = 10000;

    private int $processedRequests = 0;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'worker_count' => 4,
            'max_requests' => 10000,
            'heartbeat_interval' => 5.0,
            'pid_file' => sys_get_temp_dir() . '/kode-process.pid',
            'log_file' => sys_get_temp_dir() . '/kode-process.log',
            'daemonize' => false,
            'user' => null,
            'group' => null,
            'chroot' => null,
            'max_memory' => 512 * 1024 * 1024,
            'graceful_timeout' => 30,
        ], $config);

        $this->logger = $logger ?? new NullLogger();
        $this->signalDispatcher = new SignalDispatcher($this->logger);
        $this->pidFile = $this->config['pid_file'];
        $this->logFile = $this->config['log_file'];
        $this->daemonize = $this->config['daemonize'];
        $this->maxRequests = $this->config['max_requests'];
        $this->heartbeatInterval = $this->config['heartbeat_interval'];
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            throw ProcessException::processAlreadyRunning($this->pid);
        }

        $this->state = ProcessInterface::STATE_STARTING;
        $this->logger->info('Master 进程启动中...');

        if ($this->daemonize) {
            $this->daemonize();
        }

        $this->pid = posix_getpid();
        $this->startTime = microtime(true);
        $this->running = true;

        $this->writePidFile();
        $this->registerSignalHandlers();
        $this->setupServerSocket();

        $this->state = ProcessInterface::STATE_RUNNING;
        $this->logger->info('Master 进程已启动', ['pid' => $this->pid]);

        $this->runEventLoop();
    }

    public function stop(bool $graceful = true): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $this->state = ProcessInterface::STATE_STOPPING;
        $this->logger->info('Master 进程停止中...', ['graceful' => $graceful]);

        $this->stopWorkers($graceful);

        $this->closeServerSocket();
        $this->removePidFile();

        $this->running = false;
        $this->state = ProcessInterface::STATE_STOPPED;

        $this->logger->info('Master 进程已停止');
    }

    public function restart(): void
    {
        $this->logger->info('Master 进程重启中...');

        $this->stop(true);

        usleep(100000);

        $this->start();
    }

    public function reload(): void
    {
        $this->logger->info('重新加载配置...');

        foreach ($this->workers as $worker) {
            if ($worker instanceof WorkerInterface) {
                posix_kill($worker->getPid(), Signal::USR1);
            }
        }

        $this->logger->info('配置重载信号已发送到所有 Worker');
    }

    private function daemonize(): void
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            throw ProcessException::forkFailed('无法创建守护进程');
        }

        if ($pid > 0) {
            exit(0);
        }

        posix_setsid();

        $pid = pcntl_fork();

        if ($pid < 0) {
            throw ProcessException::forkFailed('无法创建第二个守护进程');
        }

        if ($pid > 0) {
            exit(0);
        }

        umask(0);

        if ($this->config['chroot']) {
            chroot($this->config['chroot']);
        }

        chdir('/');

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdin = fopen('/dev/null', 'r');
        $stdout = fopen($this->logFile, 'a');
        $stderr = fopen($this->logFile, 'a');

        if ($this->config['user']) {
            $user = posix_getpwnam($this->config['user']);
            if ($user) {
                posix_setuid($user['uid']);
            }
        }

        if ($this->config['group']) {
            $group = posix_getgrnam($this->config['group']);
            if ($group) {
                posix_setgid($group['gid']);
            }
        }

        $this->logger->info('守护进程模式已启用');
    }

    private function registerSignalHandlers(): void
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

        $this->signalDispatcher->on(Signal::HUP, function () {
            $this->reload();
        });

        $this->signalDispatcher->on(Signal::USR1, function () {
            $this->rotateLog();
        });

        $this->signalDispatcher->on(Signal::USR2, function () {
            $this->dumpStatus();
        });

        $this->signalDispatcher->on(Signal::CHLD, function () {
            $this->reapChildren();
        });

        $this->logger->debug('信号处理器已注册');
    }

    private function setupServerSocket(): void
    {
        if (isset($this->config['socket'])) {
            $this->serverSocket = $this->config['socket'];
            $this->logger->debug('使用现有服务器套接字');
            return;
        }

        if (!isset($this->config['port'])) {
            return;
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new ProcessException('无法创建服务器套接字: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($socket);

        $host = $this->config['host'] ?? '0.0.0.0';
        $port = $this->config['port'];

        if (!socket_bind($socket, $host, $port)) {
            throw new ProcessException(sprintf('无法绑定到 %s:%d', $host, $port));
        }

        if (!socket_listen($socket, $this->config['backlog'] ?? 1024)) {
            throw new ProcessException('无法监听套接字');
        }

        $this->serverSocket = $socket;
        $this->logger->info('服务器套接字已创建', ['host' => $host, 'port' => $port]);
    }

    private function closeServerSocket(): void
    {
        if ($this->serverSocket !== null) {
            socket_close($this->serverSocket);
            $this->serverSocket = null;
            $this->logger->debug('服务器套接字已关闭');
        }
    }

    private function runEventLoop(): void
    {
        $this->logger->info('进入事件循环');

        while ($this->running) {
            pcntl_signal_dispatch();

            $this->checkHeartbeat();

            $this->checkMemory();

            $this->checkWorkers();

            if ($this->processedRequests >= $this->maxRequests) {
                $this->logger->info('达到最大请求数，准备重启');
                $this->restart();
                break;
            }

            usleep(10000);
        }

        $this->logger->info('事件循环结束');
    }

    private function checkHeartbeat(): void
    {
        $now = microtime(true);

        if ($now - $this->lastHeartbeat < $this->heartbeatInterval) {
            return;
        }

        $this->lastHeartbeat = $now;

        foreach ($this->workers as $id => $worker) {
            if ($worker instanceof WorkerInterface) {
                $status = $worker->heartbeat();

                if (isset($status['overdue']) && $status['overdue']) {
                    $this->logger->warning('Worker 心跳超时', ['worker_id' => $id]);
                }
            }
        }

        if (isset($this->callbacks['heartbeat'])) {
            ($this->callbacks['heartbeat'])($this);
        }
    }

    private function checkMemory(): void
    {
        $memory = memory_get_usage(true);
        $maxMemory = $this->config['max_memory'];

        if ($memory > $maxMemory) {
            $this->logger->warning('内存使用超限，准备重启', [
                'current' => $memory,
                'max' => $maxMemory
            ]);
            $this->restart();
        }
    }

    private function checkWorkers(): void
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker instanceof WorkerInterface) {
                if (!$worker->isRunning()) {
                    $this->logger->warning('Worker 已停止', ['worker_id' => $id]);
                }
            }
        }
    }

    private function stopWorkers(bool $graceful): void
    {
        $timeout = $this->config['graceful_timeout'] ?? 30;
        $signal = $graceful ? Signal::TERM : Signal::KILL;

        foreach ($this->workers as $id => $worker) {
            if ($worker instanceof WorkerInterface) {
                posix_kill($worker->getPid(), $signal);
            }
        }

        if ($graceful) {
            $start = microtime(true);

            while (!empty($this->workers) && (microtime(true) - $start) < $timeout) {
                $this->reapChildren();
                usleep(100000);
            }

            foreach ($this->workers as $id => $worker) {
                if ($worker instanceof WorkerInterface) {
                    $this->logger->warning('强制终止 Worker', ['worker_id' => $id]);
                    posix_kill($worker->getPid(), Signal::KILL);
                }
            }
        }

        $this->workers = [];
    }

    private function reapChildren(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            foreach ($this->workers as $id => $worker) {
                if ($worker instanceof WorkerInterface && $worker->getPid() === $pid) {
                    $exitCode = pcntl_wexitstatus($status);
                    $this->logger->info('Worker 进程已退出', [
                        'worker_id' => $id,
                        'pid' => $pid,
                        'exit_code' => $exitCode
                    ]);

                    unset($this->workers[$id]);
                    break;
                }
            }
        }
    }

    private function rotateLog(): void
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        $backup = $this->logFile . '.' . date('YmdHis');
        rename($this->logFile, $backup);

        $this->logger->info('日志文件已轮转', ['backup' => $backup]);
    }

    private function dumpStatus(): void
    {
        $status = [
            'master' => [
                'pid' => $this->pid,
                'state' => $this->state,
                'uptime' => microtime(true) - $this->startTime,
                'memory' => memory_get_usage(true),
                'processed' => $this->processedRequests,
            ],
            'workers' => [],
        ];

        foreach ($this->workers as $id => $worker) {
            if ($worker instanceof WorkerInterface) {
                $status['workers'][$id] = [
                    'pid' => $worker->getPid(),
                    'state' => $worker->getState(),
                    'processed' => $worker->getProcessedCount(),
                    'errors' => $worker->getErrorCount(),
                ];
            }
        }

        $statusFile = $this->pidFile . '.status';
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

        $this->logger->info('状态已导出', ['file' => $statusFile]);
    }

    private function writePidFile(): void
    {
        if ($this->pidFile === null) {
            return;
        }

        $dir = dirname($this->pidFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->pidFile, $this->pid);
    }

    private function removePidFile(): void
    {
        if ($this->pidFile !== null && file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }

    public function addWorker(WorkerInterface $worker): void
    {
        $this->workers[$worker->getId()] = $worker;
        $this->logger->debug('Worker 已添加', ['worker_id' => $worker->getId()]);
    }

    public function removeWorker(int $workerId): void
    {
        unset($this->workers[$workerId]);
        $this->logger->debug('Worker 已移除', ['worker_id' => $workerId]);
    }

    public function onHeartbeat(callable $callback): void
    {
        $this->callbacks['heartbeat'] = $callback;
    }

    public function onWorkerExit(callable $callback): void
    {
        $this->callbacks['worker_exit'] = $callback;
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
        return $this->running && $this->state === ProcessInterface::STATE_RUNNING;
    }

    public function isMaster(): bool
    {
        return true;
    }

    public function isWorker(): bool
    {
        return false;
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

    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getServerSocket(): ?int
    {
        return $this->serverSocket;
    }
}
