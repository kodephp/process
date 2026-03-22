<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Process\Signal;
use Kode\Process\Contracts\ProcessInterface;
use Kode\Process\Contracts\WorkerInterface;
use Kode\Process\Contracts\IPCInterface;
use Kode\Process\Contracts\PoolInterface;
use Kode\Process\Contracts\MonitorInterface;

/**
 * 基础结构测试
 */
class ProcessStructureTest extends TestCase
{
    public function testSignalConstants(): void
    {
        $this->assertEquals(15, Signal::TERM);
        $this->assertEquals(2, Signal::INT);
        $this->assertEquals(defined('SIGUSR1') ? SIGUSR1 : 30, Signal::USR1);
        $this->assertEquals(defined('SIGUSR2') ? SIGUSR2 : 31, Signal::USR2);
    }

    public function testSignalGetName(): void
    {
        $this->assertEquals('SIGTERM', Signal::getName(Signal::TERM));
        $this->assertEquals('SIGINT', Signal::getName(Signal::INT));
        $this->assertEquals('SIGUSR1', Signal::getName(Signal::USR1));
    }

    public function testSignalGetDescription(): void
    {
        $this->assertStringContainsString('终止', Signal::getDescription(Signal::TERM));
        $this->assertStringContainsString('中断', Signal::getDescription(Signal::INT));
    }

    public function testSignalIsCatchable(): void
    {
        $this->assertTrue(Signal::isCatchable(Signal::TERM));
        $this->assertTrue(Signal::isCatchable(Signal::INT));
        $this->assertFalse(Signal::isCatchable(Signal::KILL));
        $this->assertFalse(Signal::isCatchable(Signal::STOP));
    }

    public function testProcessInterfaceConstants(): void
    {
        $this->assertEquals('idle', ProcessInterface::STATE_IDLE);
        $this->assertEquals('starting', ProcessInterface::STATE_STARTING);
        $this->assertEquals('running', ProcessInterface::STATE_RUNNING);
        $this->assertEquals('stopping', ProcessInterface::STATE_STOPPING);
        $this->assertEquals('stopped', ProcessInterface::STATE_STOPPED);
        $this->assertEquals('error', ProcessInterface::STATE_ERROR);
    }

    public function testWorkerInterfaceConstants(): void
    {
        $this->assertEquals('free', WorkerInterface::STATUS_FREE);
        $this->assertEquals('busy', WorkerInterface::STATUS_BUSY);
        $this->assertEquals('overloaded', WorkerInterface::STATUS_OVERLOADED);
    }

    public function testIPCInterfaceConstants(): void
    {
        $this->assertEquals('socket', IPCInterface::TYPE_SOCKET);
        $this->assertEquals('shared_memory', IPCInterface::TYPE_SHARED_MEMORY);
        $this->assertEquals('message_queue', IPCInterface::TYPE_MESSAGE_QUEUE);
        $this->assertEquals('pipe', IPCInterface::TYPE_PIPE);
    }

    public function testPoolInterfaceConstants(): void
    {
        $this->assertEquals('round_robin', PoolInterface::STRATEGY_ROUND_ROBIN);
        $this->assertEquals('least_connections', PoolInterface::STRATEGY_LEAST_CONNECTIONS);
        $this->assertEquals('least_load', PoolInterface::STRATEGY_LEAST_LOAD);
        $this->assertEquals('random', PoolInterface::STRATEGY_RANDOM);
        $this->assertEquals('weighted', PoolInterface::STRATEGY_WEIGHTED);
    }

    public function testMonitorInterfaceConstants(): void
    {
        $this->assertEquals('healthy', MonitorInterface::HEALTH_HEALTHY);
        $this->assertEquals('degraded', MonitorInterface::HEALTH_DEGRADED);
        $this->assertEquals('unhealthy', MonitorInterface::HEALTH_UNHEALTHY);
    }

    public function testProcessExceptionFactoryMethods(): void
    {
        $exception = \Kode\Process\Exceptions\ProcessException::forkFailed('test reason');
        $this->assertInstanceOf(\Kode\Process\Exceptions\ProcessException::class, $exception);
        $this->assertStringContainsString('fork', $exception->getMessage());

        $exception = \Kode\Process\Exceptions\ProcessException::processNotFound(12345);
        $this->assertStringContainsString('12345', $exception->getMessage());
    }

    public function testIPCExceptionFactoryMethods(): void
    {
        $exception = \Kode\Process\Exceptions\IPCException::connectionFailed('socket', 'test error');
        $this->assertInstanceOf(\Kode\Process\Exceptions\IPCException::class, $exception);
        $this->assertStringContainsString('socket', $exception->getMessage());

        $exception = \Kode\Process\Exceptions\IPCException::bufferOverflow(1000, 500);
        $this->assertStringContainsString('1000', $exception->getMessage());
    }

    public function testWorkerExceptionFactoryMethods(): void
    {
        $exception = \Kode\Process\Exceptions\WorkerException::workerNotFound(1);
        $this->assertInstanceOf(\Kode\Process\Exceptions\WorkerException::class, $exception);
        $this->assertStringContainsString('1', $exception->getMessage());

        $exception = \Kode\Process\Exceptions\WorkerException::poolFull(10);
        $this->assertStringContainsString('10', $exception->getMessage());
    }

    public function testThreadExceptionFactoryMethods(): void
    {
        $exception = \Kode\Process\Exceptions\ThreadException::extensionNotLoaded();
        $this->assertInstanceOf(\Kode\Process\Exceptions\ThreadException::class, $exception);
        $this->assertStringContainsString('pthreads', $exception->getMessage());
    }

    public function testSignalExceptionFactoryMethods(): void
    {
        $exception = \Kode\Process\Exceptions\SignalException::unsupportedSignal(99);
        $this->assertInstanceOf(\Kode\Process\Exceptions\SignalException::class, $exception);
        $this->assertStringContainsString('99', $exception->getMessage());
    }
}
