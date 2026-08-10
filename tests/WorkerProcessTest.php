<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Worker\WorkerProcess;
use PHPUnit\Framework\TestCase;

/**
 * Worker 进程单元行为（不涉及真实 fork，fork 路径由集成测试覆盖）。
 *
 * 重点验证两处曾导致进程服务器整体失效的隐患：
 *  - parentPid 必须在子进程内（fork 之后）重置为真正父进程（master），否则 checkParent
 *    会误判父进程已退出，子进程立即自杀并以退出码 0 触发无限重生；
 *  - isRunning() 只做存活探测不得回收退出状态，否则会架空 MasterProcess 的自动重生。
 */
final class WorkerProcessTest extends TestCase
{
    public function testPrepareWorkerResetsParentPidToTrueParent(): void
    {
        $worker = new WorkerProcess(1);

        // 模拟 master 在构造对象时记录的 parentPid（shell 之类，非本进程真正父进程）
        $ref = new \ReflectionClass($worker);
        $pp = $ref->getProperty('parentPid');
        $pp->setAccessible(true);
        $pp->setValue($worker, 999999);

        $m = $ref->getMethod('prepareWorker');
        $m->setAccessible(true);
        $m->invoke($worker);

        // 子进程内：pid 应为本进程，parentPid 必须重置为真正父进程（fork 父 = master）
        $this->assertSame(posix_getpid(), $worker->getPid());
        $this->assertSame(posix_getppid(), $pp->getValue($worker));
        $this->assertTrue($worker->isRunning());
    }

    public function testCheckParentDoesNotSuicideWhenParentAlive(): void
    {
        $worker = new WorkerProcess(2);

        $ref = new \ReflectionClass($worker);
        $pp = $ref->getProperty('parentPid');
        $pp->setAccessible(true);
        $pp->setValue($worker, posix_getppid()); // 正确的父进程

        $pid = $ref->getProperty('pid');
        $pid->setAccessible(true);
        $pid->setValue($worker, posix_getpid());

        $running = $ref->getProperty('running');
        $running->setAccessible(true);
        $running->setValue($worker, true);

        $m = $ref->getMethod('checkParent');
        $m->setAccessible(true);
        $m->invoke($worker);

        // 父进程存活时不应误判自杀
        $this->assertTrue($worker->isRunning());
    }

    public function testIsRunningProbesWithoutReaping(): void
    {
        $worker = new WorkerProcess(3);

        $ref = new \ReflectionClass($worker);
        $pid = $ref->getProperty('pid');
        $pid->setAccessible(true);

        // 未 start：pid 0 → false
        $this->assertFalse($worker->isRunning());

        // 指向一个不存在的 pid：安全返回 false，且不抛、不回收、不改状态
        $pid->setValue($worker, 999999);
        $this->assertFalse($worker->isRunning());

        // 指向自身：应判定存活（探测而非回收）
        $pid->setValue($worker, posix_getpid());
        $this->assertTrue($worker->isRunning());
    }
}
