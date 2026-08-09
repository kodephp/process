<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

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
}
