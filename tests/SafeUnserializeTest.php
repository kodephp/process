<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\GlobalData\SafeUnserialize;
use PHPUnit\Framework\TestCase;

/**
 * SafeUnserialize 单测：不依赖任何扩展，直接验证受控反序列化与对象注入防护。
 */
final class SafeUnserializeTest extends TestCase
{
    public function testEmptyPayloadIsNull(): void
    {
        $this->assertNull(SafeUnserialize::value(''));
    }

    public function testValidFalseDistinguishedFromParseFailure(): void
    {
        // 'b:0;' 是合法的 false，必须与解析失败的 false 区分
        $this->assertFalse(SafeUnserialize::value('b:0;'));
    }

    public function testScalarAndArrayRoundTrip(): void
    {
        $this->assertSame(42, SafeUnserialize::value('i:42;'));
        $this->assertSame(3.14, SafeUnserialize::value('d:3.1400000000000001;'));
        $this->assertSame('hello', SafeUnserialize::value('s:5:"hello";'));
        $this->assertSame([1, 2, 3], SafeUnserialize::value('a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}'));
    }

    public function testCorruptedPayloadDowngradedToNull(): void
    {
        $this->assertNull(SafeUnserialize::value('not a serialized string'));
        $this->assertNull(SafeUnserialize::value('O:999:":0:{}'));
    }

    public function testObjectInjectionBlockedByDefault(): void
    {
        $payload = 'O:8:"stdClass":0:{}';
        $value   = SafeUnserialize::value($payload);

        // 默认禁止实例化任何类：要么降级为 null，要么还原成 __PHP_Incomplete_Class，
        // 绝不能是业务类的真实实例（否则 __wakeup/__destruct 可被触发）。
        $this->assertFalse($value instanceof \stdClass);
        $this->assertTrue($value === null || $value instanceof \__PHP_Incomplete_Class);
    }

    public function testExplicitClassWhitelistAllowed(): void
    {
        $payload = 'O:8:"stdClass":0:{}';
        $value   = SafeUnserialize::value($payload, [\stdClass::class]);

        $this->assertInstanceOf(\stdClass::class, $value);
    }

    public function testNonWhitelistedClassBlockedEvenWithArray(): void
    {
        $payload = 'O:8:"stdClass":0:{}';
        $value   = SafeUnserialize::value($payload, [\DateTime::class]);

        $this->assertFalse($value instanceof \stdClass);
        $this->assertTrue($value === null || $value instanceof \__PHP_Incomplete_Class);
    }
}
