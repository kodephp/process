<?php

declare(strict_types=1);

namespace Kode\Process\Benchmark;

use Kode\Process\Fiber\FiberPool;
use Kode\Process\Fiber\FiberScheduler;
use Kode\Process\Response;

final class FiberBenchmark
{
    private array $results = [];
    private int $iterations;
    private int $warmup;

    public function __construct(int $iterations = 10000, int $warmup = 100)
    {
        $this->iterations = $iterations;
        $this->warmup = $warmup;
    }

    public function run(): Response
    {
        $this->results = [];

        $this->benchmarkFiberCreation();
        $this->benchmarkFiberExecution();
        $this->benchmarkFiberContextSwitch();
        $this->benchmarkFiberPool();
        $this->benchmarkFiberSleep();
        $this->benchmarkFiberIO();
        $this->benchmarkConcurrent();

        $this->compareWithAlternatives();

        return Response::ok([
            'results' => $this->results,
            'summary' => $this->getSummary(),
            'comparison' => $this->results['comparison'] ?? [],
        ]);
    }

    private function benchmarkFiberCreation(): void
    {
        $this->warmup(function () {
            $fiber = new \Fiber(fn() => 1);
        });

        $start = microtime(true);
        $memoryBefore = memory_get_usage();

        for ($i = 0; $i < $this->iterations; $i++) {
            $fiber = new \Fiber(fn() => 1);
        }

        $end = microtime(true);
        $memoryAfter = memory_get_usage();

        $this->results['fiber_creation'] = [
            'iterations' => $this->iterations,
            'total_time' => round(($end - $start) * 1000, 3),
            'avg_time' => round((($end - $start) / $this->iterations) * 1000000, 3),
            'ops_per_sec' => (int) ($this->iterations / ($end - $start)),
            'memory_used' => $memoryAfter - $memoryBefore,
            'memory_per_fiber' => (int) (($memoryAfter - $memoryBefore) / $this->iterations),
        ];
    }

    private function benchmarkFiberExecution(): void
    {
        $this->warmup(function () {
            $fiber = new \Fiber(fn() => 1);
            $fiber->start();
        });

        $start = microtime(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            $fiber = new \Fiber(fn() => $i * 2);
            $fiber->start();
        }

        $end = microtime(true);

        $this->results['fiber_execution'] = [
            'iterations' => $this->iterations,
            'total_time' => round(($end - $start) * 1000, 3),
            'avg_time' => round((($end - $start) / $this->iterations) * 1000000, 3),
            'ops_per_sec' => (int) ($this->iterations / ($end - $start)),
        ];
    }

    private function benchmarkFiberContextSwitch(): void
    {
        $switches = $this->iterations;
        $counter = 0;

        $fiber = new \Fiber(function () use (&$counter, $switches) {
            for ($i = 0; $i < $switches; $i++) {
                $counter++;
                \Fiber::suspend();
            }
        });

        $start = microtime(true);
        $fiber->start();

        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        $end = microtime(true);

        $this->results['fiber_context_switch'] = [
            'switches' => $switches,
            'total_time' => round(($end - $start) * 1000, 3),
            'avg_time' => round((($end - $start) / $switches) * 1000000, 3),
            'switches_per_sec' => (int) ($switches / ($end - $start)),
        ];
    }

    private function benchmarkFiberPool(): void
    {
        FiberScheduler::reset();
        $pool = new FiberPool(100);

        $start = microtime(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            $pool->submit(fn($n) => $n * 2, $i);
        }

        $pool->wait();

        $end = microtime(true);

        $this->results['fiber_pool'] = [
            'tasks' => $this->iterations,
            'total_time' => round(($end - $start) * 1000, 3),
            'avg_time' => round((($end - $start) / $this->iterations) * 1000, 3),
            'tasks_per_sec' => (int) ($this->iterations / ($end - $start)),
        ];
    }

    private function benchmarkFiberSleep(): void
    {
        FiberScheduler::reset();
        $scheduler = FiberScheduler::getInstance();

        $sleepCount = 1000;
        $sleepDuration = 0.001;

        $start = microtime(true);

        for ($i = 0; $i < $sleepCount; $i++) {
            $scheduler->create(function () use ($sleepDuration) {
                $start = microtime(true);

                while ((microtime(true) - $start) < $sleepDuration) {
                    \Fiber::suspend();
                }

                return true;
            });
        }

        $scheduler->run();

        $end = microtime(true);

        $this->results['fiber_sleep'] = [
            'sleep_count' => $sleepCount,
            'sleep_duration' => $sleepDuration,
            'total_time' => round(($end - $start) * 1000, 3),
            'overhead' => round((($end - $start) - ($sleepCount * $sleepDuration)) * 1000, 3),
        ];
    }

    private function benchmarkFiberIO(): void
    {
        FiberScheduler::reset();
        $pool = new FiberPool(50);

        $ioTasks = 100;

        $start = microtime(true);

        for ($i = 0; $i < $ioTasks; $i++) {
            $pool->submit(function () {
                $start = microtime(true);

                while ((microtime(true) - $start) < 0.01) {
                    \Fiber::suspend();
                }

                return ['status' => 'ok'];
            });
        }

        $pool->wait();

        $end = microtime(true);

        $this->results['fiber_io'] = [
            'tasks' => $ioTasks,
            'total_time' => round(($end - $start) * 1000, 3),
            'concurrent_efficiency' => round(($ioTasks * 10) / ($end - $start), 2),
        ];
    }

    private function benchmarkConcurrent(): void
    {
        FiberScheduler::reset();

        $concurrencyLevels = [10, 100, 1000, 5000];
        $results = [];

        foreach ($concurrencyLevels as $level) {
            $pool = new FiberPool($level);

            $start = microtime(true);

            for ($i = 0; $i < $this->iterations; $i++) {
                $pool->submit(fn($n) => $n * 2, $i);
            }

            $pool->wait();

            $end = microtime(true);

            $results[$level] = [
                'total_time' => round(($end - $start) * 1000, 3),
                'ops_per_sec' => (int) ($this->iterations / ($end - $start)),
            ];
        }

        $this->results['concurrent'] = $results;
    }

    private function compareWithAlternatives(): void
    {
        $fiberCreation = $this->results['fiber_creation']['ops_per_sec'] ?? 0;
        $fiberExecution = $this->results['fiber_execution']['ops_per_sec'] ?? 0;
        $contextSwitch = $this->results['fiber_context_switch']['switches_per_sec'] ?? 0;

        $this->results['comparison'] = [
            'fiber_creation' => [
                'kode/process' => $fiberCreation,
                'swoole_coroutine' => '~800000',
                'workerman' => 'N/A (进程模型)',
                'swow' => '~900000',
            ],
            'fiber_execution' => [
                'kode/process' => $fiberExecution,
                'swoole_coroutine' => '~600000',
                'workerman' => 'N/A',
                'swow' => '~700000',
            ],
            'context_switch' => [
                'kode/process' => $contextSwitch,
                'swoole_coroutine' => '~2000000',
                'swow' => '~2500000',
            ],
            'memory_per_fiber' => [
                'kode/process' => $this->results['fiber_creation']['memory_per_fiber'] ?? 0,
                'swoole_coroutine' => '~8KB',
                'swow' => '~4KB',
            ],
        ];
    }

    private function warmup(callable $callback): void
    {
        for ($i = 0; $i < $this->warmup; $i++) {
            $callback();
        }
    }

    private function getSummary(): array
    {
        return [
            'fiber_creation_rate' => ($this->results['fiber_creation']['ops_per_sec'] ?? 0) . ' ops/sec',
            'fiber_execution_rate' => ($this->results['fiber_execution']['ops_per_sec'] ?? 0) . ' ops/sec',
            'context_switch_rate' => ($this->results['fiber_context_switch']['switches_per_sec'] ?? 0) . ' switches/sec',
            'memory_per_fiber' => ($this->results['fiber_creation']['memory_per_fiber'] ?? 0) . ' bytes',
            'optimal_concurrency' => $this->findOptimalConcurrency(),
        ];
    }

    private function findOptimalConcurrency(): int
    {
        $concurrent = $this->results['concurrent'] ?? [];

        if (empty($concurrent)) {
            return 100;
        }

        $best = 100;
        $bestOps = 0;

        foreach ($concurrent as $level => $data) {
            if ($data['ops_per_sec'] > $bestOps) {
                $bestOps = $data['ops_per_sec'];
                $best = (int) $level;
            }
        }

        return $best;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
