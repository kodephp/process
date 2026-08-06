<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use InvalidArgumentException;
use Kode\Process\Reactor\EventLoop;
use Kode\Process\Reactor\EvLoop;
use Kode\Process\Reactor\LoopFactory;
use Kode\Process\Reactor\LoopInterface;
use Kode\Process\Reactor\SelectLoop;
use PHPUnit\Framework\TestCase;

/**
 * 事件循环工厂：择优、显式指定、自检。
 *
 * 设计原则是「可选加速 + 零扩展兜底」，因此本测试只对 SelectLoop 做强断言
 * （它必须在任何环境可用），ext-event / ext-ev 相关断言按实际安装情况生效。
 */
final class LoopFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        LoopFactory::setGlobal(null);
    }

    public function testSelectLoopIsAlwaysAvailable(): void
    {
        $this->assertTrue(SelectLoop::isSupported(), 'stream_select 兜底必须在任何环境可用');
        $this->assertContains('select', LoopFactory::available());
    }

    public function testAutoCreateReturnsLoopInterface(): void
    {
        $loop = LoopFactory::create();

        $this->assertInstanceOf(LoopInterface::class, $loop);
        $this->assertSame(LoopFactory::preferred(), $loop->stats()['driver']);

        $loop->destroy();
    }

    public function testPreferredFollowsPriorityOrder(): void
    {
        $expected = match (true) {
            EventLoop::isSupported() => 'event',
            EvLoop::isSupported()    => 'ev',
            default                  => 'select',
        };

        $this->assertSame($expected, LoopFactory::preferred());
    }

    public function testAvailableIsSortedByPriorityDesc(): void
    {
        $available = LoopFactory::available();

        $this->assertNotEmpty($available);
        $this->assertSame(LoopFactory::preferred(), $available[0]);
        $this->assertSame('select', $available[array_key_last($available)], 'select 权重最低，应排在最后');
    }

    public function testExplicitDriverSelection(): void
    {
        $loop = LoopFactory::create('select');

        $this->assertSame('select', $loop->stats()['driver']);

        $loop->destroy();
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/未知事件循环驱动/');

        LoopFactory::create('libuv');
    }

    public function testGlobalLoopIsShared(): void
    {
        $a = LoopFactory::global();
        $b = LoopFactory::global();

        $this->assertSame($a, $b);
    }

    public function testSetGlobalOverridesInstance(): void
    {
        $custom = new SelectLoop();
        LoopFactory::setGlobal($custom);

        $this->assertSame($custom, LoopFactory::global());

        $custom->destroy();
    }

    public function testDiagnoseCoversAllDrivers(): void
    {
        $report = LoopFactory::diagnose();

        foreach (['event', 'ev', 'select'] as $driver) {
            $this->assertArrayHasKey($driver, $report);
            $this->assertIsBool($report[$driver]['supported']);
            $this->assertIsInt($report[$driver]['priority']);
            $this->assertIsBool($report[$driver]['preferred']);
        }

        $preferred = array_filter($report, static fn (array $r): bool => $r['preferred']);
        $this->assertCount(1, $preferred, '有且只有一个驱动被标记为 preferred');
    }
}
