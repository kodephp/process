<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Monitor\Heartbeat;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase
{
    public function testRegisterBeatAndUnregister(): void
    {
        $hb = new Heartbeat();
        $hb->register(100, ['role' => 'worker']);

        $this->assertCount(1, $hb->getAll());
        $this->assertTrue($hb->beat(100, ['load' => 3]));

        $info = $hb->checkPid(100);
        $this->assertSame('active', $info['status']);
        $this->assertSame(1, $info['count']);
        $this->assertSame(['load' => 3], $info['data']);

        $hb->unregister(100);
        $this->assertSame('unknown', $hb->checkPid(100)['status']);
    }

    public function testBeatAutoRegistersUnknownPid(): void
    {
        $hb = new Heartbeat();
        $hb->beat(200);

        $this->assertSame('active', $hb->checkPid(200)['status']);
        $this->assertCount(1, $hb->getAll());
    }

    public function testCheckSeparatesActiveAndTimeout(): void
    {
        $hb = new Heartbeat();
        $hb->setTimeout(3600.0);
        $hb->register(1);
        $hb->register(2);

        $results = $hb->check();
        $this->assertCount(2, $results['active']);
        $this->assertCount(0, $results['timeout']);
        $this->assertSame(2, $hb->getActiveCount());
        $this->assertSame(0, $hb->getTimeoutCount());

        // 负超时阈值让所有心跳立刻判定为超时
        $hb->setTimeout(-1.0);
        $results = $hb->check();
        $this->assertCount(2, $results['timeout']);
        $this->assertCount(0, $results['active']);
        $this->assertSame(2, $hb->getTimeoutCount());
        $this->assertSame('timeout', $hb->getAll()[1]['status']);
    }

    /**
     * 回归守卫：任一超时回调抛异常时，既不能中断 check() 的整轮扫描，
     * 也不能阻断后续回调的执行。
     */
    public function testThrowingTimeoutCallbackDoesNotAbortCheck(): void
    {
        $hb = new Heartbeat();
        $hb->setTimeout(-1.0);
        $hb->register(11);
        $hb->register(22);

        $second = [];

        $hb->onTimeout(function (): void {
            throw new \RuntimeException('第一个回调炸了');
        });
        $hb->onTimeout(function (int $pid) use (&$second): void {
            $second[] = $pid;
        });

        $results = $hb->check();

        $this->assertCount(2, $results['timeout'], '两个 pid 都必须完成扫描');
        $this->assertSame([11, 22], $second, '前一个回调抛异常不得阻断后续回调');
    }

    public function testCheckPidOnUnknownPid(): void
    {
        $hb = new Heartbeat();
        $result = $hb->checkPid(999);

        $this->assertSame('unknown', $result['status']);
        $this->assertSame(999, $result['pid']);
    }

    public function testIntervalTimeoutAndLifecycle(): void
    {
        $hb = new Heartbeat();

        $hb->setInterval(2.5);
        $this->assertSame(2.5, $hb->getInterval());

        $hb->setTimeout(12.5);
        $this->assertSame(12.5, $hb->getTimeout());

        $this->assertFalse($hb->isRunning());
        $hb->start();
        $this->assertTrue($hb->isRunning());
        $hb->stop();
        $this->assertFalse($hb->isRunning());

        $hb->register(1);
        $hb->clear();
        $this->assertCount(0, $hb->getAll());
    }
}
