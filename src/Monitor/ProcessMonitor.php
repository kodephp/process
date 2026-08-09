<?php

declare(strict_types=1);

namespace Kode\Process\Monitor;

use Kode\Process\Contracts\MonitorInterface;
use Kode\Process\Contracts\WorkerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 进程监控器
 * 
 * 负责监控进程健康状态、资源使用和自动重启
 */
class ProcessMonitor implements MonitorInterface
{
    private string $health = MonitorInterface::HEALTH_HEALTHY;

    private LoggerInterface $logger;

    private float $heartbeatInterval = 5.0;

    private int $maxMemoryUsage = 512 * 1024 * 1024;

    private float $maxCpuUsage = 80.0;

    private float $maxResponseTime = 30.0;

    private array $processes = [];

    private array $metrics = [];

    private bool $running = false;

    private array $unhealthyCallbacks = [];

    private array $restartCallbacks = [];

    private array $history = [];

    private int $maxHistorySize = 100;

    /** 每个 pid 的最近一次 CPU 采样（累计滴答数 + 采样时刻），用于求占用率而非累计值 */
    private array $cpuSamples = [];

    private float $lastCheck = 0.0;

    private int $checkCount = 0;

    private int $restartCount = 0;

    private int $maxRestartAttempts = 5;

    private array $restartAttempts = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function start(): void
    {
        $this->running = true;
        $this->logger->info('进程监控器已启动');
    }

    public function stop(): void
    {
        $this->running = false;
        $this->logger->info('进程监控器已停止');
    }

    public function register(int $pid, array $config = []): void
    {
        $this->processes[$pid] = array_merge([
            'pid' => $pid,
            'registered_at' => microtime(true),
            'last_check' => 0,
            'last_heartbeat' => microtime(true),
            'restart_count' => 0,
            'status' => 'registered',
            'memory_limit' => $this->maxMemoryUsage,
            'cpu_limit' => $this->maxCpuUsage,
        ], $config);

        $this->logger->debug('进程已注册到监控器', ['pid' => $pid]);
    }

    public function unregister(int $pid): void
    {
        unset($this->processes[$pid]);
        unset($this->restartAttempts[$pid]);

        $this->logger->debug('进程已从监控器移除', ['pid' => $pid]);
    }

    public function check(int $pid): array
    {
        if (!isset($this->processes[$pid])) {
            return [
                'pid' => $pid,
                'status' => 'unknown',
                'healthy' => false,
                'error' => '进程未注册',
            ];
        }

        $process = &$this->processes[$pid];
        $process['last_check'] = microtime(true);

        $isAlive = $this->isProcessAlive($pid);

        if (!$isAlive) {
            $process['status'] = 'dead';
            $process['healthy'] = false;

            $this->recordHistory($pid, 'dead');

            return [
                'pid' => $pid,
                'status' => 'dead',
                'healthy' => false,
                'error' => '进程已终止',
            ];
        }

        $memory = $this->getProcessMemory($pid);
        $cpu = $this->getProcessCpu($pid);

        $process['memory'] = $memory;
        $process['cpu'] = $cpu;

        $issues = [];

        if ($memory > $process['memory_limit']) {
            $issues[] = 'memory_exceeded';
        }

        if ($cpu > $process['cpu_limit']) {
            $issues[] = 'cpu_exceeded';
        }

        $healthy = empty($issues);

        $process['status'] = $healthy ? 'healthy' : 'unhealthy';
        $process['healthy'] = $healthy;
        $process['issues'] = $issues;

        $this->recordHistory($pid, $process['status']);

        return [
            'pid' => $pid,
            'status' => $process['status'],
            'healthy' => $healthy,
            'memory' => $memory,
            'cpu' => $cpu,
            'issues' => $issues,
        ];
    }

    public function checkAll(): array
    {
        $results = [];
        $healthyCount = 0;
        $unhealthyCount = 0;

        foreach (array_keys($this->processes) as $pid) {
            try {
                $result = $this->check($pid);
            } catch (\Throwable $e) {
                $this->logger->error('进程健康检查抛出异常', [
                    'pid' => $pid,
                    'error' => $e->getMessage(),
                ]);

                $result = [
                    'pid' => $pid,
                    'status' => 'unknown',
                    'healthy' => false,
                    'error' => $e->getMessage(),
                ];
            }

            $results[$pid] = $result;

            if ($result['healthy']) {
                $healthyCount++;
            } else {
                $unhealthyCount++;

                $this->handleUnhealthy($pid, $result);
            }
        }

        $this->checkCount++;

        if ($unhealthyCount === 0) {
            $this->health = MonitorInterface::HEALTH_HEALTHY;
        } elseif ($healthyCount > $unhealthyCount) {
            $this->health = MonitorInterface::HEALTH_DEGRADED;
        } else {
            $this->health = MonitorInterface::HEALTH_UNHEALTHY;
        }

        $this->metrics = [
            'total' => count($this->processes),
            'healthy' => $healthyCount,
            'unhealthy' => $unhealthyCount,
            'check_count' => $this->checkCount,
            'last_check' => microtime(true),
        ];

        return $results;
    }

    private function handleUnhealthy(int $pid, array $result): void
    {
        foreach ($this->unhealthyCallbacks as $callback) {
            try {
                $callback($pid, $result);
            } catch (\Throwable $e) {
                $this->logger->error('进程不健康回调抛出异常', [
                    'pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($result['status'] === 'dead') {
            $this->attemptRestart($pid);
        }
    }

    private function attemptRestart(int $pid): void
    {
        if (!isset($this->restartAttempts[$pid])) {
            $this->restartAttempts[$pid] = 0;
        }

        if ($this->restartAttempts[$pid] >= $this->maxRestartAttempts) {
            $this->logger->error('进程重启次数超限', [
                'pid' => $pid,
                'attempts' => $this->restartAttempts[$pid]
            ]);

            return;
        }

        $this->restartAttempts[$pid]++;
        $this->restartCount++;

        foreach ($this->restartCallbacks as $callback) {
            try {
                $callback($pid);
            } catch (\Throwable $e) {
                $this->logger->error('进程重启回调抛出异常', [
                    'pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->warning('尝试重启进程', [
            'pid' => $pid,
            'attempt' => $this->restartAttempts[$pid]
        ]);
    }

    public function resetRestartAttempts(int $pid): void
    {
        unset($this->restartAttempts[$pid]);

        if (isset($this->processes[$pid])) {
            $this->processes[$pid]['restart_count'] = 0;
        }
    }

    private function recordHistory(int $pid, string $status): void
    {
        $this->history[] = [
            'pid' => $pid,
            'status' => $status,
            'time' => microtime(true),
        ];

        if (count($this->history) > $this->maxHistorySize) {
            array_shift($this->history);
        }
    }

    public function getHealth(): string
    {
        return $this->health;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getHistory(?int $pid = null, int $limit = 50): array
    {
        $history = $this->history;

        if ($pid !== null) {
            $history = array_filter($history, fn($h) => $h['pid'] === $pid);
        }

        return array_slice(array_reverse($history), 0, $limit);
    }

    public function setHeartbeatInterval(float $seconds): void
    {
        $this->heartbeatInterval = $seconds;
    }

    public function setMaxMemoryUsage(int $bytes): void
    {
        $this->maxMemoryUsage = $bytes;
    }

    public function setMaxCpuUsage(float $percent): void
    {
        $this->maxCpuUsage = $percent;
    }

    public function setMaxResponseTime(float $seconds): void
    {
        $this->maxResponseTime = $seconds;
    }

    public function setMaxRestartAttempts(int $attempts): void
    {
        $this->maxRestartAttempts = $attempts;
    }

    public function onUnhealthy(callable $callback): void
    {
        $this->unhealthyCallbacks[] = $callback;
    }

    public function onRestart(callable $callback): void
    {
        $this->restartCallbacks[] = $callback;
    }

    public function updateHeartbeat(int $pid): void
    {
        if (isset($this->processes[$pid])) {
            $this->processes[$pid]['last_heartbeat'] = microtime(true);
        }
    }

    public function getProcessStats(int $pid): ?array
    {
        return $this->processes[$pid] ?? null;
    }

    public function getRegisteredProcesses(): array
    {
        return array_keys($this->processes);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getRestartCount(): int
    {
        return $this->restartCount;
    }

    private function isProcessAlive(int $pid): bool
    {
        if (posix_kill($pid, 0)) {
            return true;
        }

        // posix_kill 返回 false：ESRCH(3)=进程不存在；EPERM(1)=存在但无权限
        // 仅「不存在」判为死亡，权限不足仍视为存活。
        return posix_get_last_error() !== 3;
    }

    private function getProcessMemory(int $pid): int
    {
        $statusFile = "/proc/{$pid}/status";

        if (is_readable($statusFile)) {
            $content = @file_get_contents($statusFile);

            if ($content !== false && preg_match('/VmRSS:\s+(\d+)/', $content, $matches)) {
                return (int) $matches[1] * 1024;
            }

            return 0;
        }

        // macOS / BSD 无 /proc：ps -o rss 返回 KB
        $out = @shell_exec("ps -o rss= -p {$pid} 2>/dev/null");

        if ($out !== null && preg_match('/\d+/', $out, $matches)) {
            return (int) $matches[0] * 1024;
        }

        return 0;
    }

    /**
     * 返回进程近一周期的 CPU 占用率（0~100 百分比），而非累计 CPU 秒数。
     *
     * 旧实现直接把 /proc/$pid/stat 的 utime+stime 时钟滴答除以 100 当作返回值，
     * 本质是「自启动累计 CPU 秒数」，却与 maxCpuUsage（百分比阈值）比较，导致任何
     * 累计跑了阈值秒数 CPU 的进程都被误判 cpu_exceeded 并重启。这里改为两次采样
     * 求占用率；macOS/BSD 直接读 ps 的百分比。
     */
    private function getProcessCpu(int $pid): float
    {
        if (!is_readable('/proc')) {
            $out = @shell_exec("ps -o %cpu= -p {$pid} 2>/dev/null");

            if ($out !== null && preg_match('/[\d.]+/', $out, $matches)) {
                return min(100.0, (float) $matches[0]);
            }

            return 0.0;
        }

        $now = microtime(true);
        $totalTicks = $this->readCpuTicksLinux($pid);

        if ($totalTicks === null) {
            unset($this->cpuSamples[$pid]);
            return 0.0;
        }

        // 首帧无法求速率，仅记录基线
        if (!isset($this->cpuSamples[$pid])) {
            $this->cpuSamples[$pid] = ['total' => $totalTicks, 'time' => $now];
            return 0.0;
        }

        $prev = $this->cpuSamples[$pid];
        $elapsed = $now - $prev['time'];
        $deltaTicks = $totalTicks - $prev['total'];
        $this->cpuSamples[$pid] = ['total' => $totalTicks, 'time' => $now];

        if ($elapsed <= 0 || $deltaTicks < 0) {
            return 0.0;
        }

        $ticksPerSec = $this->getClockTicks();
        $cpuSeconds = $deltaTicks / $ticksPerSec;
        $usage = ($cpuSeconds / $elapsed) * 100.0;
        $cores = $this->getCpuCount();

        return $cores > 0 ? min(100.0, $usage / $cores) : min(100.0, $usage);
    }

    private function readCpuTicksLinux(int $pid): ?int
    {
        $statFile = "/proc/{$pid}/stat";

        if (!is_readable($statFile)) {
            return null;
        }

        $content = @file_get_contents($statFile);

        if ($content === false) {
            return null;
        }

        // comm 字段（括号包裹）可能含空格/括号，从首个 ')' 之后开始解析，
        // 避免 explode(' ') 因 comm 含空格而错位。
        $pos = strpos($content, ')');

        if ($pos === false) {
            return null;
        }

        $parts = explode(' ', substr($content, $pos + 2));
        $utime = (int) ($parts[11] ?? 0);
        $stime = (int) ($parts[12] ?? 0);

        return $utime + $stime;
    }

    private function getClockTicks(): int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')) {
            $ticks = @posix_sysconf(POSIX_SC_CLK_TCK);
            if (is_int($ticks) && $ticks > 0) {
                return $ticks;
            }
        }

        return 100;
    }

    private function getCpuCount(): int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_NPROCESSORS_ONLN')) {
            $n = @posix_sysconf(POSIX_SC_NPROCESSORS_ONLN);
            if (is_int($n) && $n > 0) {
                return $n;
            }
        }

        $out = @shell_exec('nproc 2>/dev/null');

        if ($out !== null && ($n = (int) trim($out)) > 0) {
            return $n;
        }

        return 1;
    }

    /**
     * 当前平台是否支持进程资源监控（Linux /proc 或可用的 ps）。
     */
    public function supported(): bool
    {
        if (is_readable('/proc')) {
            return true;
        }

        $out = @shell_exec('ps -o pid= -p ' . getmypid() . ' 2>/dev/null');

        return $out !== null && trim($out) !== '';
    }
}
