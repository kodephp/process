<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Monitor\ConnectionHeartbeat;
use PHPUnit\Framework\TestCase;

final class ConnectionHeartbeatTest extends TestCase
{
    public function testRegisterAndUpdateActivity(): void
    {
        $hb = new ConnectionHeartbeat(55, 110);
        $hb->register(1, ['ip' => '127.0.0.1']);

        $this->assertSame(1, $hb->count());
        $this->assertSame('127.0.0.1', $hb->getConnection(1)['metadata']['ip']);

        $hb->updateActivity(1);
        $this->assertSame('active', $hb->getConnection(1)['status']);

        $hb->unregister(1);
        $this->assertNull($hb->getConnection(1));
    }

    public function testCheckClassifiesConnections(): void
    {
        $hb = new ConnectionHeartbeat(2, 5);
        $hb->register(1);
        $hb->register(2);
        $hb->register(3);

        // 直接改写 last_message_time 无法从外部完成，改用极短阈值模拟
        $hb->setInterval(0)->setTimeout(3600);
        $result = $hb->check();

        // interval=0 → 所有连接都进入 need_heartbeat 或 active（取决于同秒与否）
        $this->assertSame(3, count($result['active']) + count($result['need_heartbeat']));
        $this->assertCount(0, $result['timeout']);
    }

    public function testTimeoutConnectionIsReportedAndUnregistered(): void
    {
        $hb = new ConnectionHeartbeat(0, -1);
        $hb->register(7);

        $seen = [];
        $hb->onTimeout(function (int $id) use (&$seen): void {
            $seen[] = $id;
        });

        $result = $hb->check();

        $this->assertCount(1, $result['timeout']);
        $this->assertSame([7], $seen);
        // 超时连接必须被摘除，否则会无限重复触发超时回调
        $this->assertSame(0, $hb->count());
    }

    /**
     * 回归守卫：单个连接的超时回调抛异常时，不得中断整轮扫描，
     * 也不得跳过 unregister（否则该连接会永远残留并每轮重复回调）。
     */
    public function testThrowingTimeoutCallbackDoesNotAbortSweep(): void
    {
        $hb = new ConnectionHeartbeat(0, -1);
        $hb->register(1);
        $hb->register(2);
        $hb->register(3);

        $handled = [];
        $hb->onTimeout(function (int $id) use (&$handled): void {
            $handled[] = $id;

            if ($id === 2) {
                throw new \RuntimeException('回调炸了');
            }
        });

        $result = $hb->check();

        $this->assertCount(3, $result['timeout']);
        $this->assertSame([1, 2, 3], $handled, '异常连接之后的连接仍必须被处理');
        $this->assertSame(0, $hb->count(), '抛异常的连接同样必须被摘除');
    }

    /**
     * 回归守卫：心跳发送回调抛异常时返回 false 而非穿透，
     * 保证 sendHeartbeats() 能继续处理后续连接。
     */
    public function testThrowingHeartbeatCallbackDoesNotAbortSweep(): void
    {
        // interval=-1 让刚注册的连接（elapsed=0）立即满足发送条件
        $hb = new ConnectionHeartbeat(-1, 3600);
        $hb->register(1);
        $hb->register(2);
        $hb->register(3);

        $touched = [];
        $hb->onHeartbeat(function (int $id) use (&$touched): bool {
            $touched[] = $id;

            if ($id === 2) {
                throw new \RuntimeException('心跳回调炸了');
            }

            return true;
        });

        $sent = $hb->sendHeartbeats();

        $this->assertSame([1, 2, 3], $touched);
        $this->assertSame(2, $sent, '抛异常的连接计为发送失败，其余照常计数');
    }

    public function testHeartbeatCallbackReturnValueIsCoercedToBool(): void
    {
        $hb = new ConnectionHeartbeat();
        $hb->register(1);
        $hb->onHeartbeat(fn(): mixed => 1);

        $this->assertTrue($hb->sendHeartbeat(1));
        $this->assertSame(1, $hb->getConnection(1)['heartbeat_count']);
    }

    public function testSendHeartbeatOnUnknownConnection(): void
    {
        $hb = new ConnectionHeartbeat();
        $this->assertFalse($hb->sendHeartbeat(404));
    }

    public function testStatsAndLifecycle(): void
    {
        $hb = new ConnectionHeartbeat(3600, 7200);
        $hb->register(1);
        $hb->register(2);

        $stats = $hb->getStats();
        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(0, $stats['timeout']);
        $this->assertSame(2, $hb->getActiveCount());
        $this->assertSame(0, $hb->getTimeoutCount());
        $this->assertCount(2, $hb->getAll());

        $this->assertFalse($hb->isRunning());
        $hb->start();
        $this->assertTrue($hb->isRunning());
        $hb->stop();
        $this->assertFalse($hb->isRunning());

        $hb->clear();
        $this->assertSame(0, $hb->count());
    }
}
