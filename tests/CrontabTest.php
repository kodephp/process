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

    public function testTickDoesNotRepeatWithinSameSecond(): void
    {
        $count = 0;
        $crontab = new Crontab('* * * * * *', function () use (&$count) {
            $count++;
        });

        // 对齐到秒边界，确保这一秒内确实到点
        $second = time();
        while (time() === $second) {
            usleep(20_000);
        }

        for ($i = 0; $i < 10; $i++) {
            $crontab->tick();
        }

        // 事件循环 100ms 一跳，若重算基准仍是 now 则同秒会执行 10 次
        $this->assertSame(1, $count, '同一秒内只应执行一次');

        $second = time();
        while (time() === $second) {
            usleep(20_000);
        }

        for ($i = 0; $i < 10; $i++) {
            $crontab->tick();
        }

        $this->assertSame(2, $count, '下一秒应再执行一次');
    }

    public function testNextRunTimeIsStrictlyInTheFuture(): void
    {
        $crontab = new Crontab('* * * * * *', function () {});

        $this->assertGreaterThan(time(), $crontab->getNextRunTime());
    }

    public function testSecondFieldIsHonoured(): void
    {
        $crontab = new Crontab('0 * * * * *', function () {});

        // 步进 60 秒扫描会让 seconds 恒等于 now%60，该表达式将永不命中
        $this->assertSame(0, (int)date('s', $crontab->getNextRunTime()));
    }

    public function testStepInSecondField(): void
    {
        $crontab = new Crontab('*/10 * * * * *', function () {});

        $this->assertSame(0, (int)date('s', $crontab->getNextRunTime()) % 10);
    }

    public function testFiveFieldExpressionRunsAtSecondZero(): void
    {
        $crontab = new Crontab('0 0 * * *', function () {});
        $next = $crontab->getNextRunTime();

        // 5 段表达式补秒必须是 0，补 `*` 会落在构造时刻的随机秒上
        $this->assertSame('00:00:00', date('H:i:s', $next));
    }

    public function testWeekdaySevenMeansSunday(): void
    {
        $crontab = new Crontab('0 0 * * 7', function () {});
        $next = $crontab->getNextRunTime();

        $this->assertSame('0', date('w', $next), 'POSIX 允许 7 表示周日');
        $this->assertSame('00:00:00', date('H:i:s', $next));
    }

    public function testWeekdayNameIsAccepted(): void
    {
        $crontab = new Crontab('0 0 * * SUN', function () {});

        $this->assertSame('0', date('w', $crontab->getNextRunTime()));
    }

    public function testMonthNameIsAccepted(): void
    {
        $crontab = new Crontab('0 0 1 JAN *', function () {});

        $this->assertSame('01-01', date('m-d', $crontab->getNextRunTime()));
    }

    public function testDayOfMonthAndWeekdayUseOrSemantics(): void
    {
        // POSIX：两者都被限定时取并集，命中「13 号」或「周五」任一即可
        $crontab = new Crontab('0 0 13 * 5', function () {});
        $next = $crontab->getNextRunTime();

        $isThirteenth = date('j', $next) === '13';
        $isFriday = date('w', $next) === '5';

        $this->assertTrue($isThirteenth || $isFriday);
    }

    public function testZeroStepIsRejectedInsteadOfHanging(): void
    {
        // 原实现 for ($i=$a; $i<=$b; $i+=0) 会无限循环并耗尽内存
        $this->expectException(\InvalidArgumentException::class);

        new Crontab('*/0 * * * * *', function () {});
    }

    public function testOutOfRangeFieldIsRejected(): void
    {
        // 原实现静默过滤成空集合，任务退化为「每天在随机时刻跑一次」
        $this->expectException(\InvalidArgumentException::class);

        new Crontab('0 0 32 * *', function () {});
    }

    public function testMalformedExpressionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Crontab('* * * *', function () {});
    }

    public function testUnsatisfiableExpressionDoesNotDegradeToDaily(): void
    {
        // 2 月 30 日永不存在：不应兜底成 now+86400 变成幽灵日常任务
        $crontab = new Crontab('0 0 30 2 *', function () {});

        $this->assertGreaterThan(time() + 300 * 86400, $crontab->getNextRunTime());
    }
}
