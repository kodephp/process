<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Async\Async;
use Kode\Process\Async\Promise;
use PHPUnit\Framework\TestCase;

final class PromiseTest extends TestCase
{
    protected function tearDown(): void
    {
        Async::reset();
    }

    public function testResolve(): void
    {
        $promise = Promise::resolve('value');

        $this->assertTrue($promise->isFulfilled());
        $this->assertFalse($promise->isPending());
        $this->assertSame('value', $promise->getValue());
        $this->assertSame('fulfilled', $promise->getState());
    }

    public function testReject(): void
    {
        $promise = Promise::reject('reason');

        $this->assertTrue($promise->isRejected());
        $this->assertSame('reason', $promise->getReason());
        $this->assertSame('rejected', $promise->getState());
    }

    public function testPendingState(): void
    {
        $promise = new Promise(function (): void {
            // 不 resolve，保持 pending
        });

        $this->assertTrue($promise->isPending());
        $this->assertFalse($promise->isFulfilled());
        $this->assertFalse($promise->isRejected());
    }

    public function testExecutorResolve(): void
    {
        $promise = new Promise(function (callable $resolve): void {
            $resolve(42);
        });

        $this->assertTrue($promise->isFulfilled());
        $this->assertSame(42, $promise->getValue());
    }

    public function testExecutorThrowRejects(): void
    {
        $promise = new Promise(function (): void {
            throw new \RuntimeException('boom');
        });

        $this->assertTrue($promise->isRejected());
        $this->assertInstanceOf(\RuntimeException::class, $promise->getReason());
    }

    public function testThen(): void
    {
        $promise = Promise::resolve(1);
        $result = null;

        $promise->then(function ($value) use (&$result): void {
            $result = $value * 2;
        });

        Async::runMicrotasks();

        $this->assertSame(2, $result);
    }

    public function testThenChaining(): void
    {
        $result = null;

        Promise::resolve(2)
            ->then(fn ($v) => $v + 3)
            ->then(fn ($v) => $v * 10)
            ->then(function ($v) use (&$result): void {
                $result = $v;
            });

        Async::runMicrotasks();

        $this->assertSame(50, $result);
    }

    public function testCatch(): void
    {
        $promise = Promise::reject('error');
        $caught = null;

        $promise->catch(function ($reason) use (&$caught): void {
            $caught = $reason;
        });

        Async::runMicrotasks();

        $this->assertSame('error', $caught);
    }

    public function testThenSkippedOnRejection(): void
    {
        $thenCalled = false;
        $caught = false;

        Promise::reject('nope')
            ->then(function () use (&$thenCalled): void {
                $thenCalled = true;
            })
            ->catch(function () use (&$caught): void {
                $caught = true;
            });

        Async::runMicrotasks();

        $this->assertFalse($thenCalled);
        $this->assertTrue($caught);
    }

    public function testFinally(): void
    {
        $promise = Promise::resolve('value');
        $finallyCalled = false;

        $promise->finally(function () use (&$finallyCalled): void {
            $finallyCalled = true;
        });

        Async::runMicrotasks();

        $this->assertTrue($finallyCalled);
    }

    public function testFinallyRunsOnRejection(): void
    {
        $finallyCalled = false;

        Promise::reject('err')->finally(function () use (&$finallyCalled): void {
            $finallyCalled = true;
        });

        Async::runMicrotasks();

        $this->assertTrue($finallyCalled);
    }

    public function testAll(): void
    {
        $promises = [
            Promise::resolve(1),
            Promise::resolve(2),
            Promise::resolve(3),
        ];

        $result = null;

        Promise::all($promises)->then(function ($values) use (&$result): void {
            $result = $values;
        });

        Async::runMicrotasks();

        $this->assertSame([1, 2, 3], $result);
    }

    public function testAllRejectsOnFirstFailure(): void
    {
        $reason = null;

        Promise::all([
            Promise::resolve(1),
            Promise::reject('failed'),
            Promise::resolve(3),
        ])->catch(function ($r) use (&$reason): void {
            $reason = $r;
        });

        Async::runMicrotasks();

        $this->assertSame('failed', $reason);
    }

    public function testAllWithEmptyArray(): void
    {
        $result = null;

        Promise::all([])->then(function ($values) use (&$result): void {
            $result = $values;
        });

        Async::runMicrotasks();

        $this->assertSame([], $result);
    }

    public function testRace(): void
    {
        $promises = [
            Promise::resolve('first'),
            Promise::resolve('second'),
        ];

        $result = null;

        Promise::race($promises)->then(function ($value) use (&$result): void {
            $result = $value;
        });

        Async::runMicrotasks();

        $this->assertSame('first', $result);
    }

    public function testAnyIgnoresRejections(): void
    {
        $result = null;

        Promise::any([
            Promise::reject('bad'),
            Promise::resolve('good'),
        ])->then(function ($value) use (&$result): void {
            $result = $value;
        });

        Async::runMicrotasks();

        $this->assertSame('good', $result);
    }

    public function testAllSettled(): void
    {
        $promises = [
            Promise::resolve('success'),
            Promise::reject('error'),
        ];

        $result = null;

        Promise::allSettled($promises)->then(function ($values) use (&$result): void {
            $result = $values;
        });

        Async::runMicrotasks();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('fulfilled', $result[0]['status']);
        $this->assertSame('success', $result[0]['value']);
        $this->assertSame('rejected', $result[1]['status']);
        $this->assertSame('error', $result[1]['reason']);
    }

    public function testAwaitReturnsValue(): void
    {
        $this->assertSame('done', Promise::resolve('done')->await());
    }
}
