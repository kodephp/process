<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Fiber;
use Kode\Process\Async\Async;
use Kode\Process\Exceptions\ParallelException;
use Kode\Process\Parallel\FutureInterface;
use Kode\Process\Parallel\Parallel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 并行（多线程）门面测试
 *
 * 当前 CI / 本地为 NTS 构建，无法真正加载 ext-parallel，因此：
 * - 环境探测断言在 NTS 下得到 false / 'none'；
 * - run() 在不可用时应抛出清晰的 ParallelException；
 * - await() 通过 Fake Future 验证「阻塞」与「协程内挂起/恢复」两种路径，
 *   其中协程路径分别模拟 kode/fibers 的 FiberPool（忙轮询 resume）与本库 Async 事件循环（defer 轮询）。
 */
final class ParallelTest extends TestCase
{
    protected function tearDown(): void
    {
        Async::reset();
    }

    public function testDetectionInNts(): void
    {
        $this->assertFalse(Parallel::isZts());
        $this->assertFalse(Parallel::isAvailable());
        $this->assertSame('none', Parallel::backend());
    }

    public function testRunThrowsWhenUnavailable(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessageMatches('/requires ZTS \+ ext-parallel/');

        Parallel::run(static fn() => 42);
    }

    public function testAwaitBlockingReturnsValue(): void
    {
        $future = new FakeDoneFuture(7);

        $result = Parallel::await($future);

        $this->assertSame(7, $result);
        $this->assertTrue($future->isSuccessful());
    }

    public function testAwaitBlockingThrowsOnFailure(): void
    {
        $error = new RuntimeException('boom');
        $future = new FakeFailedFuture($error);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        Parallel::await($future);
    }

    public function testAwaitInFiberBusyPollReturnsValue(): void
    {
        // 模拟 kode/fibers 的 FiberPool：持续 resume 挂起的 Fiber
        $future = new FakeDeferredFuture(99, 3);

        $fiber = new Fiber(static fn() => Parallel::await($future));
        $fiber->start();

        $ticks = 0;
        while (!$fiber->isTerminated() && $ticks < 50) {
            $fiber->resume();
            $ticks++;
        }

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(99, $fiber->getReturn());
    }

    public function testAwaitInFiberThrowsOnFailure(): void
    {
        $error = new RuntimeException('thread blew up');
        $future = new FakeFailedFuture($error);

        $fiber = new Fiber(static fn() => Parallel::await($future));

        try {
            $fiber->start();
            while (!$fiber->isTerminated()) {
                $fiber->resume();
            }
            $this->fail('Expected exception to be thrown from fiber');
        } catch (RuntimeException $e) {
            $this->assertSame('thread blew up', $e->getMessage());
        }
    }

    public function testAwaitInFiberAsyncLoopResumesViaDefer(): void
    {
        // 模拟本库 Async 事件循环正在驱动：将 running 置 true，并由 Async::tick() 驱动
        $ref = new \ReflectionProperty(Async::class, 'running');
        $ref->setAccessible(true);
        $ref->setValue(null, true);

        $future = new FakeDeferredFuture('async-ok', 3);

        $fiber = new Fiber(static fn() => Parallel::await($future));
        $fiber->start();

        $ticks = 0;
        while (!$fiber->isTerminated() && $ticks < 50) {
            Async::tick();
            $ticks++;
        }

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame('async-ok', $fiber->getReturn());
    }
}

/**
 * 已完成的 Fake Future（可立即取值）
 */
final class FakeDoneFuture implements FutureInterface
{
    public function __construct(private mixed $result)
    {
    }

    public function done(): bool
    {
        return true;
    }

    public function value(): mixed
    {
        return $this->result;
    }

    public function isSuccessful(): bool
    {
        return true;
    }

    public function isFailed(): bool
    {
        return false;
    }

    public function getException(): ?\Throwable
    {
        return null;
    }
}

/**
 * 延迟完成的 Fake Future：前 $ticks 次 done() 返回 false，用于触发协程挂起/恢复
 */
final class FakeDeferredFuture implements FutureInterface
{
    private int $checked = 0;

    public function __construct(private mixed $result, private int $ticks = 3)
    {
    }

    public function done(): bool
    {
        $this->checked++;

        return $this->checked > $this->ticks;
    }

    public function value(): mixed
    {
        return $this->result;
    }

    public function isSuccessful(): bool
    {
        return $this->checked > $this->ticks;
    }

    public function isFailed(): bool
    {
        return false;
    }

    public function getException(): ?\Throwable
    {
        return null;
    }
}

/**
 * 失败的 Fake Future（value() 抛原异常）
 */
final class FakeFailedFuture implements FutureInterface
{
    public function __construct(private \Throwable $error)
    {
    }

    public function done(): bool
    {
        return true;
    }

    public function value(): mixed
    {
        throw $this->error;
    }

    public function isSuccessful(): bool
    {
        return false;
    }

    public function isFailed(): bool
    {
        return true;
    }

    public function getException(): ?\Throwable
    {
        return $this->error;
    }
}
