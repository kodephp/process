<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Daemon\Daemon;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Process;
use Kode\Process\Signal;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertFileExists;

/**
 * Daemon 常驻进程运行器的单元测试。
 *
 * 涉及 fork 的用例直接验证「用户任务确实由 Timer 在子进程里执行」「停止能杀掉子进程」，
 * 不依赖 Master/Worker 池（那套 processTasks 不会调用回调，见 src/Worker/WorkerProcess.php:201）。
 */
final class DaemonTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir();
    }

    // ---------------------------------------------------------- 构造器 / 校验

    public function testBuilderReturnsSelfAndStoresConfig(): void
    {
        $task = fn () => null;

        $daemon = Daemon::define()
            ->task($task)
            ->every(5)
            ->workers(4)
            ->pidFile('/tmp/x.pid')
            ->maxRestarts(7);

        $this->assertSame(4, $daemon->getWorkers());
        $this->assertSame(5.0, $daemon->getInterval());
        $this->assertNull($daemon->getCron());
        $this->assertSame('/tmp/x.pid', $daemon->getPidFile());
    }

    public function testCronOverridesInterval(): void
    {
        $daemon = Daemon::define()->task(fn () => null)->cron('0 0 * * *');

        $this->assertSame('0 0 * * *', $daemon->getCron());
    }

    public function testEveryRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Daemon::define()->task(fn () => null)->every(0);
    }

    public function testWorkersRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Daemon::define()->task(fn () => null)->workers(0);
    }

    public function testRunThrowsWithoutTask(): void
    {
        $this->expectException(ProcessException::class);

        Daemon::define()->run();
    }

    // --------------------------------------------------- 子进程 / 信号 / 重生

    public function testWorkerExecutesTaskViaTimerInForkedChild(): void
    {
        $flag = tempnam($this->tmpDir, 'kode-daemon-flag');
        @unlink($flag);

        $daemon = Daemon::define()
            ->task(function () use ($flag): void {
                file_put_contents($flag, (string) posix_getpid());
            })
            ->every(0.05)
            ->workers(1);

        $pid = Process::fork(function () use ($daemon): void {
            $daemon->runWorker(0);
        });

        $ran = false;
        for ($i = 0; $i < 100; $i++) {
            if (file_exists($flag) && filesize($flag) > 0) {
                $ran = true;
                break;
            }
            usleep(10_000);
        }

        // 停止并回收子进程
        @posix_kill($pid, Signal::TERM);
        Process::wait($pid);
        @unlink($flag);

        $this->assertTrue($ran, 'worker 应通过 Timer 在子进程内执行用户任务');
    }

    public function testStopAllWorkersKillsSpawnedChild(): void
    {
        $daemon = Daemon::define()
            ->task(fn () => usleep(10_000))
            ->every(1)
            ->workers(1);

        $spawn = new \ReflectionMethod($daemon, 'spawnWorker');
        $spawn->setAccessible(true);
        $spawn->invoke($daemon, 0);

        $childPids = (new \ReflectionProperty($daemon, 'childPids'))->getValue($daemon);
        $this->assertCount(1, $childPids);
        $pid = $childPids[0];
        $this->assertTrue(Process::isProcessAlive($pid), 'worker 子进程应先存活');

        $stop = new \ReflectionMethod($daemon, 'stopAllWorkers');
        $stop->setAccessible(true);
        $stop->invoke($daemon);

        $this->assertFalse(Process::isProcessAlive($pid), 'stopAllWorkers 应杀掉 worker 子进程');
    }

    public function testWriteAndCleanupPidFile(): void
    {
        $pidFile = tempnam($this->tmpDir, 'kode-daemon-pid');
        @unlink($pidFile);

        $daemon = Daemon::define()->task(fn () => null)->pidFile($pidFile);

        $write = new \ReflectionMethod($daemon, 'writePidFile');
        $write->setAccessible(true);
        $write->invoke($daemon);

        $this->assertFileExists($pidFile);
        $this->assertSame((string) posix_getpid(), file_get_contents($pidFile));

        $cleanup = new \ReflectionMethod($daemon, 'cleanup');
        $cleanup->setAccessible(true);
        $cleanup->invoke($daemon);

        $this->assertFileDoesNotExist($pidFile);
    }

    public function testRestartBudgetGuard(): void
    {
        $daemon = Daemon::define()->task(fn () => null)->maxRestarts(2);

        $prop = new \ReflectionProperty($daemon, 'restartCount');
        $prop->setAccessible(true);

        $method = new \ReflectionMethod($daemon, 'exceedsRestartBudget');
        $method->setAccessible(true);

        $prop->setValue($daemon, [0 => 2]);
        $this->assertFalse($method->invoke($daemon, 0), '恰好等于上限不算超限');

        $prop->setValue($daemon, [0 => 3]);
        $this->assertTrue($method->invoke($daemon, 0), '超过上限应判定超限');
    }

    public function testHealthyWindowResetsStaleRestartCount(): void
    {
        $daemon = Daemon::define()->task(fn () => null)->healthyWindow(60);

        $rc = new \ReflectionClass($daemon);
        $spawnAt = $rc->getProperty('childSpawnAt');
        $spawnAt->setAccessible(true);
        $restart = $rc->getProperty('restartCount');
        $restart->setAccessible(true);
        $bump = $rc->getMethod('bumpRestartCount');
        $bump->setAccessible(true);

        // 槽位已健康存活 100 秒（远超 60 秒窗口）→ 旧计数清零后 +1
        $restart->setValue($daemon, [0 => 5]);
        $spawnAt->setValue($daemon, [0 => microtime(true) - 100]);
        $bump->invoke($daemon, 0);
        $this->assertSame(1, $restart->getValue($daemon)[0], '健康存活超窗口后应从 0 重新累计');

        // 槽位刚派生 1 秒（未达窗口）→ 不清零，直接 +1
        $restart->setValue($daemon, [0 => 2]);
        $spawnAt->setValue($daemon, [0 => microtime(true) - 1]);
        $bump->invoke($daemon, 0);
        $this->assertSame(3, $restart->getValue($daemon)[0], '未达健康窗口不应清零');
    }
}
