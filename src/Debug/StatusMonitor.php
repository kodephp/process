<?php

declare(strict_types=1);

namespace Kode\Process\Debug;

final class StatusMonitor
{
    private string $statusFile;
    private string $pidFile;
    private int $masterPid = 0;
    private array $workers = [];
    private int $startTime = 0;

    public function __construct(string $statusFile = '/tmp/kode_process_status.json', string $pidFile = '/tmp/kode_process.pid')
    {
        $this->statusFile = $statusFile;
        $this->pidFile = $pidFile;
    }

    public function init(int $masterPid): void
    {
        $this->masterPid = $masterPid;
        $this->startTime = time();
        $this->savePid($masterPid);
    }

    public function registerWorker(int $pid, string $name, string $listen = 'none'): void
    {
        $this->workers[$pid] = [
            'pid' => $pid,
            'name' => $name,
            'listen' => $listen,
            'connections' => 0,
            'total_requests' => 0,
            'send_fail' => 0,
            'timers' => 0,
            'memory' => 0,
            'status' => 'idle',
            'start_time' => time(),
            'last_update' => time()
        ];
    }

    public function unregisterWorker(int $pid): void
    {
        unset($this->workers[$pid]);
    }

    public function updateWorkerStatus(int $pid, array $data): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid] = array_merge($this->workers[$pid], $data, ['last_update' => time()]);
        }
    }

    public function incrementRequests(int $pid, int $count = 1): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['total_requests'] += $count;
        }
    }

    public function incrementConnections(int $pid, int $count = 1): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['connections'] += $count;
        }
    }

    public function decrementConnections(int $pid, int $count = 1): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['connections'] = max(0, $this->workers[$pid]['connections'] - $count);
        }
    }

    public function incrementSendFail(int $pid): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['send_fail']++;
        }
    }

    public function setWorkerBusy(int $pid): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['status'] = 'busy';
        }
    }

    public function setWorkerIdle(int $pid): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['status'] = 'idle';
        }
    }

    public function updateMemory(int $pid): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['memory'] = memory_get_usage(true);
        }
    }

    public function setTimerCount(int $pid, int $count): void
    {
        if (isset($this->workers[$pid])) {
            $this->workers[$pid]['timers'] = $count;
        }
    }

    public function save(): void
    {
        $status = [
            'master_pid' => $this->masterPid,
            'start_time' => $this->startTime,
            'run_time' => time() - $this->startTime,
            'load_average' => $this->getLoadAverage(),
            'php_version' => PHP_VERSION,
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'workers' => $this->workers,
            'summary' => $this->calculateSummary(),
            'last_update' => time()
        ];

        file_put_contents($this->statusFile, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function load(): array
    {
        if (!file_exists($this->statusFile)) {
            return [];
        }

        $content = file_get_contents($this->statusFile);
        return json_decode($content, true) ?? [];
    }

    public function display(): string
    {
        $status = $this->load();

        if (empty($status)) {
            return "No status data available. Is the process running?\n";
        }

        $output = "\n";
        $output .= str_repeat('-', 80) . " GLOBAL STATUS " . str_repeat('-', 80) . "\n";
        $output .= sprintf("Kode Process Version: %-20s PHP Version: %s\n", '2.3.1', $status['php_version'] ?? PHP_VERSION);
        $output .= sprintf("Start Time: %-25s Run Time: %s\n", 
            date('Y-m-d H:i:s', $status['start_time'] ?? 0),
            $this->formatUptime($status['run_time'] ?? 0)
        );
        $output .= sprintf("Load Average: %s\n", implode(', ', $status['load_average'] ?? [0, 0, 0]));
        $output .= sprintf("Workers: %d processes\n", count($status['workers'] ?? []));
        $output .= "\n";

        $output .= str_repeat('-', 80) . " PROCESS STATUS " . str_repeat('-', 80) . "\n";
        $output .= sprintf("%-8s %-10s %-25s %-20s %-12s %-8s %-8s %-14s %-8s\n",
            'PID', 'Memory', 'Listening', 'Worker Name', 'Connections', 'Timers', 'SendFail', 'Total Requests', 'Status'
        );
        $output .= str_repeat('-', 120) . "\n";

        $totalMemory = 0;
        $totalConnections = 0;
        $totalRequests = 0;
        $totalTimers = 0;
        $totalSendFail = 0;

        foreach ($status['workers'] ?? [] as $worker) {
            $memory = $this->formatBytes($worker['memory'] ?? 0);
            $totalMemory += $worker['memory'] ?? 0;
            $totalConnections += $worker['connections'] ?? 0;
            $totalRequests += $worker['total_requests'] ?? 0;
            $totalTimers += $worker['timers'] ?? 0;
            $totalSendFail += $worker['send_fail'] ?? 0;

            $output .= sprintf("%-8d %-10s %-25s %-20s %-12d %-8d %-8d %-14d [%s]\n",
                $worker['pid'],
                $memory,
                $worker['listen'] ?? 'none',
                $worker['name'] ?? 'unknown',
                $worker['connections'] ?? 0,
                $worker['timers'] ?? 0,
                $worker['send_fail'] ?? 0,
                $worker['total_requests'] ?? 0,
                $worker['status'] ?? 'idle'
            );
        }

        $output .= str_repeat('-', 120) . "\n";
        $output .= sprintf("Summary: %-8s %-25s %-20s %-12d %-8d %-8d %-14d\n",
            $this->formatBytes($totalMemory),
            '-',
            '-',
            $totalConnections,
            $totalTimers,
            $totalSendFail,
            $totalRequests
        );

        return $output;
    }

    public function displayDetailed(int $pid = 0): string
    {
        $status = $this->load();

        if (empty($status)) {
            return "No status data available.\n";
        }

        $output = "\n=== Detailed Status ===\n\n";

        if ($pid > 0 && isset($status['workers'][$pid])) {
            $worker = $status['workers'][$pid];
            $output .= "Worker PID: {$pid}\n";
            $output .= str_repeat('-', 40) . "\n";
            $output .= "Name: {$worker['name']}\n";
            $output .= "Listening: {$worker['listen']}\n";
            $output .= "Status: {$worker['status']}\n";
            $output .= "Memory: " . $this->formatBytes($worker['memory']) . "\n";
            $output .= "Connections: {$worker['connections']}\n";
            $output .= "Total Requests: {$worker['total_requests']}\n";
            $output .= "Send Failures: {$worker['send_fail']}\n";
            $output .= "Active Timers: {$worker['timers']}\n";
            $output .= "Started: " . date('Y-m-d H:i:s', $worker['start_time']) . "\n";
            $output .= "Last Update: " . date('Y-m-d H:i:s', $worker['last_update']) . "\n";
        } else {
            $output .= "Master PID: {$status['master_pid']}\n";
            $output .= "Start Time: " . date('Y-m-d H:i:s', $status['start_time']) . "\n";
            $output .= "Run Time: " . $this->formatUptime($status['run_time']) . "\n";
            $output .= "Load Average: " . implode(', ', $status['load_average']) . "\n";
            $output .= "Total Memory: " . $this->formatBytes($status['memory']) . "\n";
            $output .= "Peak Memory: " . $this->formatBytes($status['peak_memory']) . "\n";
            $output .= "\nSummary:\n";
            $output .= "- Total Workers: " . count($status['workers']) . "\n";
            $output .= "- Total Connections: " . ($status['summary']['total_connections'] ?? 0) . "\n";
            $output .= "- Total Requests: " . ($status['summary']['total_requests'] ?? 0) . "\n";
        }

        return $output;
    }

    private function calculateSummary(): array
    {
        $totalConnections = 0;
        $totalRequests = 0;
        $totalSendFail = 0;
        $totalTimers = 0;
        $busyCount = 0;

        foreach ($this->workers as $worker) {
            $totalConnections += $worker['connections'] ?? 0;
            $totalRequests += $worker['total_requests'] ?? 0;
            $totalSendFail += $worker['send_fail'] ?? 0;
            $totalTimers += $worker['timers'] ?? 0;

            if (($worker['status'] ?? 'idle') === 'busy') {
                $busyCount++;
            }
        }

        return [
            'total_connections' => $totalConnections,
            'total_requests' => $totalRequests,
            'total_send_fail' => $totalSendFail,
            'total_timers' => $totalTimers,
            'busy_workers' => $busyCount,
            'idle_workers' => count($this->workers) - $busyCount
        ];
    }

    private function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            return array_map(fn($v) => round($v, 2), sys_getloadavg());
        }

        return [0, 0, 0];
    }

    private function savePid(int $pid): void
    {
        file_put_contents($this->pidFile, (string)$pid);
    }

    public function getMasterPid(): int
    {
        if (file_exists($this->pidFile)) {
            return (int)file_get_contents($this->pidFile);
        }

        return 0;
    }

    public function isRunning(): bool
    {
        $pid = $this->getMasterPid();
        
        if ($pid <= 0) {
            return false;
        }

        return file_exists("/proc/{$pid}") || posix_kill($pid, 0);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . $units[$i];
    }

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return "{$days} days {$hours} hours {$minutes} minutes";
        }

        if ($hours > 0) {
            return "{$hours} hours {$minutes} minutes {$secs} seconds";
        }

        return "{$minutes} minutes {$secs} seconds";
    }

    public function cleanup(): void
    {
        if (file_exists($this->statusFile)) {
            unlink($this->statusFile);
        }

        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }
}
