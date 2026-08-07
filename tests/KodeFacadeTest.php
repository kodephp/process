<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Async\EventEmitter;
use Kode\Process\Debug\StatusMonitor;
use Kode\Process\Kode;
use Kode\Process\Queue\QueueManager;
use Kode\Process\Signal\SignalHandler;
use Kode\Process\Timer;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖 Kode 门面新增的编排原语委托方法（定时器 / 信号 / 队列 / 监控 / 事件 / diagnose）。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class KodeFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Timer::reset();
    }

    public function testAfterRegistersOneShotTimer(): void
    {
        $id = Kode::after(1.0, static fn() => null);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, Kode::timerCount());
    }

    public function testEveryRegistersPersistentTimer(): void
    {
        $id = Kode::every(1.0, static fn() => null);

        $this->assertSame(1, Kode::timerCount());
        $this->assertTrue(Kode::clearTimer($id));
        $this->assertSame(0, Kode::timerCount());
    }

    public function testCronRegistersCronJob(): void
    {
        $id = Kode::cron('* * * * *', static fn() => null);

        $this->assertSame(1, Kode::timerCount());
        $this->assertTrue(Kode::clearTimer($id));
        $this->assertSame(0, Kode::timerCount());
    }

    public function testTickTimersDoesNotThrow(): void
    {
        Kode::after(0.0, static fn() => null);

        Kode::tickTimers();

        $this->assertSame(0, Kode::timerCount());
    }

    public function testSignalReturnsSingleton(): void
    {
        $a = Kode::signal();
        $b = Kode::signal();

        $this->assertInstanceOf(SignalHandler::class, $a);
        $this->assertSame($a, $b);
    }

    public function testQueueReturnsInstance(): void
    {
        $this->assertInstanceOf(QueueManager::class, Kode::queue());
    }

    public function testMonitorReturnsInstance(): void
    {
        $monitor = Kode::monitor();

        $this->assertInstanceOf(StatusMonitor::class, $monitor);

        $custom = Kode::monitor('/tmp/kode_custom_status.json', '/tmp/kode_custom.pid');

        $this->assertInstanceOf(StatusMonitor::class, $custom);
    }

    public function testEmitterReturnsEventEmitter(): void
    {
        $this->assertInstanceOf(EventEmitter::class, Kode::emitter());
    }

    public function testDiagnoseIncludesParallelAndTable(): void
    {
        $report = Kode::diagnose();

        $this->assertArrayHasKey('parallel', $report);
        $this->assertArrayHasKey('zts', $report['parallel']);
        $this->assertArrayHasKey('available', $report['parallel']);
        $this->assertArrayHasKey('backend', $report['parallel']);
        $this->assertContains($report['parallel']['backend'], ['ext-parallel', 'kode-parallel', 'none']);

        $this->assertArrayHasKey('table', $report);
        $this->assertArrayHasKey('runtimes', $report);
        $this->assertArrayHasKey('loop', $report);
        $this->assertArrayHasKey('recommendation', $report);
    }
}
