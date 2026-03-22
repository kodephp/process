<?php

declare(strict_types=1);

namespace Kode\Process\Fiber;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;

final class FiberScheduler
{
    private static ?self $instance = null;

    private array $fibers = [];
    private array $queue = [];
    private array $sleeping = [];
    private array $ioWaiting = [];
    private int $maxFibers = 10000;
    private int $tickInterval = 1000;
    private bool $running = false;
    private float $lastTick = 0.0;
    private int $fiberId = 0;
    private array $stats = [
        'created' => 0,
        'completed' => 0,
        'failed' => 0,
        'peak' => 0,
    ];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function create(callable $callback, mixed ...$args): int
    {
        if (count($this->fibers) >= $this->maxFibers) {
            throw new \RuntimeException('达到最大 Fiber 数量限制');
        }

        $id = ++$this->fiberId;

        $fiber = new \Fiber(function () use ($id, $callback, $args) {
            try {
                $result = $callback(...$args);
                $this->complete($id, $result);
            } catch (\Throwable $e) {
                $this->fail($id, $e);
            }
        });

        $this->fibers[$id] = [
            'fiber' => $fiber,
            'status' => 'pending',
            'created_at' => microtime(true),
            'result' => null,
            'error' => null,
        ];

        $this->queue[] = $id;
        $this->stats['created']++;
        $this->stats['peak'] = max($this->stats['peak'], count($this->fibers));

        return $id;
    }

    public function start(int $id): bool
    {
        if (!isset($this->fibers[$id])) {
            return false;
        }

        $fiber = $this->fibers[$id]['fiber'];

        if ($fiber->isStarted()) {
            return false;
        }

        try {
            $fiber->start();
            $this->fibers[$id]['status'] = 'running';
            return true;
        } catch (\Throwable $e) {
            $this->fail($id, $e);
            return false;
        }
    }

    public function resume(int $id, mixed $value = null): bool
    {
        if (!isset($this->fibers[$id])) {
            return false;
        }

        $fiber = $this->fibers[$id]['fiber'];

        if (!$fiber->isStarted() || $fiber->isTerminated()) {
            return false;
        }

        try {
            $fiber->resume($value);
            return true;
        } catch (\Throwable $e) {
            $this->fail($id, $e);
            return false;
        }
    }

    public function throw(int $id, \Throwable $exception): bool
    {
        if (!isset($this->fibers[$id])) {
            return false;
        }

        $fiber = $this->fibers[$id]['fiber'];

        if (!$fiber->isStarted() || $fiber->isTerminated()) {
            return false;
        }

        try {
            $fiber->throw($exception);
            return true;
        } catch (\Throwable $e) {
            $this->fail($id, $e);
            return false;
        }
    }

    public function tick(): void
    {
        $this->lastTick = microtime(true);

        foreach ($this->queue as $i => $id) {
            if (isset($this->fibers[$id])) {
                $this->start($id);
            }
            unset($this->queue[$i]);
        }

        $this->queue = array_values($this->queue);

        $now = microtime(true);
        $wakeUp = [];

        foreach ($this->sleeping as $id => $wakeTime) {
            if ($now >= $wakeTime) {
                $wakeUp[] = $id;
            }
        }

        foreach ($wakeUp as $id) {
            unset($this->sleeping[$id]);
            $this->resume($id);
        }

        foreach ($this->fibers as $id => $data) {
            $fiber = $data['fiber'];

            if ($fiber->isTerminated()) {
                if ($fiber->isStarted()) {
                    try {
                        $result = $fiber->getReturn();
                        $this->complete($id, $result);
                    } catch (\Throwable $e) {
                        $this->fail($id, $e);
                    }
                }
            }
        }
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running && (count($this->fibers) > 0 || count($this->queue) > 0)) {
            $this->tick();

            if (count($this->fibers) > 0 || count($this->queue) > 0) {
                usleep($this->tickInterval);
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function sleep(float $seconds): void
    {
        $current = \Fiber::getCurrent();

        if ($current === null) {
            usleep((int) ($seconds * 1000000));
            return;
        }

        $id = $this->findFiberId($current);

        if ($id !== null) {
            $this->sleeping[$id] = microtime(true) + $seconds;
            \Fiber::suspend();
        }
    }

    public function wait(int $id, float $timeout = 0.0): mixed
    {
        if (!isset($this->fibers[$id])) {
            return null;
        }

        $startTime = microtime(true);

        while (true) {
            $data = $this->fibers[$id];

            if ($data['status'] === 'completed') {
                return $data['result'];
            }

            if ($data['status'] === 'failed') {
                throw $data['error'];
            }

            if ($timeout > 0 && (microtime(true) - $startTime) >= $timeout) {
                throw new \RuntimeException('等待超时');
            }

            $this->tick();
            usleep(1000);
        }
    }

    public function getStatus(int $id): ?string
    {
        return $this->fibers[$id]['status'] ?? null;
    }

    public function getResult(int $id): mixed
    {
        return $this->fibers[$id]['result'] ?? null;
    }

    public function getError(int $id): ?\Throwable
    {
        return $this->fibers[$id]['error'] ?? null;
    }

    public function count(): int
    {
        return count($this->fibers);
    }

    public function countActive(): int
    {
        return count(array_filter($this->fibers, fn($f) => $f['status'] === 'running'));
    }

    public function countPending(): int
    {
        return count($this->queue);
    }

    public function countSleeping(): int
    {
        return count($this->sleeping);
    }

    public function getStats(): array
    {
        return [
            ...$this->stats,
            'active' => $this->countActive(),
            'pending' => $this->countPending(),
            'sleeping' => $this->countSleeping(),
            'total' => $this->count(),
        ];
    }

    public function setMaxFibers(int $max): self
    {
        $this->maxFibers = $max;
        return $this;
    }

    public function setTickInterval(int $microseconds): self
    {
        $this->tickInterval = $microseconds;
        return $this;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function cancel(int $id): bool
    {
        if (!isset($this->fibers[$id])) {
            return false;
        }

        $this->fibers[$id]['status'] = 'cancelled';
        unset($this->fibers[$id], $this->sleeping[$id]);

        return true;
    }

    public function clear(): void
    {
        $this->fibers = [];
        $this->queue = [];
        $this->sleeping = [];
        $this->ioWaiting = [];
    }

    private function complete(int $id, mixed $result): void
    {
        if (isset($this->fibers[$id])) {
            $this->fibers[$id]['status'] = 'completed';
            $this->fibers[$id]['result'] = $result;
            $this->stats['completed']++;
        }
    }

    private function fail(int $id, \Throwable $e): void
    {
        if (isset($this->fibers[$id])) {
            $this->fibers[$id]['status'] = 'failed';
            $this->fibers[$id]['error'] = $e;
            $this->stats['failed']++;
        }
    }

    private function findFiberId(\Fiber $fiber): ?int
    {
        foreach ($this->fibers as $id => $data) {
            if ($data['fiber'] === $fiber) {
                return $id;
            }
        }

        return null;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->clear();
        }

        self::$instance = null;
    }
}
