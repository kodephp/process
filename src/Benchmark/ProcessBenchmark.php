<?php

declare(strict_types=1);

namespace Kode\Process\Benchmark;

use Kode\Process\Response;
use Kode\Process\Server;

/**
 * 性能基准测试
 */
class ProcessBenchmark
{
    private int $iterations;

    private array $results = [];

    public function __construct(int $iterations = 1000)
    {
        $this->iterations = $iterations;
    }

    public function run(): array
    {
        $this->results = [
            'fork' => $this->benchmarkFork(),
            'ipc' => $this->benchmarkIPC(),
            'signal' => $this->benchmarkSignal(),
            'response' => $this->benchmarkResponse(),
            'memory' => $this->benchmarkMemory(),
        ];

        return $this->results;
    }

    private function benchmarkFork(): array
    {
        $start = microtime(true);
        $pids = [];

        for ($i = 0; $i < min($this->iterations, 100); $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                exit(0);
            } elseif ($pid > 0) {
                $pids[] = $pid;
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return [
            'iterations' => count($pids),
            'time' => microtime(true) - $start,
            'avg_time' => (microtime(true) - $start) / max(count($pids), 1),
        ];
    }

    private function benchmarkIPC(): array
    {
        $start = microtime(true);
        $success = 0;
        $failed = 0;

        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        $messages = 100;

        for ($i = 0; $i < $messages; $i++) {
            $data = serialize(['id' => $i, 'time' => microtime(true)]);

            if (socket_write($sockets[0], $data)) {
                $success++;
            } else {
                $failed++;
            }
        }

        socket_close($sockets[0]);
        socket_close($sockets[1]);

        return [
            'messages' => $messages,
            'success' => $success,
            'failed' => $failed,
            'time' => microtime(true) - $start,
            'throughput' => $messages / (microtime(true) - $start),
        ];
    }

    private function benchmarkSignal(): array
    {
        $start = microtime(true);
        $handled = 0;

        pcntl_signal(SIGUSR1, function () use (&$handled) {
            $handled++;
        });

        $pid = posix_getpid();

        for ($i = 0; $i < 100; $i++) {
            posix_kill($pid, SIGUSR1);
            pcntl_signal_dispatch();
        }

        return [
            'signals' => 100,
            'handled' => $handled,
            'time' => microtime(true) - $start,
        ];
    }

    private function benchmarkResponse(): array
    {
        $start = microtime(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            Response::ok(['index' => $i])->toArray();
            Response::error('test')->toArray();
        }

        return [
            'iterations' => $this->iterations * 2,
            'time' => microtime(true) - $start,
            'ops_per_sec' => ($this->iterations * 2) / (microtime(true) - $start),
        ];
    }

    private function benchmarkMemory(): array
    {
        $start = microtime(true);
        $initial = memory_get_usage(true);

        $data = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $data[] = str_repeat('x', 1024);
        }

        $peak = memory_get_usage(true);
        unset($data);

        return [
            'initial_memory' => $initial,
            'peak_memory' => $peak,
            'memory_per_op' => ($peak - $initial) / $this->iterations,
            'time' => microtime(true) - $start,
        ];
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function printReport(): void
    {
        echo "\n=== 性能基准测试报告 ===\n\n";

        foreach ($this->results as $name => $result) {
            echo "【{$name}】\n";
            foreach ($result as $key => $value) {
                if (is_float($value)) {
                    printf("  %-15s: %.6f\n", $key, $value);
                } else {
                    printf("  %-15s: %s\n", $key, number_format($value));
                }
            }
            echo "\n";
        }
    }

    public static function quick(): array
    {
        $benchmark = new self(100);
        $benchmark->run();
        $benchmark->printReport();
        return $benchmark->getResults();
    }
}
