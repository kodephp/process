<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Contracts\ProcessInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Master\MasterProcess;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * MasterProcess 主循环错误边界回归测试。
 *
 * 关注点：runEventLoop 单次迭代（tick）中，用户回调 / Worker 检查抛出的异常
 * 必须被就地隔离、绝不允许穿透外层循环导致 master 崩溃（连带所有 worker 死亡）。
 */
class MasterProcessTest extends TestCase
{
    private function makeMaster(array $config = []): MasterProcess
    {
        return new MasterProcess($config + ['heartbeat_interval' => 0.0], new NullLogger());
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $r = new ReflectionClass($obj);
        $p = $r->getProperty($name);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    private function getProp(object $obj, string $name): mixed
    {
        $r = new ReflectionClass($obj);
        $p = $r->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($obj);
    }

    private function callTick(MasterProcess $master): ?\Throwable
    {
        $e = null;
        try {
            $r = new ReflectionClass($master);
            $m = $r->getMethod('tick');
            $m->setAccessible(true);
            $m->invoke($master);
        } catch (\Throwable $e) {
        }
        return $e;
    }

    /**
     * 用户注册的 heartbeat 回调抛出异常时，tick 必须存活（不抛出、仍 running）。
     */
    public function testHeartbeatUserCallbackExceptionIsIsolated(): void
    {
        $master = $this->makeMaster();
        $this->setProp($master, 'running', true);
        $this->setProp($master, 'lastHeartbeat', 0.0);
        $this->setProp($master, 'heartbeatInterval', 0.0);

        $invoked = false;
        $this->setProp($master, 'callbacks', [
            'heartbeat' => function () use (&$invoked): void {
                $invoked = true;
                throw new \RuntimeException('boom from user heartbeat callback');
            },
        ]);

        $e = $this->callTick($master);

        self::assertNull($e, 'tick() 不应因用户 heartbeat 回调异常而抛出');
        self::assertTrue($invoked, 'heartbeat 回调应被实际调用');
        self::assertTrue($this->getProp($master, 'running'), '异常后 master 仍应存活');
    }

    /**
     * Worker 的 heartbeat() 抛出异常时，tick 必须存活（不抛出、仍 running）。
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testWorkerHeartbeatExceptionIsIsolated(): void
    {
        $master = $this->makeMaster();
        $this->setProp($master, 'running', true);
        $this->setProp($master, 'lastHeartbeat', 0.0);
        $this->setProp($master, 'heartbeatInterval', 0.0);

        $worker = $this->createMock(WorkerInterface::class);
        $worker->method('heartbeat')
            ->willThrowException(new \RuntimeException('worker heartbeat boom'));

        $this->setProp($master, 'workers', [1 => $worker]);

        $e = $this->callTick($master);

        self::assertNull($e, 'tick() 不应因 worker heartbeat 异常而抛出');
        self::assertTrue($this->getProp($master, 'running'), '异常后 master 仍应存活');
    }

    /**
     * 正常（无异常）一次 tick 不应改变 running 状态，且能顺利跑完。
     */
    public function testTickRunsWithoutErrorWhenHealthy(): void
    {
        $master = $this->makeMaster();
        $this->setProp($master, 'running', true);
        $this->setProp($master, 'lastHeartbeat', 0.0);
        $this->setProp($master, 'heartbeatInterval', 0.0);

        $e = $this->callTick($master);

        self::assertNull($e, '健康状态下 tick() 不应抛出');
        self::assertTrue($this->getProp($master, 'running'));
    }

    // ---- 子进程回收 / 僵尸处理（#191） ----

    private function makeWorkerMock(int $id, int $pid): WorkerInterface
    {
        $w = $this->createMock(WorkerInterface::class);
        $w->method('getId')->willReturn($id);
        $w->method('getPid')->willReturn($pid);
        return $w;
    }

    private function callMethodByName(MasterProcess $master, string $name, mixed ...$args): mixed
    {
        $r = new ReflectionClass($master);
        $m = $r->getMethod($name);
        $m->setAccessible(true);
        return $m->invoke($master, ...$args);
    }

    /**
     * 退出状态解读必须区分「正常退出」与「被信号杀死」。
     * 原实现直接 pcntl_wexitstatus($status) 在信号杀死时会得到无意义值并触发 warning。
     */
    public function testInterpretExitStatusNormalAndSignaled(): void
    {
        // 构造 wait 状态：512 = (2 << 8) 即退出码 2；9 = SIGKILL；11 = SIGSEGV；0 = 干净退出
        $cases = [
            'clean0' => [0, ['signaled' => false, 'exit_code' => 0, 'signal_name' => '']],
            'exit2'  => [512, ['signaled' => false, 'exit_code' => 2, 'signal_name' => '']],
            'sig9'   => [9, ['signaled' => true, 'exit_code' => -1, 'signal_name' => 'SIGKILL']],
            'sig11'  => [11, ['signaled' => true, 'exit_code' => -1, 'signal_name' => 'SIGSEGV']],
        ];

        foreach ($cases as $label => [$status, $expect]) {
            $info = $this->callMethodByName($this->makeMaster(), 'interpretExitStatus', $status);
            self::assertSame($expect['signaled'], $info['signaled'], $label . ': signaled');
            self::assertSame($expect['exit_code'], $info['exit_code'], $label . ': exit_code');
            self::assertSame($expect['signal_name'], $info['signal_name'], $label . ': signal_name');
        }
    }

    /**
     * 运行中、注入重生器时，worker 干净退出（如达到 max_requests）应自动重生以维持池容量。
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testWorkerCleanExitTriggersRespawn(): void
    {
        $master = $this->makeMaster();
        $this->setProp($master, 'state', ProcessInterface::STATE_RUNNING);

        $master->addWorker($this->makeWorkerMock(1, 1001));

        $spawnCount = 0;
        $master->setWorkerSpawner(function () use (&$spawnCount): WorkerInterface {
            $spawnCount++;
            return $this->makeWorkerMock(2, 1002);
        });

        // 模拟 wait 状态：0 = 干净退出
        $this->callMethodByName($master, 'handleWorkerExit', 0, 0);

        self::assertSame(1, $spawnCount, '干净退出应触发一次重生');
        self::assertCount(1, $master->getWorkers(), '重生后池容量维持');
    }

    /**
     * worker 反复异常退出时，应在达到上限后停止自动重生（防 fork bomb）。
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testWorkerAbnormalExitStopsAfterMaxAttempts(): void
    {
        $master = $this->makeMaster();
        $this->setProp($master, 'state', ProcessInterface::STATE_RUNNING);
        $this->setProp($master, 'maxRestartAttempts', 5);

        $master->addWorker($this->makeWorkerMock(1, 1001));

        $spawnCount = 0;
        $master->setWorkerSpawner(function () use (&$spawnCount): WorkerInterface {
            $spawnCount++;
            return $this->makeWorkerMock(99, 9000 + $spawnCount);
        });

        // 512 = 退出码 2（异常）。反复触发，直到超过上限
        for ($i = 0; $i < 6; $i++) {
            $this->callMethodByName($master, 'handleWorkerExit', 0, 512);
        }

        self::assertSame(5, $spawnCount, '达到上限后不应继续重生');
        self::assertCount(0, $master->getWorkers(), '超限后不再重生，池为空');
    }

    /**
     * 停止/重启阶段（非 RUNNING）worker 退出不应触发重生，否则 master 关不掉。
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testWorkerExitDoesNotRespawnWhenNotRunning(): void
    {
        $master = $this->makeMaster();
        $this->setProp($master, 'state', ProcessInterface::STATE_STOPPING);

        $master->addWorker($this->makeWorkerMock(1, 1001));

        $spawnCount = 0;
        $master->setWorkerSpawner(function () use (&$spawnCount): WorkerInterface {
            $spawnCount++;
            return $this->makeWorkerMock(2, 1002);
        });

        $this->callMethodByName($master, 'handleWorkerExit', 0, 512);

        self::assertSame(0, $spawnCount, '非运行状态下不应重生');
        self::assertCount(0, $master->getWorkers());
    }
}
