<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Monitor\ProcessMonitor;
use PHPUnit\Framework\TestCase;

final class ProcessMonitorTest extends TestCase
{
    private ProcessMonitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new ProcessMonitor();
        $this->monitor->start();
    }

    protected function tearDown(): void
    {
        $this->monitor->stop();
    }

    public function testIsProcessAlive(): void
    {
        // 当前进程存活
        $this->monitor->register(getmypid());
        $alive = $this->monitor->check(getmypid());
        $this->assertSame('healthy', $alive['status']);
        $this->assertTrue($alive['healthy']);

        // 不存在的 pid：posix_kill 返回 false 且 errno=ESRCH(3) → 判为 dead
        $this->monitor->register(999999);
        $dead = $this->monitor->check(999999);
        $this->assertSame('dead', $dead['status']);
        $this->assertFalse($dead['healthy']);
    }

    public function testCpuIsPercentageNotCumulativeSeconds(): void
    {
        $pid = getmypid();
        $this->monitor->register($pid);

        // 连续两次 check：确保 CPU 采样基线与速率计算均不报错
        $this->monitor->check($pid);
        usleep(50000);
        $result = $this->monitor->check($pid);

        $this->assertArrayHasKey('cpu', $result);
        $this->assertIsFloat($result['cpu']);
        // 关键回归守卫：旧实现返回「累计 CPU 秒数」（可能 >100），新实现是 0~100 的百分比
        $this->assertGreaterThanOrEqual(0.0, $result['cpu']);
        $this->assertLessThanOrEqual(100.0, $result['cpu']);

        $this->assertArrayHasKey('memory', $result);
        $this->assertIsInt($result['memory']);
        $this->assertGreaterThanOrEqual(0, $result['memory']);
    }

    public function testBusyChildReportsBoundedCpuPercentage(): void
    {
        $pid = pcntl_fork();

        if ($pid === 0) {
            // 子进程：持续占用 CPU
            $x = 0;
            while (true) {
                $x += sqrt($x + 1);
            }
            exit(0);
        }

        $this->assertGreaterThan(0, $pid, 'fork 失败');
        $this->monitor->register($pid);

        // 首帧建立基线
        $this->monitor->check($pid);
        usleep(400000);
        $result = $this->monitor->check($pid);

        // 占用率必须落在 0~100 区间内（绝不可能是累计秒数那样的无界值）
        $this->assertGreaterThanOrEqual(0.0, $result['cpu']);
        $this->assertLessThanOrEqual(100.0, $result['cpu']);

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    public function testUnknownPidReturnsUnknown(): void
    {
        $result = $this->monitor->check(888888);

        $this->assertSame('unknown', $result['status']);
        $this->assertFalse($result['healthy']);
    }

    public function testSupportedReturnsBool(): void
    {
        $this->assertIsBool($this->monitor->supported());
    }
}
