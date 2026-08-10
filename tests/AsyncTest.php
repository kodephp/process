<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Async\Async;
use Kode\Process\Async\EventEmitter;
use Kode\Process\Async\Promise;
use PHPUnit\Framework\TestCase;

final class AsyncTest extends TestCase
{
    protected function tearDown(): void
    {
        Async::reset();
    }

    public function testDefer(): void
    {
        $called = false;

        Async::defer(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);

        Async::runDeferred();

        $this->assertTrue($called);
    }

    public function testDeferredRunsInFifoOrder(): void
    {
        $order = [];

        Async::defer(function () use (&$order): void {
            $order[] = 'first';
        });
        Async::defer(function () use (&$order): void {
            $order[] = 'second';
        });

        Async::runDeferred();

        $this->assertSame(['first', 'second'], $order);
    }

    public function testQueueMicrotask(): void
    {
        $called = false;

        Async::queueMicrotask(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);

        Async::runMicrotasks();

        $this->assertTrue($called);
    }

    public function testNextTickIsMicrotask(): void
    {
        $called = false;

        Async::nextTick(function () use (&$called): void {
            $called = true;
        });

        Async::runMicrotasks();

        $this->assertTrue($called);
    }

    public function testEventEmission(): void
    {
        $received = null;

        Async::on('custom_event', function ($data) use (&$received): void {
            $received = $data;
        });

        Async::emit('custom_event', ['message' => 'hello']);

        $this->assertSame(['message' => 'hello'], $received);
    }

    public function testOnceFiresOnlyOnce(): void
    {
        $count = 0;

        Async::once('tick', function () use (&$count): void {
            $count++;
        });

        Async::emit('tick');
        Async::emit('tick');

        $this->assertSame(1, $count);
    }

    public function testOffRemovesListener(): void
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };

        Async::on('evt', $listener);
        Async::off('evt', $listener);
        Async::emit('evt');

        $this->assertSame(0, $count);
    }

    public function testGetEmitterReturnsSharedInstance(): void
    {
        $this->assertInstanceOf(EventEmitter::class, Async::getEmitter());
        $this->assertSame(Async::getEmitter(), Async::getEmitter());
    }

    public function testPromisify(): void
    {
        $asyncFunc = Async::promisify(function ($value, $callback): void {
            $callback(null, $value * 2);
        });

        $promise = $asyncFunc(5);
        $result = null;

        $promise->then(function ($value) use (&$result): void {
            $result = $value;
        });

        Async::runMicrotasks();

        $this->assertInstanceOf(Promise::class, $promise);
        $this->assertSame(10, $result);
    }

    public function testPromisifyRejectsOnError(): void
    {
        $asyncFunc = Async::promisify(function ($callback): void {
            $callback(new \RuntimeException('failed'), null);
        });

        $reason = null;

        $asyncFunc()->catch(function ($r) use (&$reason): void {
            $reason = $r;
        });

        Async::runMicrotasks();

        $this->assertInstanceOf(\RuntimeException::class, $reason);
    }

    public function testMapTransformsItems(): void
    {
        $result = null;

        Async::map([1, 2, 3], fn (int $n): int => $n * $n)
            ->then(function ($values) use (&$result): void {
                $result = $values;
            });

        Async::runMicrotasks();

        $this->assertSame([1, 4, 9], $result);
    }

    public function testFilterKeepsMatchingItems(): void
    {
        $result = null;

        Async::filter([1, 2, 3, 4], fn (int $n): bool => $n % 2 === 0)
            ->then(function ($values) use (&$result): void {
                $result = array_values($values);
            });

        Async::runMicrotasks();

        $this->assertSame([2, 4], $result);
    }

    public function testReduceAccumulates(): void
    {
        $result = null;

        Async::reduce([1, 2, 3, 4], fn ($carry, $item) => $carry + $item, 0)
            ->then(function ($value) use (&$result): void {
                $result = $value;
            });

        Async::runMicrotasks();

        $this->assertSame(10, $result);
    }

    public function testClearTimeoutPreventsExecution(): void
    {
        $called = false;

        $id = Async::setTimeout(function () use (&$called): void {
            $called = true;
        }, 0.0);

        Async::clearTimeout($id);
        Async::processTimers();

        $this->assertFalse($called);
    }

    public function testWaitReturnsTrueWhenConditionMet(): void
    {
        $this->assertTrue(Async::wait(fn (): bool => true, 0.1, 0.001));
    }

    public function testWaitTimesOut(): void
    {
        $this->assertFalse(Async::wait(fn (): bool => false, 0.02, 0.005));
    }

    public function testGetStatusStructure(): void
    {
        $status = Async::getStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('microtask_count', $status);
        $this->assertArrayHasKey('deferred_count', $status);
        $this->assertArrayHasKey('timer_count', $status);
        $this->assertArrayHasKey('interval_count', $status);
        $this->assertArrayHasKey('running', $status);
        $this->assertFalse($status['running']);
    }

    public function testResetClearsQueues(): void
    {
        Async::defer(fn () => null);
        Async::queueMicrotask(fn () => null);

        Async::reset();

        $status = Async::getStatus();

        $this->assertSame(0, $status['microtask_count']);
        $this->assertSame(0, $status['deferred_count']);
    }

    public function testTickDrivesTimers(): void
    {
        $called = false;

        Async::setTimeout(function () use (&$called): void {
            $called = true;
        }, 0.0);

        // tick() 是事件循环的唯一推进点，必须自己驱动定时器，
        // 否则 setTimeout 只有在调用方额外手动调 processTimers() 时才会触发
        Async::tick();

        $this->assertTrue($called);
    }

    public function testRunExecutesTimeoutAndInterval(): void
    {
        $timeoutFired = false;
        $intervalCount = 0;

        $intervalId = Async::setInterval(function () use (&$intervalCount): void {
            $intervalCount++;
        }, 0.01);

        Async::setTimeout(function () use (&$timeoutFired): void {
            $timeoutFired = true;
            Async::stop();
        }, 0.05);

        Async::run();
        Async::clearInterval($intervalId);

        $this->assertTrue($timeoutFired, 'setTimeout 必须能在 Async::run() 下触发');
        $this->assertGreaterThan(0, $intervalCount, 'setInterval 必须能在 Async::run() 下触发');
    }

    public function testSetImmediateFiresOnNextTick(): void
    {
        $called = false;

        Async::setImmediate(function () use (&$called): void {
            $called = true;
        });

        Async::tick();

        $this->assertTrue($called);
    }

    public function testMapRespectsConfiguredConcurrency(): void
    {
        Async::reset();

        $concurrency = 4;
        $items = range(1, 12);
        $maxConcurrent = 0;
        $running = 0;

        // 回调返回 Promise 才会真正占用并发名额（同步返回值会被立即结算，不走限流）。
        $promise = Async::map($items, function ($item) use (&$maxConcurrent, &$running) {
            $running++;
            $maxConcurrent = max($maxConcurrent, $running);

            return new Promise(function ($resolve) use (&$running, $item) {
                Async::defer(function () use (&$running, $resolve, $item) {
                    $running--;
                    $resolve($item);
                });
            });
        }, $concurrency);

        $promise->await();

        // #204：此前 use 列表漏掉 $concurrency，循环写死 10，map(…, 4) 实际峰值=10。
        $this->assertSame($concurrency, $maxConcurrent);

        Async::reset();
    }

    public function testProcessTimersSkipsFutureTimers(): void
    {
        Async::reset();

        $fired = 0;
        Async::setTimeout(function () use (&$fired): void {
            $fired++;
        }, 3600.0);

        // 远未来定时器不应触发；processTimers 在 O(1) 提前返回路径下保持语义正确。
        Async::processTimers();

        $this->assertSame(0, $fired);
        $this->assertSame(1, Async::getStatus()['timer_count']);

        Async::reset();
    }

    /**
     * queueMicrotask 必须支持携带参数并以 ($cb)(...$args) 形式调用，
     * 这样 Promise 决议热路径才能避免「只为转发一个值」而闭包包裹。
     * 不定参时行为保持旧版一致。
     */
    public function testQueueMicrotaskForwardsArguments(): void
    {
        Async::reset();

        $captured = [];
        Async::queueMicrotask(function (int $a, string $b): void {
            $GLOBALS['__mt_capture'] = func_get_args();
        }, 42, 'hello');

        $this->assertSame([], $captured); // 尚未执行

        Async::runMicrotasks();

        $this->assertSame([42, 'hello'], $GLOBALS['__mt_capture'] ?? null);

        // 不定参等价旧行为
        Async::queueMicrotask(function (): void {
            $GLOBALS['__mt_noarg'] = true;
        });
        Async::runMicrotasks();
        $this->assertTrue($GLOBALS['__mt_noarg']);

        Async::reset();
        unset($GLOBALS['__mt_capture'], $GLOBALS['__mt_noarg']);
    }
}
