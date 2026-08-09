<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Driver\SwooleRuntime;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * Swoole 兼容适配器的契约映射测试。
 *
 * 不在此重复验证 Swoole 自身的 I/O 行为——只确认地址协议、能力集、
 * 定时器与状态快照按本包契约正确暴露。
 */
final class SwooleRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!SwooleRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 ext-swoole');
        }
    }

    public function testMetadata(): void
    {
        $this->assertSame(RuntimeType::Swoole, SwooleRuntime::type());
        $this->assertSame(swoole_version(), SwooleRuntime::version());
    }

    public function testCapabilitiesIncludeSwooleExclusives(): void
    {
        $rt = new SwooleRuntime();

        $this->assertTrue($rt->supports(Capability::Coroutine));
        $this->assertTrue($rt->supports(Capability::TaskWorker));
        $this->assertTrue($rt->supports(Capability::AsyncIo));
        $this->assertTrue($rt->supports(Capability::UdpServer));
    }

    public function testSupportedSchemes(): void
    {
        $rt = new SwooleRuntime();

        foreach (['tcp', 'udp', 'http', 'websocket'] as $i => $scheme) {
            $rt->listen(sprintf('%s://127.0.0.1:%d', $scheme, 19200 + $i));
        }

        $this->assertSame(4, $rt->stats()['listeners']);
    }

    public function testUnsupportedSchemeThrows(): void
    {
        $this->expectException(RuntimeNotSupportedException::class);
        $this->expectExceptionMessageMatches('/不支持协议/');

        (new SwooleRuntime())->listen('frame://127.0.0.1:19299');
    }

    public function testStatsBeforeStart(): void
    {
        $stats = (new SwooleRuntime())->stats();

        $this->assertSame('swoole', $stats['runtime']);
        $this->assertFalse($stats['running']);
        $this->assertSame(0, $stats['listeners']);
    }

    public function testDelTimerOnUnknownIdReturnsFalse(): void
    {
        $this->assertFalse((new SwooleRuntime())->delTimer(4242));
    }

    /**
     * 定时回调抛异常必须被隔离，绝不能穿透 Swoole 事件循环打死 worker。
     *
     * 用原生 Swoole 定时器驱动 reactor 退出，让上面的周期定时器真正触发若干次；
     * 若异常未被隔离，进程会在此处之前崩溃、测试直接失败。
     */
    public function testThrowingTimerCallbackIsIsolated(): void
    {
        $rt = new SwooleRuntime();
        $fired = new \stdClass();
        $fired->count = 0;

        $id = $rt->addTimer(0.02, static function () use ($fired): void {
            $fired->count++;
            throw new \RuntimeException('boom-from-timer');
        });
        $this->assertIsInt($id);

        \Swoole\Timer::after(300, static fn() => \Swoole\Event::exit());
        \Swoole\Event::wait();

        // 进程没崩（异常被隔离）且定时器确实多次触发
        self::assertGreaterThan(0, $fired->count);
    }

    /**
     * 一次性定时器触发后底层已自动移除，本端映射也应清理：delTimer 应返回 false。
     */
    public function testOneShotTimerCleansUpMapAfterFiring(): void
    {
        $rt = new SwooleRuntime();

        // 触发前可正常删除（尚未移除）
        $id = $rt->addTimer(0.02, static fn() => null, false);
        $this->assertIsInt($id);
        $this->assertTrue($rt->delTimer($id));

        // 再注册一个，让它真正触发一次
        $id2 = $rt->addTimer(0.02, static fn() => null, false);
        \Swoole\Timer::after(300, static fn() => \Swoole\Event::exit());
        \Swoole\Event::wait();

        // 触发后映射已清理：delTimer 返回 false（避免陈旧的 timer id 残留）
        self::assertFalse($rt->delTimer($id2));
    }
}
