<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Daemon\Daemon;
use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Process;
use Kode\Process\Signal;
use PHPUnit\Framework\TestCase;

/**
 * Daemon 的 pid 文件归属判定。
 *
 * 修的是「重复启动叠出孤儿守护进程」：`run()` 此前无条件 `file_put_contents()`，
 * 第二次 `kode process:start` 会把第一代的 pid 覆盖成自己的，于是第一代
 * 从状态表里永久消失（读侧只认这个文件），而它和它的 worker 还在跑、
 * 还在消费同一份资源。停止/重载信号因此只能发给第二代。
 *
 * 归属判定只有一份 = `claimPidFile()`，`run()` 在守护化之前与 `writePidFile()`
 * 落盘之前各调一次；`cleanup()` 只删写着**自己 pid** 的文件。
 */
final class DaemonPidFileTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    private function pidFile(string $tag): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kode-pid-' . $tag);
        @unlink($path);

        $this->files[] = $path;

        return $path;
    }

    private function invoke(Daemon $daemon, string $method): mixed
    {
        $m = new \ReflectionMethod($daemon, $method);
        $m->setAccessible(true);

        return $m->invoke($daemon);
    }

    /**
     * 一个**活着且不属于本进程**的 pid：PHPUnit 的父进程必然在跑，
     * 且不等待我们的子进程（不会被回收成僵尸，也不会被 wait 掉）。
     */
    private function liveForeignPid(): int
    {
        $pid = posix_getppid();

        if ($pid <= 0 || $pid === posix_getpid()) {
            $this->markTestSkipped('无法取得一个存活的他人 pid（父进程 pid=' . $pid . '）');
        }

        return $pid;
    }

    /** 一个**已经死掉**的 pid：fork 完立刻回收。 */
    private function deadPid(): int
    {
        $pid = Process::fork(static fn () => null);
        Process::wait($pid);

        $this->assertFalse(Process::isProcessAlive($pid), '前置条件：该 pid 必须已死亡');

        return $pid;
    }

    public function testRefusesToClaimFileHoldingLiveForeignPid(): void
    {
        $file = $this->pidFile('live');
        $pid = $this->liveForeignPid();
        file_put_contents($file, (string) $pid);

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);

        try {
            $this->invoke($daemon, 'writePidFile');
            $this->fail('pid 文件指向存活进程时必须拒绝占位');
        } catch (ProcessException $e) {
            $this->assertStringContainsString((string) $pid, $e->getMessage(), '原因里必须点名是哪个 pid 占了位');
            $this->assertStringContainsString($file, $e->getMessage(), '原因里必须点名是哪个文件');
        }

        // 拒绝 = 一个字节都不许动。覆盖掉就等于把第一代抹成孤儿。
        $this->assertSame((string) $pid, file_get_contents($file), '拒绝启动后 pid 文件必须原样保留');
    }

    public function testReclaimsFileHoldingOwnPid(): void
    {
        $file = $this->pidFile('own');
        file_put_contents($file, (string) posix_getpid());

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);

        $this->invoke($daemon, 'writePidFile');

        $this->assertSame((string) posix_getpid(), file_get_contents($file));
    }

    public function testClaimsFileHoldingDeadPid(): void
    {
        $file = $this->pidFile('dead');
        $stale = $this->deadPid();
        file_put_contents($file, (string) $stale);

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);

        $this->invoke($daemon, 'writePidFile');

        $this->assertSame((string) posix_getpid(), file_get_contents($file), '失效 pid 文件应被接管');
    }

    public function testClaimsFileWithUnreadableContent(): void
    {
        $file = $this->pidFile('garbage');
        file_put_contents($file, "  not-a-pid  \n");

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);

        $this->invoke($daemon, 'writePidFile');

        $this->assertSame((string) posix_getpid(), file_get_contents($file), '内容读不出 pid 时按未占用处理');
    }

    public function testClaimsMissingFile(): void
    {
        $file = $this->pidFile('missing');

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);

        $this->invoke($daemon, 'writePidFile');

        $this->assertFileExists($file);
        $this->assertSame((string) posix_getpid(), file_get_contents($file));
    }

    public function testCleanupKeepsFileTakenOverByAnotherDaemon(): void
    {
        $file = $this->pidFile('takeover');

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);
        $this->invoke($daemon, 'writePidFile');

        // 本进程退出前，另一个实例把文件改成了它的 pid（现实里由上一条覆盖缺陷造成）。
        $other = $this->liveForeignPid();
        file_put_contents($file, (string) $other);

        $this->invoke($daemon, 'cleanup');

        $this->assertFileExists($file, '退出清理不得删掉别人占的位');
        $this->assertSame((string) $other, file_get_contents($file));
    }

    public function testCleanupRemovesFileStillHoldingOwnPid(): void
    {
        $file = $this->pidFile('self-cleanup');

        $daemon = Daemon::define()->task(static fn () => null)->pidFile($file);
        $this->invoke($daemon, 'writePidFile');
        $this->invoke($daemon, 'cleanup');

        $this->assertFileDoesNotExist($file);
    }

    /**
     * 落盘失败不能静默。pid 文件是唯一的状态来源：写不下去 = 这一代守护进程
     * 在状态表里永久查不到，互斥也随之失效（下一个实例能直接起）。
     */
    public function testWriteFailureIsReported(): void
    {
        $dir = sys_get_temp_dir() . '/kode-pid-dir-' . uniqid();
        mkdir($dir, 0775);

        // pid 路径本身是个目录：is_file() 判不出、claim 会放行，
        // 而 file_put_contents() 必然失败（Is a directory）。
        $daemon = Daemon::define()->task(static fn () => null)->pidFile($dir);

        try {
            $this->invoke($daemon, 'writePidFile');
            $this->fail('pid 文件写不下去时必须抛，而不是让守护进程裸跑');
        } catch (ProcessException $e) {
            $this->assertStringContainsString(basename($dir), $e->getMessage());
        } finally {
            @rmdir($dir);
        }
    }

    /**
     * `run()` 必须在**派生 worker 之前**拒绝。
     *
     * 整个场景跑在子进程里：判定没落地时 supervise() 会永不返回，
     * 直接在测试进程里调 run() 会把 PHPUnit 挂死。父进程只等有限时间，
     * 等不到结论就杀掉子进程并判红（此时 pid 文件已被覆盖 = 缺陷现场）。
     */
    public function testRunRefusesBeforeSpawningWorkers(): void
    {
        $file = $this->pidFile('run');
        $other = $this->liveForeignPid();
        file_put_contents($file, (string) $other);

        $outcome = tempnam(sys_get_temp_dir(), 'kode-pid-outcome');
        @unlink($outcome);

        $daemon = Daemon::define()
            ->task(static fn () => null)
            ->every(0.05)
            ->workers(1)
            ->pidFile($file);

        $pid = Process::fork(static function () use ($daemon, $outcome): void {
            try {
                $daemon->run();
                file_put_contents($outcome, 'returned');
            } catch (ProcessException $e) {
                file_put_contents($outcome, 'refused:' . $e->getMessage());
            }
        });

        $verdict = null;
        for ($i = 0; $i < 200; $i++) {
            if (is_file($outcome)) {
                $verdict = (string) file_get_contents($outcome);
                break;
            }
            usleep(10_000);
        }

        posix_kill($pid, Signal::TERM);
        Process::wait($pid);
        @unlink($outcome);

        $this->assertNotNull($verdict, 'run() 没有立刻拒绝（子进程卡在监督循环里 = 已经派生过 worker）');
        $this->assertStringStartsWith('refused:', $verdict, 'run() 应抛 ProcessException 拒绝启动');
        $this->assertStringContainsString((string) $other, (string) $verdict);
        $this->assertSame((string) $other, file_get_contents($file), '拒绝启动后 pid 文件必须原样保留');
    }

    /**
     * 占位判定必须排在守护化**之前**。
     *
     * 这条只能用源码顺序断言：`daemonize(true)` 真跑起来会让测试进程脱离
     * PHPUnit（fork 之后才有「文件被别人占」的判定 = 白脱离一次），
     * 所以「为了失败而 fork」这个缺陷无法安全地用行为复现。
     */
    public function testPidClaimHappensBeforeDaemonize(): void
    {
        $method = new \ReflectionMethod(Daemon::class, 'run');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $claim = strpos($body, 'claimPidFile()');
        $daemonize = strpos($body, 'Process::daemonize(');

        $this->assertIsInt($claim, 'run() 里没有调用占位判定，守护化失败路径完全没保护');
        $this->assertIsInt($daemonize);
        $this->assertLessThan($daemonize, $claim, '占位判定必须排在守护化之前，否则为了失败会先 fork 一次');
    }
}
