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

    /** worker 内部按 slot 索引（Worker id 不稳定，重生需稳定槽位做崩溃计数） */
    private array $workers = [];

    /** pid => slot 反向索引，供 reapChildren O(1) 定位 */
    private array $pidToSlot = [];

    /** worker id => slot 反向索引，供 removeWorker(int $workerId) 定位 */
    private array $workerIdToSlot = [];

    /** slot => 连续异常退出次数，超过上限则停止自动重生，防 fork bomb */
    private array $restartCounts = [];

    /** 下一可用 slot */
    private int $nextSlot = 0;

    /** 单槽位允许的最大连续异常重生次数 */
    private int $maxRestartAttempts = 5;

    /** 可选：提供则 worker 异常退出时自动重生（由 ProcessManager 注入 WorkerPool 的 addWorker） */
    private ?\Closure $workerSpawner = null;

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
        $root = $config['root'] ?? $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
        $rootHash = substr(hash('xxh64', $root), 0, 8);

        $this->config = array_merge([
            'worker_count' => 4,
            'max_requests' => 10000,
            'heartbeat_interval' => 5.0,
            'pid_file' => sys_get_temp_dir() . "/kode-process-{$rootHash}.pid",
            'log_file' => sys_get_temp_dir() . '/kode-process.log',
            'daemonize' => false,
            'user' => null,
            'group' => null,
            'chroot' => null,
            'max_memory' => 512 * 1024 * 1024,
            'graceful_timeout' => 30,
            'restart_backoff_base' => 100000, // 100ms 基础退避
            'restart_backoff_max' => 5000000, // 5s 最大退避
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

        $selfPid = posix_getpid();
        $signaled = 0;
        $skipped = 0;

        foreach ($this->workers as $worker) {
            if (!$worker instanceof WorkerInterface) {
                continue;
            }

            // 热重载边界安全：仅向真正处于运行态的 worker 发送 USR1。
            // 已停止 / 从未启动的 worker 不在线，向其陈旧 pid 发送只会命中内核
            // 回收的其它进程；而 getPid() 在 pid 为 0 时回退到 master 自身 pid，
            // 若不拦截会误触发 master 的 USR1 日志轮转甚至关闭自身。
            if ($worker->getState() !== ProcessInterface::STATE_RUNNING) {
                $skipped++;
                continue;
            }

            $pid = $worker->getPid();
            if ($pid <= 0 || $pid === $selfPid) {
                $skipped++;
                continue;
            }

            if ($this->deliverReloadSignal($worker)) {
                $signaled++;
            } else {
                $this->logger->warning('向 Worker 发送重载信号失败', [
                    'worker_id' => $worker->getId(),
                    'pid' => $pid,
                ]);
                $skipped++;
            }
        }

        $this->logger->info('配置重载信号已发送', ['signaled' => $signaled, 'skipped' => $skipped]);
    }

    /**
     * 向单个 worker 投递 USR1 重载信号。抽成受保护方法便于在测试中替换，
     * 隔离对全局 posix_kill 的依赖。
     */
    protected function deliverReloadSignal(WorkerInterface $worker): bool
    {
        return posix_kill($worker->getPid(), Signal::USR1);
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

        // 子进程重置信号处理器
        pcntl_async_signals(false);
        foreach ([SIGTERM, SIGINT, SIGUSR1, SIGCHLD] as $s) {
            pcntl_signal($s, SIG_DFL);
        }

        posix_setsid();

        $pid = pcntl_fork();

        if ($pid < 0) {
            throw ProcessException::forkFailed('无法创建第二个守护进程');
        }

        if ($pid > 0) {
            exit(0);
        }

        // 二次 fork 后再次重置（双重保险）
        pcntl_async_signals(false);
        foreach ([SIGTERM, SIGINT, SIGUSR1, SIGCHLD] as $s) {
            pcntl_signal($s, SIG_DFL);
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
            $this->tick();
        }

        $this->logger->info('事件循环结束');
    }

    /**
     * 单次事件循环迭代。
     *
     * 每个检查步骤都在孤立的错误边界内执行：任一回调（如用户 heartbeat 回调）
     * 或检查逻辑抛异常都只记录日志、绝不穿透外层循环——否则一个瞬态错误就会
     * 崩掉 master 并连带杀死所有 worker（常驻循环异常边界全局约定）。
     */
    private function tick(): void
    {
        try {
            pcntl_signal_dispatch();
        } catch (\Throwable $e) {
            $this->logger->error('信号派发异常', ['exception' => $e->getMessage()]);
        }

        try {
            $this->checkHeartbeat();
        } catch (\Throwable $e) {
            $this->logger->error('心跳检查异常', ['exception' => $e->getMessage()]);
        }

        try {
            $this->checkMemory();
        } catch (\Throwable $e) {
            $this->logger->error('内存检查异常', ['exception' => $e->getMessage()]);
        }

        try {
            $this->checkWorkers();
        } catch (\Throwable $e) {
            $this->logger->error('Worker 检查异常', ['exception' => $e->getMessage()]);
        }

        if ($this->processedRequests >= $this->maxRequests) {
            $this->logger->info('达到最大请求数，准备重启');
            try {
                $this->restart();
            } catch (\Throwable $e) {
                $this->logger->error('自动重启异常', ['exception' => $e->getMessage()]);
            }
            // 等价原 while 循环的 break：强制退出外层循环，避免 restart→start
            // 重入 runEventLoop 后 running 被重置为 true 导致外层循环不终止。
            $this->running = false;
        }

        usleep(10000);
    }

    private function checkHeartbeat(): void
    {
        $now = microtime(true);

        if ($now - $this->lastHeartbeat < $this->heartbeatInterval) {
            return;
        }

        $this->lastHeartbeat = $now;

        foreach ($this->workers as $worker) {
            if ($worker instanceof WorkerInterface) {
                $status = $worker->heartbeat();

                if (isset($status['overdue']) && $status['overdue']) {
                    $this->logger->warning('Worker 心跳超时', ['worker_id' => $worker->getId()]);
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
        foreach ($this->workers as $worker) {
            if ($worker instanceof WorkerInterface) {
                if (!$worker->isRunning()) {
                    $this->logger->warning('Worker 已停止', ['worker_id' => $worker->getId()]);
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
            $slot = $this->pidToSlot[$pid] ?? null;

            if ($slot === null) {
                // 非本 master 跟踪的子进程（如孙进程），已被回收，忽略
                continue;
            }

            $this->handleWorkerExit($slot, $status);
        }
    }

    /**
     * 处理单个槽位 worker 的退出：结构化记录退出原因（正常退出 / 被信号杀死），
     * 触发 worker_exit 回调，并在运行中且已注入重生器时按需自动重生。
     */
    private function handleWorkerExit(int $slot, int $status): void
    {
        $worker = $this->workers[$slot] ?? null;
        $workerId = $worker instanceof WorkerInterface ? $worker->getId() : null;
        $pid = $worker instanceof WorkerInterface ? $worker->getPid() : null;

        $info = $this->interpretExitStatus($status);

        if ($info['signaled']) {
            $this->logger->warning('Worker 被信号终止', [
                'worker_id' => $workerId,
                'pid' => $pid,
                'signal' => $info['signal'],
                'signal_name' => $info['signal_name'],
            ]);
        } else {
            $this->logger->info('Worker 已退出', [
                'worker_id' => $workerId,
                'pid' => $pid,
                'exit_code' => $info['exit_code'],
            ]);
        }

        $this->unregisterSlot($slot);

        // 触发 worker_exit 回调（如有），隔离异常避免穿透
        if (isset($this->callbacks['worker_exit'])) {
            try {
                ($this->callbacks['worker_exit'])($workerId, $info);
            } catch (\Throwable $e) {
                $this->logger->error('worker_exit 回调异常', ['exception' => $e->getMessage()]);
            }
        }

        // 仅在运行中、且注入了重生器时自动重生；停止/重启阶段不重生（避免关不掉）
        if ($this->state !== ProcessInterface::STATE_RUNNING || $this->workerSpawner === null) {
            return;
        }

        $abnormal = $info['signaled'] || $info['exit_code'] !== 0;

        if (!$abnormal) {
            // 干净退出（如达到 max_requests）：维持池容量，直接重生且不计入崩溃上限
            $this->respawn($slot);
            return;
        }

        $attempts = $this->restartCounts[$slot] ?? 0;
        if ($attempts >= $this->maxRestartAttempts) {
            $this->logger->critical('Worker 反复异常退出，停止自动重生', [
                'worker_id' => $workerId,
                'slot' => $slot,
                'attempts' => $attempts,
            ]);
            return;
        }

        $this->restartCounts[$slot] = $attempts + 1;
        $this->respawn($slot);
    }

    private function respawn(int $slot): void
    {
        $attempt = $this->restartCounts[$slot] ?? 0;

        // 指数退避：base * 2^attempt，上限 max
        $base = $this->config['restart_backoff_base'] ?? 100000;
        $max = $this->config['restart_backoff_max'] ?? 5000000;
        $delay = min($base * (1 << $attempt), $max);

        $this->logger->info('Worker 重生退避', [
            'slot' => $slot,
            'attempt' => $attempt,
            'delay_us' => $delay,
        ]);
        usleep($delay);

        try {
            $replacement = ($this->workerSpawner)();

            if (!$replacement instanceof WorkerInterface) {
                $this->logger->error('Worker 重生器返回非 WorkerInterface', ['slot' => $slot]);
                return;
            }

            $this->registerWorker($replacement, $slot);
            $this->logger->info('Worker 已自动重生', [
                'slot' => $slot,
                'attempt' => $attempt,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Worker 自动重生失败', ['slot' => $slot, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 解析 wait 状态。必须先判 wifexited/wifsignaled 再取退出码：被信号杀死时
     * 直接 pcntl_wexitstatus 会返回无意义值并触发 PHP warning（原实现缺陷）。
     *
     * @return array{signaled: bool, signal: int, signal_name: string, exit_code: int, exited: bool}
     */
    private function interpretExitStatus(int $status): array
    {
        if (pcntl_wifsignaled($status)) {
            $signal = pcntl_wtermsig($status);
            return [
                'signaled' => true,
                'signal' => $signal,
                'signal_name' => Signal::getName($signal),
                'exit_code' => -1,
                'exited' => false,
            ];
        }

        return [
            'signaled' => false,
            'signal' => 0,
            'signal_name' => '',
            'exit_code' => pcntl_wexitstatus($status),
            'exited' => pcntl_wifexited($status),
        ];
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

        foreach ($this->workers as $worker) {
            if ($worker instanceof WorkerInterface) {
                $status['workers'][$worker->getId()] = [
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
        $this->registerWorker($worker, $this->nextSlot++);
    }

    /**
     * 注册 worker 到指定 slot，并建立 pid / id 反向索引。
     * 重生时复用同一 slot，使崩溃计数能稳定累计。
     */
    private function registerWorker(WorkerInterface $worker, int $slot): void
    {
        $this->workers[$slot] = $worker;
        $this->pidToSlot[$worker->getPid()] = $slot;
        $this->workerIdToSlot[$worker->getId()] = $slot;
        $this->logger->debug('Worker 已注册', ['worker_id' => $worker->getId(), 'slot' => $slot]);
    }

    /**
     * 注入 worker 重生器（返回已启动的 WorkerInterface）。未注入时 worker 退出
     * 不自动重生（保持旧行为）。
     */
    public function setWorkerSpawner(callable $spawner): void
    {
        $this->workerSpawner = $spawner instanceof \Closure ? $spawner : \Closure::fromCallable($spawner);
    }

    public function removeWorker(int $workerId): void
    {
        $slot = $this->workerIdToSlot[$workerId] ?? null;

        if ($slot === null) {
            $this->logger->debug('Worker 移除：未找到', ['worker_id' => $workerId]);
            return;
        }

        $this->unregisterSlot($slot);
        $this->logger->debug('Worker 已移除', ['worker_id' => $workerId, 'slot' => $slot]);
    }

    private function unregisterSlot(int $slot): void
    {
        $worker = $this->workers[$slot] ?? null;

        if ($worker instanceof WorkerInterface) {
            unset($this->pidToSlot[$worker->getPid()], $this->workerIdToSlot[$worker->getId()]);
        }

        // 注意：刻意保留 $this->restartCounts[$slot]——重生复用同一 slot，
        // 崩溃计数需跨重生累计才能触发上限（防 fork bomb）。仅在命中上限后停止重生。
        unset($this->workers[$slot]);
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
