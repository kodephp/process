<?php

declare(strict_types=1);

namespace Kode\Process\Coroutine\Driver;

use Kode\Process\Coroutine\CoroutineDriverInterface;
use Kode\Process\Coroutine\ChannelInterface;
use Kode\Process\Coroutine\WaitGroupInterface;
use Kode\Fibers\Fibers;

/**
 * kode/fibers 协程驱动
 */
final class FibersDriver implements CoroutineDriverInterface
{
    private int $coroutineId = 0;

    public function getName(): string
    {
        return 'kode/fibers';
    }

    public function go(callable $callback): mixed
    {
        return Fibers::go($callback);
    }

    public function batch(array $items, callable $callback, int $concurrency = 10): array
    {
        return Fibers::batch($items, $callback, $concurrency);
    }

    public function sleep(float $seconds): void
    {
        Fibers::sleep($seconds);
    }

    public function getCurrentId(): int
    {
        return $this->coroutineId++;
    }

    public function inCoroutine(): bool
    {
        return Fibers::inFiber();
    }

    public function createChannel(int $capacity = 0): ChannelInterface
    {
        return new class($capacity) implements ChannelInterface {
            private array $queue = [];
            private bool $closed = false;
            private int $capacity;

            public function __construct(int $capacity)
            {
                $this->capacity = $capacity;
            }

            public function push(mixed $data, float $timeout = -1): bool
            {
                if ($this->closed) {
                    return false;
                }

                if ($this->capacity > 0 && count($this->queue) >= $this->capacity) {
                    return false;
                }

                $this->queue[] = $data;
                return true;
            }

            public function pop(float $timeout = -1): mixed
            {
                if (empty($this->queue) && $this->closed) {
                    return null;
                }

                return array_shift($this->queue);
            }

            public function close(): void
            {
                $this->closed = true;
            }

            public function getCapacity(): int
            {
                return $this->capacity;
            }

            public function getLength(): int
            {
                return count($this->queue);
            }

            public function isClosed(): bool
            {
                return $this->closed;
            }
        };
    }

    public function createWaitGroup(): WaitGroupInterface
    {
        return new class implements WaitGroupInterface {
            private int $count = 0;

            public function add(int $delta = 1): void
            {
                $this->count += $delta;
            }

            public function done(): void
            {
                $this->count = max(0, $this->count - 1);
            }

            public function wait(float $timeout = -1): bool
            {
                $start = microtime(true);
                while ($this->count > 0) {
                    if ($timeout > 0 && (microtime(true) - $start) >= $timeout) {
                        return false;
                    }
                    usleep(1000);
                }
                return true;
            }

            public function getCount(): int
            {
                return $this->count;
            }
        };
    }
}
