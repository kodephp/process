<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Process;
use PHPUnit\Framework\TestCase;

use function Kode\Process\is_process_alive;

final class ProcessTest extends TestCase
{
    /** 一个几乎不可能被占用的 pid，用于制造 ESRCH。 */
    private const DEAD_PID = 999_999;

    public function testIsProcessAliveDetectsSelf(): void
    {
        $this->assertTrue(Process::isProcessAlive(posix_getpid()));
    }

    public function testIsProcessAliveDetectsMissingProcess(): void
    {
        $this->assertFalse(Process::isProcessAlive(self::DEAD_PID));
    }

    public function testIsProcessAliveIsNotPoisonedByStaleErrno(): void
    {
        // posix 的 errno 只在失败时写入、成功时不清零。原实现
        // `posix_kill($pid,0) && posix_get_last_error() !== 3` 会在任意一次
        // 先前失败留下 ESRCH(3) 后，把所有存活进程都判成已死。
        $this->assertFalse(Process::isProcessAlive(self::DEAD_PID));

        $this->assertTrue(
            Process::isProcessAlive(posix_getpid()),
            '残留的 ESRCH 不应污染后续判活'
        );
    }

    public function testIsProcessAliveRejectsNonPositivePid(): void
    {
        // pid 0 是进程组、-1 是「所有进程」，用它们发信号语义完全不同
        $this->assertFalse(Process::isProcessAlive(0));
        $this->assertFalse(Process::isProcessAlive(-1));
    }

    public function testHelperFunctionSharesTheSameSemantics(): void
    {
        is_process_alive(self::DEAD_PID);

        $this->assertTrue(is_process_alive(posix_getpid()));
        $this->assertFalse(is_process_alive(self::DEAD_PID));
    }
}
