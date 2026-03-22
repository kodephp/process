<?php

declare(strict_types=1);

namespace Kode\Process\Fiber;

use Kode\Process\Response;

final class FiberPool
{
    private FiberScheduler $scheduler;
    private int $concurrency;
    private array $tasks = [];
    private array $results = [];
    private int $taskId = 0;

    public function __construct(int $concurrency = 100)
    {
        $this->scheduler = FiberScheduler::getInstance();
        $this->concurrency = $concurrency;
    }

    public function submit(callable $task, mixed ...$args): int
    {
        $id = ++$this->taskId;

        $this->tasks[$id] = [
            'task' => $task,
            'args' => $args,
            'status' => 'pending',
            'fiber_id' => null,
        ];

        return $id;
    }

    public function submitAll(array $tasks): array
    {
        $ids = [];

        foreach ($tasks as $task) {
            if (is_callable($task)) {
                $ids[] = $this->submit($task);
            } elseif (is_array($task) && isset($task[0]) && is_callable($task[0])) {
                $ids[] = $this->submit($task[0], ...($task[1] ?? []));
            }
        }

        return $ids;
    }

    public function map(array $items, callable $callback): array
    {
        $ids = [];

        foreach ($items as $key => $item) {
            $ids[$key] = $this->submit($callback, $item, $key);
        }

        $this->wait();

        $results = [];

        foreach ($ids as $key => $id) {
            $results[$key] = $this->getResult($id);
        }

        return $results;
    }

    public function each(array $items, callable $callback): void
    {
        foreach ($items as $key => $item) {
            $this->submit($callback, $item, $key);
        }

        $this->wait();
    }

    public function parallel(callable ...$tasks): array
    {
        $ids = $this->submitAll($tasks);
        $this->wait();

        $results = [];

        foreach ($ids as $id) {
            $results[] = $this->getResult($id);
        }

        return $results;
    }

    public function race(callable ...$tasks): mixed
    {
        $ids = $this->submitAll($tasks);

        while (true) {
            $this->scheduler->tick();

            foreach ($ids as $id) {
                $status = $this->getStatus($id);

                if ($status === 'completed') {
                    return $this->getResult($id);
                }

                if ($status === 'failed') {
                    throw $this->getError($id);
                }
            }

            usleep(1000);
        }
    }

    public function any(callable ...$tasks): mixed
    {
        $ids = $this->submitAll($tasks);
        $errors = [];

        while (true) {
            $this->scheduler->tick();

            foreach ($ids as $id) {
                $status = $this->getStatus($id);

                if ($status === 'completed') {
                    return $this->getResult($id);
                }

                if ($status === 'failed') {
                    $errors[] = $this->getError($id);
                }
            }

            if (count($errors) === count($ids)) {
                throw new \RuntimeException('所有任务都失败了', 0, $errors[0]);
            }

            usleep(1000);
        }
    }

    public function all(callable ...$tasks): array
    {
        $ids = $this->submitAll($tasks);
        $this->wait();

        $results = [];

        foreach ($ids as $id) {
            $status = $this->getStatus($id);

            if ($status === 'failed') {
                throw $this->getError($id);
            }

            $results[] = $this->getResult($id);
        }

        return $results;
    }

    public function wait(?float $timeout = null): void
    {
        $startTime = microtime(true);

        while (count($this->tasks) > 0) {
            $this->processTasks();
            $this->scheduler->tick();

            if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                throw new \RuntimeException('等待超时');
            }

            usleep(1000);
        }
    }

    public function waitOne(int $id, ?float $timeout = null): mixed
    {
        $startTime = microtime(true);

        while (true) {
            $this->processTasks();
            $this->scheduler->tick();

            $status = $this->getStatus($id);

            if ($status === 'completed') {
                return $this->getResult($id);
            }

            if ($status === 'failed') {
                throw $this->getError($id);
            }

            if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                throw new \RuntimeException('等待超时');
            }

            usleep(1000);
        }
    }

    public function getStatus(int $id): ?string
    {
        if (!isset($this->tasks[$id])) {
            return null;
        }

        $task = $this->tasks[$id];

        if ($task['status'] === 'pending') {
            return 'pending';
        }

        if ($task['fiber_id'] !== null) {
            return $this->scheduler->getStatus($task['fiber_id']);
        }

        return null;
    }

    public function getResult(int $id): mixed
    {
        if (!isset($this->tasks[$id])) {
            return null;
        }

        $task = $this->tasks[$id];

        if ($task['fiber_id'] !== null) {
            return $this->scheduler->getResult($task['fiber_id']);
        }

        return null;
    }

    public function getError(int $id): ?\Throwable
    {
        if (!isset($this->tasks[$id])) {
            return null;
        }

        $task = $this->tasks[$id];

        if ($task['fiber_id'] !== null) {
            return $this->scheduler->getError($task['fiber_id']);
        }

        return null;
    }

    public function cancel(int $id): bool
    {
        if (!isset($this->tasks[$id])) {
            return false;
        }

        $task = $this->tasks[$id];

        if ($task['fiber_id'] !== null) {
            $this->scheduler->cancel($task['fiber_id']);
        }

        unset($this->tasks[$id]);

        return true;
    }

    public function getStats(): array
    {
        $pending = 0;
        $running = 0;
        $completed = 0;
        $failed = 0;

        foreach ($this->tasks as $task) {
            $status = $task['status'] === 'pending' ? 'pending' : $this->scheduler->getStatus($task['fiber_id']);

            match ($status) {
                'pending' => $pending++,
                'running' => $running++,
                'completed' => $completed++,
                'failed' => $failed++,
                default => null,
            };
        }

        return [
            'pending' => $pending,
            'running' => $running,
            'completed' => $completed,
            'failed' => $failed,
            'total' => count($this->tasks),
            'concurrency' => $this->concurrency,
        ];
    }

    public function setConcurrency(int $concurrency): self
    {
        $this->concurrency = $concurrency;
        return $this;
    }

    public function clear(): void
    {
        $this->tasks = [];
        $this->results = [];
    }

    private function processTasks(): void
    {
        $running = $this->countRunning();

        foreach ($this->tasks as $id => $task) {
            if ($task['status'] !== 'pending') {
                continue;
            }

            if ($running >= $this->concurrency) {
                break;
            }

            $fiberId = $this->scheduler->create($task['task'], ...$task['args']);
            $this->tasks[$id]['status'] = 'running';
            $this->tasks[$id]['fiber_id'] = $fiberId;
            $this->scheduler->start($fiberId);

            $running++;
        }
    }

    private function countRunning(): int
    {
        $count = 0;

        foreach ($this->tasks as $task) {
            if ($task['status'] === 'running') {
                $status = $this->scheduler->getStatus($task['fiber_id']);

                if ($status === 'running') {
                    $count++;
                }
            }
        }

        return $count;
    }
}
