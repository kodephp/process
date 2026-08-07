<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * 运行时类型枚举：取值、择优权重、外部依赖标记。
 *
 * 权重顺序体现本包的架构立场：**默认使用自研 Native 运行时**（零扩展依赖、开箱即用），
 * 因此 Native 权重最高；Swoole / Workerman 作为可选接入保留，供已有技术栈的项目复用宿主生态。
 * Native 是本包自研实现，isExternal() 为 false。
 */
final class RuntimeTypeTest extends TestCase
{
    public function testCases(): void
    {
        $values = array_map(static fn (RuntimeType $t): string => $t->value, RuntimeType::cases());

        $this->assertSame(['native', 'swoole', 'workerman'], $values);
    }

    public function testFromString(): void
    {
        $this->assertSame(RuntimeType::Native, RuntimeType::from('native'));
        $this->assertSame(RuntimeType::Swoole, RuntimeType::from('swoole'));
        $this->assertSame(RuntimeType::Workerman, RuntimeType::from('workerman'));
        $this->assertNull(RuntimeType::tryFrom('swow'));
    }

    public function testPriorityOrder(): void
    {
        $this->assertSame(100, RuntimeType::Native->priority());
        $this->assertSame(90, RuntimeType::Swoole->priority());
        $this->assertSame(80, RuntimeType::Workerman->priority());

        $this->assertGreaterThan(RuntimeType::Swoole->priority(), RuntimeType::Native->priority());
        $this->assertGreaterThan(RuntimeType::Workerman->priority(), RuntimeType::Swoole->priority());
    }

    public function testIsExternal(): void
    {
        $this->assertFalse(RuntimeType::Native->isExternal());
        $this->assertTrue(RuntimeType::Swoole->isExternal());
        $this->assertTrue(RuntimeType::Workerman->isExternal());
    }

    public function testLabelsAreUnique(): void
    {
        $labels = array_map(static fn (RuntimeType $t): string => $t->label(), RuntimeType::cases());

        $this->assertCount(count($labels), array_unique($labels));
        foreach ($labels as $label) {
            $this->assertNotSame('', $label);
        }
    }
}
