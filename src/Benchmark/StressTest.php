<?php

declare(strict_types=1);

namespace Kode\Process\Benchmark;

/**
 * 压力测试工具
 * 
 * 用于对比 Kode Process 与 Workerman/Swoole 的性能
 */
class StressTest
{
    private int $requests;

    private int $concurrency;

    private array $results = [];

    public function __construct(int $requests = 10000, int $concurrency = 100)
    {
        $this->requests = $requests;
        $this->concurrency = $concurrency;
    }

    public function run(): array
    {
        $this->results = [
            'fork' => $this->benchmarkFork(),
            'ipc' => $this->benchmarkIPC(),
            'response' => $this->benchmarkResponse(),
            'timer' => $this->benchmarkTimer(),
            'memory' => $this->benchmarkMemory(),
            'fiber' => $this->benchmarkFiber(),
        ];

        return $this->results;
    }

    private function benchmarkFork(): array
    {
        $iterations = min($this->requests, 100);
        $start = microtime(true);
        $pids = [];

        for ($i = 0; $i < $iterations; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                usleep(100);
                exit(0);
            } elseif ($pid > 0) {
                $pids[] = $pid;
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $time = microtime(true) - $start;

        return [
            'iterations' => $iterations,
            'total_time' => round($time * 1000, 2) . 'ms',
            'avg_time' => round(($time / $iterations) * 1000, 4) . 'ms',
            'ops_per_sec' => (int) ($iterations / $time),
        ];
    }

    private function benchmarkIPC(): array
    {
        $iterations = min($this->requests, 1000);
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        $start = microtime(true);
        $success = 0;
        $failed = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $data = serialize(['id' => $i, 'time' => microtime(true)]);

            if (socket_write($sockets[0], $data)) {
                $success++;
            } else {
                $failed++;
            }
        }

        $time = microtime(true) - $start;

        socket_close($sockets[0]);
        socket_close($sockets[1]);

        return [
            'iterations' => $iterations,
            'success' => $success,
            'failed' => $failed,
            'total_time' => round($time * 1000, 2) . 'ms',
            'avg_time' => round(($time / $iterations) * 1000, 4) . 'ms',
            'throughput' => (int) ($iterations / $time) . ' ops/s',
        ];
    }

    private function benchmarkResponse(): array
    {
        $iterations = $this->requests;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            \Kode\Process\Response::ok(['index' => $i, 'data' => str_repeat('x', 100)])
                ->withMeta('job_id', "job_{$i}")
                ->toArray();
        }

        $time = microtime(true) - $start;

        return [
            'iterations' => $iterations,
            'total_time' => round($time * 1000, 2) . 'ms',
            'avg_time' => round(($time / $iterations) * 1000, 4) . 'ms',
            'ops_per_sec' => (int) ($iterations / $time),
        ];
    }

    private function benchmarkTimer(): array
    {
        $iterations = min($this->requests, 1000);
        \Kode\Process\Compat\Timer::init();

        $start = microtime(true);
        $count = 0;

        for ($i = 0; $i < $iterations; $i++) {
            \Kode\Process\Compat\Timer::add(0.001, function () use (&$count) {
                $count++;
            }, [], false);
        }

        $time = microtime(true) - $start;

        return [
            'iterations' => $iterations,
            'total_time' => round($time * 1000, 2) . 'ms',
            'avg_time' => round(($time / $iterations) * 1000, 4) . 'ms',
            'ops_per_sec' => (int) ($iterations / $time),
        ];
    }

    private function benchmarkMemory(): array
    {
        $iterations = min($this->requests, 1000);
        $start = microtime(true);
        $initial = memory_get_usage(true);

        $data = [];
        for ($i = 0; $i < $iterations; $i++) {
            $data[] = new \Kode\Process\Response(0, 'test', ['id' => $i]);
        }

        $peak = memory_get_usage(true);
        unset($data);

        $time = microtime(true) - $start;

        return [
            'iterations' => $iterations,
            'initial_memory' => $this->formatBytes($initial),
            'peak_memory' => $this->formatBytes($peak),
            'memory_per_op' => $this->formatBytes(($peak - $initial) / $iterations),
            'total_time' => round($time * 1000, 2) . 'ms',
        ];
    }

    private function benchmarkFiber(): array
    {
        if (!class_exists(\Fiber::class)) {
            return ['error' => 'Fiber not supported'];
        }

        $iterations = min($this->requests, 1000);
        $start = microtime(true);

        $fibers = [];
        for ($i = 0; $i < $iterations; $i++) {
            $fiber = new \Fiber(function () use ($i) {
                \Fiber::suspend();
                return $i;
            });
            $fiber->start();
            $fibers[] = $fiber;
        }

        foreach ($fibers as $fiber) {
            while (!$fiber->isTerminated()) {
                $fiber->resume();
            }
        }

        $time = microtime(true) - $start;

        return [
            'iterations' => $iterations,
            'total_time' => round($time * 1000, 2) . 'ms',
            'avg_time' => round(($time / $iterations) * 1000, 4) . 'ms',
            'ops_per_sec' => (int) ($iterations / $time),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function printReport(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║              Kode Process 压力测试报告                        ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  请求数: " . str_pad((string) number_format($this->requests), 10) . "  并发数: " . str_pad((string) $this->concurrency, 10) . "       ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";

        foreach ($this->results as $name => $result) {
            $title = match ($name) {
                'fork' => '进程 Fork 测试',
                'ipc' => 'IPC 通信测试',
                'response' => '响应格式测试',
                'timer' => '定时器测试',
                'memory' => '内存使用测试',
                'fiber' => 'Fiber 协程测试',
                default => $name,
            };

            echo "┌─────────────────────────────────────────────────────────────┐\n";
            echo "│ {$title}" . str_repeat(' ', 57 - strlen($title)) . "│\n";
            echo "├─────────────────────────────────────────────────────────────┤\n";

            foreach ($result as $key => $value) {
                $label = $this->translateLabel($key);
                $value = is_numeric($value) ? number_format($value) : $value;
                $line = "│ {$label}: {$value}";
                echo $line . str_repeat(' ', 61 - strlen($line)) . "│\n";
            }

            echo "└─────────────────────────────────────────────────────────────┘\n\n";
        }
    }

    private function translateLabel(string $key): string
    {
        return match ($key) {
            'iterations' => '迭代次数',
            'total_time' => '总耗时',
            'avg_time' => '平均耗时',
            'ops_per_sec' => '每秒操作数',
            'throughput' => '吞吐量',
            'success' => '成功',
            'failed' => '失败',
            'initial_memory' => '初始内存',
            'peak_memory' => '峰值内存',
            'memory_per_op' => '单次内存',
            'error' => '错误',
            default => $key,
        };
    }

    public static function quick(int $requests = 10000, int $concurrency = 100): array
    {
        $test = new self($requests, $concurrency);
        $test->run();
        $test->printReport();
        return $test->getResults();
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
