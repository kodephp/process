<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Compat\Timer;
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
}
