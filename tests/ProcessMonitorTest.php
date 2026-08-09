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
        // 抬高 CPU / 内存阈值：本用例只验证「存活/死亡」判定契约，不依赖测试
        // 运行器在满负载下的瞬时 CPU/内存读数（满负载套件下 phpunit 进程内存
        // 可能短时超过 512MB 默认阈值而误判 unhealthy，导致用例偶发失败）。
        $this->monitor->setMaxCpuUsage(1000.0);
        $this->monitor->setMaxMemoryUsage(PHP_INT_MAX);

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

    /**
     * 回归守卫：重启回调抛异常不得穿透 checkAll()，
     * 否则单个进程的重启逻辑出错会打死整轮巡检。
     */
    public function testThrowingRestartCallbackDoesNotAbortCheckAll(): void
    {
        $this->monitor->register(999999);
        $this->monitor->register(999998);

        $seen = [];
        $this->monitor->onRestart(function (int $pid) use (&$seen): void {
            $seen[] = $pid;

            throw new \RuntimeException('重启回调炸了');
        });

        $results = $this->monitor->checkAll();

        $this->assertCount(2, $results, '两个进程都必须完成巡检');
        $this->assertSame('dead', $results[999999]['status']);
        $this->assertSame('dead', $results[999998]['status']);
        $this->assertSame([999999, 999998], $seen);
        $this->assertSame(2, $this->monitor->getRestartCount());
    }

    /**
     * 回归守卫：不健康回调抛异常同样不得中断整轮巡检。
     */
    public function testThrowingUnhealthyCallbackDoesNotAbortCheckAll(): void
    {
        // 999999 必然 dead；当前进程放宽阈值，避免依赖运行器瞬时 CPU/内存读数
        // （满负载套件下 phpunit 进程 CPU 可能短时 >80% 而误判 unhealthy，导致用例偶发失败）。
        $this->monitor->register(999999);
        $this->monitor->register(getmypid(), ['memory_limit' => PHP_INT_MAX, 'cpu_limit' => 1000.0]);

        $this->monitor->onUnhealthy(function (): void {
            throw new \RuntimeException('不健康回调炸了');
        });

        $results = $this->monitor->checkAll();

        $this->assertCount(2, $results);
        $metrics = $this->monitor->getMetrics();
        $this->assertSame(1, $metrics['healthy']);
        $this->assertSame(1, $metrics['unhealthy']);
    }

    public function testRestartAttemptsAreCapped(): void
    {
        $this->monitor->setMaxRestartAttempts(2);
        $this->monitor->register(999999);

        $attempts = 0;
        $this->monitor->onRestart(function () use (&$attempts): void {
            $attempts++;
        });

        $this->monitor->checkAll();
        $this->monitor->checkAll();
        $this->monitor->checkAll();
        $this->monitor->checkAll();

        $this->assertSame(2, $attempts, '超过上限后不得继续触发重启回调');

        $this->monitor->resetRestartAttempts(999999);
        $this->monitor->checkAll();
        $this->assertSame(3, $attempts, 'reset 之后应允许重新尝试');
    }
}
