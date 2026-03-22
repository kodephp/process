<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Contracts\IPCInterface;
use Kode\Process\Contracts\MonitorInterface;
use Kode\Process\Contracts\PoolInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\IPC\MessageQueue;
use Kode\Process\IPC\SharedMemoryIPC;
use Kode\Process\IPC\SocketIPC;
use Kode\Process\Master\ProcessManager;
use Kode\Process\Monitor\ProcessMonitor;
use Kode\Process\Signal\SignalDispatcher;
use Kode\Process\Signal\SignalHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Process 门面类
 * 
 * 提供统一的进程管理静态接口
 */
final class Process
{
    private static ?ProcessManager $manager = null;

    private static ?SignalDispatcher $signalDispatcher = null;

    private static ?ProcessMonitor $monitor = null;

    private static ?LoggerInterface $logger = null;

    private static array $ipcChannels = [];

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function getManager(): ProcessManager
    {
        if (self::$manager === null) {
            self::$manager = new ProcessManager([], self::$logger);
        }

        return self::$manager;
    }

    public static function getSignalDispatcher(): SignalDispatcher
    {
        if (self::$signalDispatcher === null) {
            self::$signalDispatcher = new SignalDispatcher(self::$logger);
        }

        return self::$signalDispatcher;
    }

    public static function getMonitor(): ProcessMonitor
    {
        if (self::$monitor === null) {
            self::$monitor = new ProcessMonitor(self::$logger);
        }

        return self::$monitor;
    }

    public static function start(array $config = [], ?callable $workerCallback = null): void
    {
        $manager = self::getManager();
        $manager->setConfig($config);

        if ($workerCallback !== null) {
            $manager->start($workerCallback);
        }
    }

    public static function stop(bool $graceful = true): void
    {
        self::getManager()->stop($graceful);
    }

    public static function restart(): void
    {
        self::getManager()->restart();
    }

    public static function reload(): void
    {
        self::getManager()->reload();
    }

    public static function scale(int $targetCount): void
    {
        self::getManager()->scale($targetCount);
    }

    public static function getWorkers(): array
    {
        return self::getManager()->getWorkers();
    }

    public static function getWorker(int $id): ?WorkerInterface
    {
        return self::getManager()->getWorker($id);
    }

    public static function getWorkerCount(): int
    {
        return self::getManager()->getWorkerCount();
    }

    public static function isRunning(): bool
    {
        return self::getManager()->isRunning();
    }

    public static function getStatus(): array
    {
        return self::getManager()->getStatus();
    }

    public static function onSignal(int $signal, callable $handler, int $priority = 0): string
    {
        return self::getSignalDispatcher()->on($signal, $handler, $priority);
    }

    public static function removeSignal(int $signal, callable|string $handler): void
    {
        self::getSignalDispatcher()->off($signal, $handler);
    }

    public static function dispatchSignal(int $signal): void
    {
        self::getSignalDispatcher()->dispatch($signal);
    }

    public static function fork(callable $childCallback, ?callable $parentCallback = null): int
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            throw new Exceptions\ProcessException('Fork 失败');
        }

        if ($pid === 0) {
            $childCallback();
            exit(0);
        }

        if ($parentCallback !== null) {
            $parentCallback($pid);
        }

        return $pid;
    }

    public static function wait(?int $pid = null, bool $noHang = false): array
    {
        $flags = $noHang ? WNOHANG : 0;
        $targetPid = $pid ?? -1;

        $result = pcntl_waitpid($targetPid, $status, $flags);

        return [
            'pid' => $result,
            'exit_code' => $result > 0 ? pcntl_wexitstatus($status) : 0,
            'signaled' => $result > 0 ? pcntl_wifsignaled($status) : false,
            'signal' => $result > 0 ? pcntl_wtermsig($status) : 0,
        ];
    }

    public static function kill(int $pid, int $signal = Signal::TERM): bool
    {
        return posix_kill($pid, $signal);
    }

    public static function isProcessAlive(int $pid): bool
    {
        return posix_kill($pid, 0) && posix_get_last_error() !== 3;
    }

    public static function getPid(): int
    {
        return posix_getpid();
    }

    public static function getParentPid(): int
    {
        return posix_getppid();
    }

    public static function getProcessInfo(?int $pid = null): array
    {
        $pid = $pid ?? posix_getpid();

        return [
            'pid' => $pid,
            'ppid' => posix_getppid(),
            'uid' => posix_getuid(),
            'gid' => posix_getgid(),
            'euid' => posix_geteuid(),
            'egid' => posix_getegid(),
            'sid' => posix_getsid($pid),
            'pgid' => posix_getpgid($pid),
            'memory' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    public static function setProcessTitle(string $title): bool
    {
        if (function_exists('cli_set_process_title')) {
            return cli_set_process_title($title);
        }

        return false;
    }

    public static function daemonize(): bool
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            return false;
        }

        if ($pid > 0) {
            exit(0);
        }

        posix_setsid();

        $pid = pcntl_fork();

        if ($pid < 0) {
            return false;
        }

        if ($pid > 0) {
            exit(0);
        }

        umask(0);
        chdir('/');

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        return true;
    }

    public static function createIpc(string $type = IPCInterface::TYPE_SOCKET): IPCInterface
    {
        $ipc = match ($type) {
            IPCInterface::TYPE_SOCKET => new SocketIPC(self::$logger),
            IPCInterface::TYPE_SHARED_MEMORY => new SharedMemoryIPC(null, self::$logger),
            IPCInterface::TYPE_MESSAGE_QUEUE => new MessageQueue(null, self::$logger),
            default => new SocketIPC(self::$logger),
        };

        $id = spl_object_id($ipc);
        self::$ipcChannels[$id] = $ipc;

        return $ipc;
    }

    public static function closeIpc(IPCInterface $ipc): void
    {
        $id = spl_object_id($ipc);
        unset(self::$ipcChannels[$id]);
        $ipc->close();
    }

    public static function startMonitor(): void
    {
        self::getMonitor()->start();
    }

    public static function stopMonitor(): void
    {
        self::getMonitor()->stop();
    }

    public static function checkHealth(): array
    {
        return self::getMonitor()->checkAll();
    }

    public static function getCpuCount(): int
    {
        if (function_exists('swoole_cpu_num')) {
            return swoole_cpu_num();
        }

        $cpuInfo = file_get_contents('/proc/cpuinfo');

        if ($cpuInfo !== false) {
            return substr_count($cpuInfo, 'processor');
        }

        return 1;
    }

    public static function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return [0, 0, 0];
    }

    public static function getMemoryInfo(): array
    {
        $memInfo = [];

        if (is_readable('/proc/meminfo')) {
            $content = file_get_contents('/proc/meminfo');

            if ($content !== false) {
                preg_match_all('/(\w+):\s+(\d+)/', $content, $matches);

                foreach ($matches[1] as $i => $key) {
                    $memInfo[strtolower($key)] = (int) $matches[2][$i] * 1024;
                }
            }
        }

        $memInfo['used'] = memory_get_usage(true);
        $memInfo['peak'] = memory_get_peak_usage(true);

        return $memInfo;
    }

    public static function reset(): void
    {
        self::$manager = null;
        self::$signalDispatcher = null;
        self::$monitor = null;

        foreach (self::$ipcChannels as $ipc) {
            $ipc->close();
        }

        self::$ipcChannels = [];
    }
}
