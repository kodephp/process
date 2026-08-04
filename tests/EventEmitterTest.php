<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Async\EventEmitter;
use PHPUnit\Framework\TestCase;

final class EventEmitterTest extends TestCase
{
    public function testOnAndEmit(): void
    {
        $emitter = new EventEmitter();
        $called = false;

        $emitter->on('test', function () use (&$called) {
            $called = true;
        });

        $emitter->emit('test');

        $this->assertTrue($called);
    }

    public function testOnce(): void
    {
        $emitter = new EventEmitter();
        $count = 0;

        $emitter->once('test', function () use (&$count) {
            $count++;
        });

        $emitter->emit('test');
        $emitter->emit('test');

        $this->assertSame(1, $count);
    }

    public function testOff(): void
    {
        $emitter = new EventEmitter();
        $called = false;

        $listener = function () use (&$called) {
            $called = true;
        };

        $emitter->on('test', $listener);
        $emitter->off('test', $listener);
        $emitter->emit('test');

        $this->assertFalse($called);
    }

    public function testOffAll(): void
    {
        $emitter = new EventEmitter();
        $count = 0;

        $emitter->on('test', function () use (&$count) {
            $count++;
        });

        $emitter->off('test');
        $emitter->emit('test');

        $this->assertSame(0, $count);
    }

    public function testHasListeners(): void
    {
        $emitter = new EventEmitter();

        $this->assertFalse($emitter->hasListeners('test'));

        $emitter->on('test', static fn () => null);

        $this->assertTrue($emitter->hasListeners('test'));
    }

    public function testListenerCount(): void
    {
        $emitter = new EventEmitter();

        $this->assertSame(0, $emitter->listenerCount('test'));

        $emitter->on('test', static fn () => null);
        $emitter->on('test', static fn () => null);

        $this->assertSame(2, $emitter->listenerCount('test'));
    }

    public function testEventNames(): void
    {
        $emitter = new EventEmitter();

        $emitter->on('event1', static fn () => null);
        $emitter->on('event2', static fn () => null);

        $names = $emitter->eventNames();

        $this->assertContains('event1', $names);
        $this->assertContains('event2', $names);
    }

    public function testPrependListener(): void
    {
        $emitter = new EventEmitter();
        $order = [];

        $emitter->on('test', function () use (&$order) {
            $order[] = 'second';
        });

        $emitter->prependListener('test', function () use (&$order) {
            $order[] = 'first';
        });

        $emitter->emit('test');

        $this->assertSame(['first', 'second'], $order);
    }

    public function testEmitPassesArguments(): void
    {
        $emitter = new EventEmitter();
        $received = null;

        $emitter->on('data', function ($payload) use (&$received) {
            $received = $payload;
        });

        // 第二参数是「参数列表」，数组载荷需再包一层
        $emitter->emit('data', [['id' => 7]]);

        $this->assertSame(['id' => 7], $received);
    }

    public function testEmitPassesMultipleArguments(): void
    {
        $emitter = new EventEmitter();
        $received = [];

        $emitter->on('data', function ($a, $b, $c) use (&$received) {
            $received = [$a, $b, $c];
        });

        $emitter->emit('data', [1, 'two', [3]]);

        $this->assertSame([1, 'two', [3]], $received);
    }

    public function testPrependOnceListener(): void
    {
        $emitter = new EventEmitter();
        $order = [];

        $emitter->once('test', function () use (&$order) {
            $order[] = 'second';
        });
        $emitter->prependOnceListener('test', function () use (&$order) {
            $order[] = 'first';
        });

        $emitter->emit('test');
        $emitter->emit('test');

        $this->assertSame(['first', 'second'], $order);
    }

    public function testRemoveAllListeners(): void
    {
        $emitter = new EventEmitter();

        $emitter->on('a', static fn () => null);
        $emitter->once('b', static fn () => null);

        $emitter->removeAllListeners();

        $this->assertSame([], $emitter->eventNames());
    }

    public function testListenersReturnsBothKinds(): void
    {
        $emitter = new EventEmitter();

        $emitter->on('e', static fn () => null);
        $emitter->once('e', static fn () => null);

        $this->assertCount(2, $emitter->listeners('e'));
        $this->assertSame(2, $emitter->listenerCount('e'));
    }

    public function testMaxListenersAccessor(): void
    {
        $emitter = new EventEmitter();

        $this->assertSame(1000, $emitter->getMaxListeners());
        $this->assertSame(5, $emitter->setMaxListeners(5)->getMaxListeners());
    }

    /**
     * 回归：once 监听器在回调中重新注册 once，不应被本轮派发误删
     */
    public function testOnceReregisteredDuringEmitSurvives(): void
    {
        $emitter = new EventEmitter();
        $count = 0;

        $handler = function () use (&$count, &$handler, $emitter): void {
            $count++;

            if ($count === 1) {
                $emitter->once('tick', $handler);
            }
        };

        $emitter->once('tick', $handler);

        $emitter->emit('tick');
        $emitter->emit('tick');
        $emitter->emit('tick');

        $this->assertSame(2, $count);
    }

    public function testListenerErrorIsForwardedToErrorEvent(): void
    {
        $emitter = new EventEmitter();
        $caught = null;

        $emitter->on('error', function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });
        $emitter->on('boom', static function (): void {
            throw new \RuntimeException('listener failed');
        });

        $emitter->emit('boom');

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('listener failed', $caught->getMessage());
    }

    /**
     * 回归：无 error 监听器时异常不得被静默吞掉
     */
    public function testUnhandledListenerErrorRaisesWarning(): void
    {
        $emitter = new EventEmitter();

        $emitter->on('boom', static function (): void {
            throw new \RuntimeException('silent failure');
        });

        $captured = null;
        set_error_handler(function (int $no, string $msg) use (&$captured): bool {
            $captured = $msg;
            return true;
        }, E_USER_WARNING);

        try {
            $emitter->emit('boom');
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('未处理的监听器异常', $captured);
        $this->assertStringContainsString('silent failure', $captured);
    }

    /**
     * 回归：error 监听器自身抛异常不得引发无限递归
     */
    public function testThrowingErrorListenerDoesNotRecurse(): void
    {
        $emitter = new EventEmitter();
        $errorCalls = 0;

        $emitter->on('error', function () use (&$errorCalls): void {
            $errorCalls++;
            throw new \RuntimeException('error handler exploded');
        });
        $emitter->on('boom', static function (): void {
            throw new \RuntimeException('original');
        });

        $captured = null;
        set_error_handler(function (int $no, string $msg) use (&$captured): bool {
            $captured = $msg;
            return true;
        }, E_USER_WARNING);

        try {
            $emitter->emit('boom');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $errorCalls);
        $this->assertStringContainsString('error 监听器自身抛出异常', (string) $captured);
    }

    public function testEmitReturnsSelfForChaining(): void
    {
        $emitter = new EventEmitter();

        $this->assertSame($emitter, $emitter->emit('nobody-listens'));
        $this->assertSame($emitter, $emitter->on('a', static fn () => null));
    }
}
