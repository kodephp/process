<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\NodeStatus;
use PHPUnit\Framework\TestCase;

/**
 * 心跳年龄 → 健康状态的三段式推导。
 *
 * Suspect 是关键设计：ttl 到期不立即判死，留一个观察窗口，
 * 避免一次 GC 停顿或网络抖动就把好节点踢出集群（防抖）。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class NodeStatusTest extends TestCase
{
    public function testFreshHeartbeatIsUp(): void
    {
        $this->assertSame(NodeStatus::Up, NodeStatus::fromHeartbeatAge(0.0, 15.0));
        $this->assertSame(NodeStatus::Up, NodeStatus::fromHeartbeatAge(14.9, 15.0));
        $this->assertSame(NodeStatus::Up, NodeStatus::fromHeartbeatAge(15.0, 15.0));
    }

    public function testWithinDoubleTtlIsSuspect(): void
    {
        $this->assertSame(NodeStatus::Suspect, NodeStatus::fromHeartbeatAge(15.1, 15.0));
        $this->assertSame(NodeStatus::Suspect, NodeStatus::fromHeartbeatAge(30.0, 15.0));
    }

    public function testBeyondDoubleTtlIsDown(): void
    {
        $this->assertSame(NodeStatus::Down, NodeStatus::fromHeartbeatAge(30.1, 15.0));
        $this->assertSame(NodeStatus::Down, NodeStatus::fromHeartbeatAge(INF, 15.0));
    }

    public function testHealthyOnlyCoversUp(): void
    {
        $this->assertTrue(NodeStatus::Up->isHealthy());
        $this->assertFalse(NodeStatus::Suspect->isHealthy());
        $this->assertFalse(NodeStatus::Down->isHealthy());
    }

    public function testAliveIncludesSuspect(): void
    {
        $this->assertTrue(NodeStatus::Up->isAlive());
        $this->assertTrue(NodeStatus::Suspect->isAlive(), '疑似失联仍算存活，等待观察窗口');
        $this->assertFalse(NodeStatus::Down->isAlive());
    }

    public function testLabels(): void
    {
        foreach (NodeStatus::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }

    public function testBackedValues(): void
    {
        $this->assertSame('up', NodeStatus::Up->value);
        $this->assertSame('suspect', NodeStatus::Suspect->value);
        $this->assertSame('down', NodeStatus::Down->value);
    }
}
