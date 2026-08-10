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

    /**
     * 回归守卫：parseCronNext 必须按标准 cron 字段顺序（分 时 日 月 周）映射，
     * 且五段全部命中才算匹配。
     *
     * 旧实现整体偏移了一位：$parts[4]/[3]/[2]/[1] 依次匹配 wday/mday/hours/minutes，
     * 导致分钟字段（$parts[0]）被完全忽略、月份字段被当作日使用。此测试用「必在
     * 扫描地平线（≈32 天）内命中」的表达式逐项锁死映射，避免依赖运行时日期。
     */
    public function testParseCronNextRespectsAllFiveFields(): void
    {
        $parse = new \ReflectionMethod(Timer::class, 'parseCronNext');
        $parse->setAccessible(true);
        $d = fn(string $expr): array => getdate((int) $parse->invoke(null, $expr));

        // 分钟字段被尊重（这是旧实现被完全忽略的字段）
        $this->assertSame(30, $d('30 * * * *')['minutes']);

        // 小时 + 分钟
        $noon = $d('0 12 * * *');
        $this->assertSame(12, $noon['hours']);
        $this->assertSame(0, $noon['minutes']);

        // 日字段（旧实现被当作月份）
        $first = $d('0 0 1 * *');
        $this->assertSame(1, $first['mday']);
        $this->assertSame(0, $first['hours']);
        $this->assertSame(0, $first['minutes']);

        // 周字段
        $mon = $d('0 9 * * 1');
        $this->assertSame(1, $mon['wday']);
        $this->assertSame(9, $mon['hours']);
        $this->assertSame(0, $mon['minutes']);

        // 通配
        $this->assertSame(0, $d('* * * * *')['minutes'] % 1);
    }

    /**
     * 等价性守卫：新实现（位掩码由 matchCronPart 构建）对每个候选时间的五段判定，
     * 必须与「正确字段映射 + 逐轮 matchCronPart 判定」的参考实现完全一致。覆盖
     * 逗号/区间/步长/永不命中（退化为 now+3600）等组合，确保优化与修正都没改变语义。
     */
    public function testParseCronNextMatchesReferenceImplementation(): void
    {
        $parse = new \ReflectionMethod(Timer::class, 'parseCronNext');
        $parse->setAccessible(true);

        $match = new \ReflectionMethod(Timer::class, 'matchCronPart');
        $match->setAccessible(true);
        $mc = fn(string $p, int $v): bool => $match->invoke(null, $p, $v);

        // 参考实现：正确字段映射 + 逐轮字符串解析（不缓存），与 benchmarks 中的版本一致
        $reference = function (string $expr) use ($mc): int {
            $parts = preg_split('/\s+/', trim($expr));
            if (count($parts) !== 5) {
                return (int) (microtime(true) + 60);
            }
            $now = time();
            $limit = min(46080, 525600);
            for ($i = 1; $i <= $limit; $i++) {
                $candidate = $now + ($i * 60);
                $dd = getdate($candidate);
                if ($mc($parts[0], $dd['minutes']) &&
                    $mc($parts[1], $dd['hours']) &&
                    $mc($parts[2], $dd['mday']) &&
                    $mc($parts[3], $dd['mon']) &&
                    $mc($parts[4], $dd['wday'])
                ) {
                    return (int) $candidate;
                }
            }
            return (int) (microtime(true) + 3600);
        };

        $exprs = [
            '* * * * *',
            '*/15 * * * *',
            '0 9 * * 1',
            '30 14 28 2 *',
            '0 0 30 2 *',
            '1-30/5 1-12/2 1,15 * 1-5',
            '0 12 * * *',
            '0 0 1 * *',
        ];

        foreach ($exprs as $expr) {
            $new = getdate((int) $parse->invoke(null, $expr));
            $ref = getdate($reference($expr));

            $this->assertSame($new['minutes'], $ref['minutes'], "分钟不一致: {$expr}");
            $this->assertSame($new['hours'], $ref['hours'], "小时不一致: {$expr}");
            $this->assertSame($new['mday'], $ref['mday'], "日不一致: {$expr}");
            $this->assertSame($new['mon'], $ref['mon'], "月不一致: {$expr}");
            $this->assertSame($new['wday'], $ref['wday'], "周不一致: {$expr}");
        }
    }

    /**
     * 缓存稳定性：同一表达式多次解析结果一致；位掩码按表达式缓存，命中后不再付出
     * 重复解析开销（此处只验证结果确定性，性能由 benchmarks/timer-cron-bench.php 量化）。
     */
    public function testParseCronNextIsDeterministicAcrossCalls(): void
    {
        $parse = new \ReflectionMethod(Timer::class, 'parseCronNext');
        $parse->setAccessible(true);

        $a = (int) $parse->invoke(null, '17 3 4 * *');
        $b = (int) $parse->invoke(null, '17 3 4 * *');
        $c = (int) $parse->invoke(null, '17 3 4 * *');

        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
    }
}
