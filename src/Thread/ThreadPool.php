<?php

declare(strict_types=1);

namespace Kode\Process\Thread;

use Kode\Process\Contracts\ThreadPoolInterface;
use Kode\Process\Exceptions\ThreadException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 线程池
 * 
 * 管理线程资源的线程池实现
 */
class ThreadPool implements ThreadPoolInterface
{
    private int $size;

    private array $threads = [];

    private array $tasks = [];

    private array $results = [];

    private LoggerInterface $logger;

    private int $taskCounter = 0;

    private bool $shutdown = false;

    public function __construct(int $size = 4, ?LoggerInterface $logger = null)
    {
        $this->size = $size;
        $this->logger = $logger ?? new NullLogger();

        if (!extension_loaded('pthreads')) {
            $this->logger->warning('pthreads 扩展未加载，线程池将使用模拟模式');
        }
    }

    public function submit(callable $task): mixed
    {
        $taskId = $this->addTask($task);

        $thread = $this->getAvailableThread();

        if ($thread === null) {
            $thread = $this->createThread();
        }

        if ($thread === null) {
            throw ThreadException::noAvailableThread();
        }

        $thread->start();

        $result = $thread->execute();

        $thread->join();

        $this->results[$taskId] = $result;

        return $result;
    }

    public function submitAsync(callable $task): string
    {
        $taskId = $this->addTask($task);

        $thread = $this->getAvailableThread();

        if ($thread === null) {
            $thread = $this->createThread();
        }

        if ($thread === null) {
            throw ThreadException::poolFull($this->size);
        }

        $thread->start();

        $this->tasks[$taskId] = $thread;

        $this->logger->debug('异步任务已提交', ['task_id' => $taskId]);

        return $taskId;
    }

    public function map(array $data, callable $transform): array
    {
        $results = [];

        foreach ($data as $key => $item) {
            $results[$key] = $this->submit(fn() => $transform($item));
        }

        return $results;
    }

    public function reduce(array $data, callable $reducer, mixed $initial = null): mixed
    {
        $result = $initial;

        foreach ($data as $item) {
            $result = $this->submit(fn() => $reducer($result, $item));
        }

        return $result;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getActiveCount(): int
    {
        $count = 0;

        foreach ($this->threads as $thread) {
            if ($thread->isRunning()) {
                $count++;
            }
        }

        return $count;
    }

    public function getIdleCount(): int
    {
        return $this->size - $this->getActiveCount();
    }

    public function resize(int $size): void
    {
        $this->size = $size;

        $this->logger->info('线程池大小已调整', ['size' => $size]);
    }

    public function shutdown(bool $waitForCompletion = true): void
    {
        $this->shutdown = true;

        if ($waitForCompletion) {
            foreach ($this->threads as $thread) {
                if ($thread->isRunning()) {
                    $thread->join();
                }
            }
        } else {
            foreach ($this->threads as $thread) {
                if ($thread->isRunning()) {
                    $thread->kill();
                }
            }
        }

        $this->threads = [];
        $this->tasks = [];

        $this->logger->info('线程池已关闭');
    }

    public function awaitCompletion(string $taskId, ?float $timeout = null): mixed
    {
        if (!isset($this->tasks[$taskId])) {
            if (isset($this->results[$taskId])) {
                return $this->results[$taskId];
            }

            throw ThreadException::invalidState('pending', 'unknown');
        }

        $thread = $this->tasks[$taskId];
        $startTime = microtime(true);

        while ($thread->isRunning()) {
            if ($timeout !== null && (microtime(true) - $startTime) > $timeout) {
                throw ThreadException::threadTimeout($thread->getId(), $timeout);
            }

            usleep(1000);
        }

        $thread->join();

        $result = $thread->getResult();
        $this->results[$taskId] = $result;

        unset($this->tasks[$taskId]);

        return $result;
    }

    public function getQueueSize(): int
    {
        return count($this->tasks);
    }

    public function getCompletedCount(): int
    {
        return count($this->results);
    }

    public function getFailedCount(): int
    {
        $count = 0;

        foreach ($this->threads as $thread) {
            if ($thread->getException() !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function addTask(callable $task): string
    {
        return 'task_' . (++$this->taskCounter) . '_' . uniqid();
    }

    private function createThread(): ?Thread
    {
        if (count($this->threads) >= $this->size) {
            return null;
        }

        $thread = new Thread(fn() => null);
        $thread->setLogger($this->logger);

        $this->threads[$thread->getId()] = $thread;

        return $thread;
    }

    private function getAvailableThread(): ?Thread
    {
        foreach ($this->threads as $thread) {
            if (!$thread->isRunning() && !$thread->isJoined()) {
                return $thread;
            }
        }

        return null;
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }
}
