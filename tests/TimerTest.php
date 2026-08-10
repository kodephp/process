<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Timer;
use PHPUnit\Framework\TestCase;

final class TimerTest extends TestCase
{
    protected function tearDown(): void
    {
        Timer::reset();
    }

    public function testAdd(): void
    {
        $executed = false;

        $timerId = Timer::add(0.1, function () use (&$executed) {
            $executed = true;
        }, [], false);

        $this->assertFalse($executed);
        $this->assertEquals(1, Timer::count());

        usleep(150000);
        Timer::tick();

        $this->assertTrue($executed);
        $this->assertEquals(0, Timer::count());
    }

    public function testOnce(): void
    {
        $count = 0;

        $timerId = Timer::once(0.05, function () use (&$count) {
            $count++;
        });

        Timer::tick();
        usleep(60000);
        Timer::tick();

        $this->assertEquals(1, $count);

        usleep(60000);
        Timer::tick();

        $this->assertEquals(1, $count);
    }

    public function testRepeat(): void
    {
        $count = 0;

        $timerId = Timer::repeat(0.05, function () use (&$count) {
            $count++;
        }, 3);

        for ($i = 0; $i < 10; $i++) {
            Timer::tick();
            usleep(60000);
        }

        $this->assertEquals(3, $count);
        $this->assertEquals(0, Timer::count());
    }

    public function testForever(): void
    {
        $count = 0;

        $timerId = Timer::forever(0.05, function () use (&$count) {
            $count++;
        });

        for ($i = 0; $i < 5; $i++) {
            Timer::tick();
            usleep(60000);
        }

        $this->assertGreaterThanOrEqual(4, $count);

        Timer::del($timerId);
        $this->assertEquals(0, Timer::count());
    }

    public function testImmediate(): void
    {
        $executed = false;

        $timerId = Timer::immediate(function () use (&$executed) {
            $executed = true;
        });

        $this->assertFalse($executed);

        Timer::tick();

        $this->assertTrue($executed);
    }

    public function testDel(): void
    {
        $executed = false;

        $timerId = Timer::add(0.1, function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue(Timer::del($timerId));
        $this->assertEquals(0, Timer::count());
    }

    public function testPauseResume(): void
    {
        $count = 0;

        $timerId = Timer::forever(0.05, function () use (&$count) {
            $count++;
        });

        Timer::tick();
        usleep(60000);
        Timer::tick();

        $countBeforePause = $count;

        Timer::pause($timerId);

        usleep(60000);
        Timer::tick();

        $countAfterPause = $count;

        $this->assertEquals($countBeforePause, $countAfterPause);

        Timer::resume($timerId);

        Timer::tick();
        usleep(60000);
        Timer::tick();

        $this->assertGreaterThan($countAfterPause, $count);

        Timer::del($timerId);
    }

    public function testCount(): void
    {
        $this->assertEquals(0, Timer::count());

        Timer::add(0.1, fn() => null);
        $this->assertEquals(1, Timer::count());

        Timer::add(0.1, fn() => null);
        $this->assertEquals(2, Timer::count());

        Timer::delAll();
        $this->assertEquals(0, Timer::count());
    }

    public function testGetStats(): void
    {
        Timer::add(0.1, fn() => null);

        $stats = Timer::getStats();

        $this->assertArrayHasKey('total_created', $stats);
        $this->assertArrayHasKey('total_executed', $stats);
        $this->assertArrayHasKey('active_timers', $stats);
    }

    public function testCallbackCanDeleteItselfWithoutResurrection(): void
    {
        $holder = new \stdClass();
        $holder->id = null;

        $timerId = Timer::forever(0.05, function () use ($holder) {
            Timer::del($holder->id);
        });
        $holder->id = $timerId;

        Timer::tick();
        usleep(60000);
        Timer::tick();
        usleep(60000);
        Timer::tick();

        // 回调内 del 自身后，不应把已删条目复活成残缺数组导致后续 tick 崩溃
        $this->assertEquals(0, Timer::count());
    }

    public function testCronCallbackCanDeleteItselfWithoutResurrection(): void
    {
        $holder = new \stdClass();
        $holder->id = null;

        $cronId = Timer::cron('* * * * *', function () use ($holder) {
            Timer::del($holder->id);
        });
        $holder->id = $cronId;

        // parseCronNext 默认返回未来时刻，单测不便等待整分钟；把 next_run 拨到过去
        // 使下一 tick 立即触发回调，真实复现「回调内 del 自身」的崩溃路径。
        $ref = new \ReflectionProperty(Timer::class, 'cronJobs');
        $ref->setAccessible(true);
        $jobs = $ref->getValue();
        $jobs[$cronId]['next_run'] = microtime(true) - 1.0;
        $ref->setValue(null, $jobs);

        Timer::tick();   // 触发回调（回调内 del 自身）
        Timer::tick();   // 再触发不应崩溃（残缺数组不再被写出）

        $this->assertEquals(0, Timer::countCronJobs());
    }

    public function testTimerAndCronIdsNeverCollide(): void
    {
        $timerId = Timer::forever(0.05, fn () => null);
        $cronId  = Timer::cron('* * * * *', fn () => null);

        $this->assertNotEquals(
            $timerId,
            $cronId,
            'timer 与 cron 必须来自同一 ID 序列，不得数值相撞（否则 del/pause 会删错对象）'
        );

        // del(timerId) 只删对应的 timer，不误伤 cron
        $this->assertTrue(Timer::del($timerId));
        $this->assertSame(0, Timer::countTimers());
        $this->assertSame(1, Timer::countCronJobs());

        // del(cronId) 只删 cron
        $this->assertTrue(Timer::del($cronId));
        $this->assertSame(0, Timer::countCronJobs());
    }

    public function testCallbackExceptionDeliveredToErrorListener(): void
    {
        $captured = null;

        Timer::onError(function (int $id, \Throwable $e) use (&$captured) {
            $captured = $e;
        });

        Timer::once(0.001, static function (): void {
            throw new \RuntimeException('boom');
        });

        usleep(2000);
        Timer::tick();

        $this->assertInstanceOf(\RuntimeException::class, $captured);
        $this->assertStringContainsString('boom', $captured->getMessage());
    }

    public function testCallbackExceptionSurfacesWithoutListener(): void
    {
        $lastWarning = null;
        $handler = function (int $errno, string $errstr) use (&$lastWarning) {
            if ($errno === E_USER_WARNING) {
                $lastWarning = $errstr;
                return true;
            }
            return false;
        };

        set_error_handler($handler);
        try {
            Timer::once(0.001, static function (): void {
                throw new \RuntimeException('boom');
            });
            usleep(2000);
            Timer::tick();
        } finally {
            restore_error_handler();
        }

        $this->assertIsString($lastWarning);
        $this->assertStringContainsString('boom', $lastWarning ?? '');
    }

    /**
     * 通过反射直接验证 cron 字段解析，覆盖逗号枚举 + 区间、区间 + 步长等组合。
     */
    public function testMatchCronPartCombinations(): void
    {
        $m = new \ReflectionMethod(Timer::class, 'matchCronPart');
        $m->setAccessible(true);
        $match = fn (string $part, int $value): bool => $m->invoke(null, $part, $value);

        // 1,2-5 = {1,2,3,4,5}
        $this->assertTrue($match('1,2-5', 3));
        $this->assertTrue($match('1,2-5', 4));
        $this->assertFalse($match('1,2-5', 6));

        // 1-30/5 = 1,6,11,16,21,26
        $this->assertTrue($match('1-30/5', 1));
        $this->assertTrue($match('1-30/5', 6));
        $this->assertFalse($match('1-30/5', 7));

        // */15
        $this->assertTrue($match('*/15', 30));
        $this->assertFalse($match('*/15', 7));

        // 单值
        $this->assertTrue($match('5', 5));
        $this->assertFalse($match('5', 6));

        // 通配
        $this->assertTrue($match('*', 42));
    }
}
