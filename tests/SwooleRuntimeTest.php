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
}
