<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Driver\SwooleRuntime;
use Kode\Process\Runtime\Driver\WorkermanRuntime;
use PHPUnit\Framework\TestCase;

/**
 * 能力枚举与各运行时能力集的一致性。
 *
 * 应用应通过 supports() 做优雅降级，因此这里重点保证：
 * 能力集互不越界（Workerman 不谎报协程/TaskWorker），且 Swoole 是能力超集。
 */
final class CapabilityTest extends TestCase
{
    public function testAllCasesHaveDistinctValuesAndLabels(): void
    {
        $values = array_map(static fn (Capability $c): string => $c->value, Capability::cases());
        $labels = array_map(static fn (Capability $c): string => $c->label(), Capability::cases());

        $this->assertCount(count($values), array_unique($values));
        $this->assertCount(count($labels), array_unique($labels));
    }

    public function testFromString(): void
    {
        $this->assertSame(Capability::SharedTable, Capability::from('shared_table'));
        $this->assertSame(Capability::HotReload, Capability::from('hot_reload'));
        $this->assertNull(Capability::tryFrom('teleport'));
    }

    public function testWorkermanDoesNotOverclaim(): void
    {
        if (!WorkermanRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 workerman/workerman');
        }

        $caps = (new WorkermanRuntime())->capabilities();

        $this->assertNotContains(Capability::Coroutine, $caps);
        $this->assertNotContains(Capability::TaskWorker, $caps);
        $this->assertNotContains(Capability::AsyncIo, $caps);
        $this->assertContains(Capability::Timer, $caps);
        $this->assertContains(Capability::HotReload, $caps);
        $this->assertContains(Capability::ReusePort, $caps);
    }

    public function testSwooleCoversWorkermanCapabilities(): void
    {
        if (!SwooleRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 ext-swoole');
        }
        if (!WorkermanRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 workerman/workerman');
        }

        $swoole   = (new SwooleRuntime())->capabilities();
        $workerman = (new WorkermanRuntime())->capabilities();

        foreach ($workerman as $cap) {
            $this->assertContains($cap, $swoole, sprintf('Swoole 应覆盖 Workerman 的能力「%s」', $cap->label()));
        }

        $this->assertContains(Capability::Coroutine, $swoole);
        $this->assertContains(Capability::TaskWorker, $swoole);
    }

    public function testWorkermanHasNoCoroutineOrTaskWorker(): void
    {
        if (!WorkermanRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 workerman/workerman');
        }

        $caps = (new WorkermanRuntime())->capabilities();

        $this->assertNotContains(Capability::Coroutine, $caps);
        $this->assertNotContains(Capability::TaskWorker, $caps);
        $this->assertContains(Capability::HotReload, $caps);
        $this->assertContains(Capability::ReusePort, $caps);
    }
}
