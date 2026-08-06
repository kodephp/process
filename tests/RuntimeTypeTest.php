<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * 运行时类型枚举：取值、择优权重、外部依赖标记。
 *
 * 权重顺序体现本包的架构立场（见 docs/gate-report.md）：优先复用宿主已有的成熟
 * 运行时，因此 Swoole 权重高于 Workerman。本包不自带服务器实现。
 */
final class RuntimeTypeTest extends TestCase
{
    public function testCases(): void
    {
        $values = array_map(static fn (RuntimeType $t): string => $t->value, RuntimeType::cases());

        $this->assertSame(['swoole', 'workerman'], $values);
    }

    public function testFromString(): void
    {
        $this->assertSame(RuntimeType::Swoole, RuntimeType::from('swoole'));
        $this->assertSame(RuntimeType::Workerman, RuntimeType::from('workerman'));
        $this->assertNull(RuntimeType::tryFrom('native'));
        $this->assertNull(RuntimeType::tryFrom('swow'));
    }

    public function testPriorityOrder(): void
    {
        $this->assertSame(100, RuntimeType::Swoole->priority());
        $this->assertSame(90, RuntimeType::Workerman->priority());

        $this->assertGreaterThan(RuntimeType::Workerman->priority(), RuntimeType::Swoole->priority());
    }

    public function testIsExternal(): void
    {
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
