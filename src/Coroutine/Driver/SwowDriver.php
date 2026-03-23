<?php

declare(strict_types=1);

namespace Kode\Process\Coroutine\Driver;

use Kode\Process\Coroutine\CoroutineDriverInterface;
use Kode\Process\Coroutine\ChannelInterface;
use Kode\Process\Coroutine\WaitGroupInterface;
use RuntimeException;

/**
 * Swow 协程驱动
 * 
 * 需要安装 Swow 扩展：pecl install swow
 * 或通过 Composer：composer require swow/swow
 */
final class SwowDriver implements CoroutineDriverInterface
{
    public function __construct()
    {
        if (!extension_loaded('swow')) {
            throw new RuntimeException(
                "Swow 扩展未安装。请安装后重试：\n" .
                "  pecl install swow\n" .
                "或\n" .
                "  composer require swow/swow\n" .
                "  php vendor/bin/swow-builder --install"
            );
        }
    }

    public function getName(): string
    {
        return 'swow';
    }

    public function go(callable $callback): mixed
    {
        $coroutine = new \Swow\Coroutine($callback);
        return $coroutine->resume();
    }

    public function batch(array $items, callable $callback, int $concurrency = 10): array
    {
        $results = [];
        $channel = new \Swow\Channel($concurrency);

        // 生产者
        \Swow\Coroutine::run(function () use ($items, $channel) {
            foreach ($items as $key => $item) {
                $channel->push(['key' => $key, 'item' => $item]);
            }
            $channel->close();
        });

        // 消费者
        $workers = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $workers[] = \Swow\Coroutine::run(function () use ($channel, $callback, &$results) {
                while ($data = $channel->pop()) {
                    $key = $data['key'];
                    $item = $data['item'];
                    $results[$key] = $callback($item, $key);
                }
            });
        }

        // 等待所有消费者完成
        foreach ($workers as $worker) {
            $worker->join();
        }

        return $results;
    }

    public function sleep(float $seconds): void
    {
        \Swow\Coroutine::sleep($seconds);
    }

    public function getCurrentId(): int
    {
        $coroutine = \Swow\Coroutine::getCurrent();
        return $coroutine ? $coroutine->getId() : 0;
    }

    public function inCoroutine(): bool
    {
        return \Swow\Coroutine::getCurrent() !== null;
    }

    public function createChannel(int $capacity = 0): ChannelInterface
    {
        return new class($capacity) implements ChannelInterface {
            private \Swow\Channel $channel;
            private bool $closed = false;

            public function __construct(int $capacity)
            {
                $this->channel = new \Swow\Channel($capacity);
            }

            public function push(mixed $data, float $timeout = -1): bool
            {
                if ($this->closed) {
                    return false;
                }

                try {
                    $this->channel->push($data, $timeout > 0 ? (int)($timeout * 1000) : -1);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }

            public function pop(float $timeout = -1): mixed
            {
                try {
                    return $this->channel->pop($timeout > 0 ? (int)($timeout * 1000) : -1);
                } catch (\Throwable) {
                    return null;
                }
            }

            public function close(): void
            {
                $this->closed = true;
                $this->channel->close();
            }

            public function getCapacity(): int
            {
                return $this->channel->getCapacity();
            }

            public function getLength(): int
            {
                return $this->channel->getLength();
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
            private \Swow\Sync\WaitGroup $waitGroup;

            public function __construct()
            {
                $this->waitGroup = new \Swow\Sync\WaitGroup();
            }

            public function add(int $delta = 1): void
            {
                for ($i = 0; $i < $delta; $i++) {
                    $this->waitGroup->add();
                }
            }

            public function done(): void
            {
                $this->waitGroup->done();
            }

            public function wait(float $timeout = -1): bool
            {
                try {
                    $this->waitGroup->wait($timeout > 0 ? (int)($timeout * 1000) : -1);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }

            public function getCount(): int
            {
                return 0;
            }
        };
    }
}
