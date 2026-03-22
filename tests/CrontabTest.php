<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Process\Crontab\Crontab;

final class CrontabTest extends TestCase
{
    protected function setUp(): void
    {
        Crontab::destroyAll();
    }

    public function testCreateCrontab(): void
    {
        $crontab = new Crontab('* * * * * *', function () {
            return true;
        });

        $this->assertGreaterThan(0, $crontab->getId());
        $this->assertEquals('* * * * * *', $crontab->getExpression());
    }

    public function testCrontabEnableDisable(): void
    {
        $crontab = new Crontab('* * * * * *', function () {});

        $this->assertTrue($crontab->isEnabled());

        $crontab->disable();
        $this->assertFalse($crontab->isEnabled());

        $crontab->enable();
        $this->assertTrue($crontab->isEnabled());
    }

    public function testCrontabDestroy(): void
    {
        $crontab = new Crontab('* * * * * *', function () {});
        $id = $crontab->getId();

        $this->assertEquals(1, Crontab::count());

        $crontab->destroy();
        $this->assertEquals(0, Crontab::count());
    }

    public function testCrontabStaticCreate(): void
    {
        $crontab = Crontab::create('* * * * * *', function () {});

        $this->assertInstanceOf(Crontab::class, $crontab);
    }

    public function testCrontabTick(): void
    {
        $executed = false;
        $crontab = new Crontab('* * * * * *', function () use (&$executed) {
            $executed = true;
        });

        $nextRun = $crontab->getNextRunTime();
        $this->assertGreaterThan(0, $nextRun);
    }

    public function testCrontabCount(): void
    {
        $this->assertEquals(0, Crontab::count());

        new Crontab('* * * * * *', function () {});
        $this->assertEquals(1, Crontab::count());

        new Crontab('*/5 * * * * *', function () {});
        $this->assertEquals(2, Crontab::count());
    }

    public function testCrontabGetAll(): void
    {
        $crontab1 = new Crontab('* * * * * *', function () {});
        $crontab2 = new Crontab('*/5 * * * * *', function () {});

        $all = Crontab::getAll();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey($crontab1->getId(), $all);
        $this->assertArrayHasKey($crontab2->getId(), $all);
    }

    public function testCrontabGetNextRunTimes(): void
    {
        new Crontab('* * * * * *', function () {});
        new Crontab('0 * * * * *', function () {});

        $times = Crontab::getNextRunTimes();

        $this->assertCount(2, $times);
        $this->assertArrayHasKey('expression', reset($times));
        $this->assertArrayHasKey('next_run', reset($times));
    }

    public function testParseMinuteExpression(): void
    {
        $crontab = new Crontab('0 30 * * * *', function () {});
        $nextRun = $crontab->getNextRunTime();
        
        $this->assertGreaterThanOrEqual(time(), $nextRun);
    }

    public function testParseHourlyExpression(): void
    {
        $crontab = new Crontab('0 0 * * *', function () {});
        $nextRun = $crontab->getNextRunTime();
        
        $this->assertGreaterThan(time(), $nextRun);
    }

    public function testDestroyAll(): void
    {
        new Crontab('* * * * * *', function () {});
        new Crontab('* * * * * *', function () {});
        
        $this->assertEquals(2, Crontab::count());
        
        Crontab::destroyAll();
        $this->assertEquals(0, Crontab::count());
    }
}
