<?php

declare(strict_types=1);

namespace Kode\Process\Tests\GlobalData;

use Kode\Process\GlobalData\SafeUnserialize;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SafeUnserialize 受控反序列化护栏（共享表 / 集群存储对象注入防护的核心原语）。
 *
 * 这些用例全部可运行（不依赖 ext-swoole / ext-apcu），负责锁住「默认禁止还原类」这一不变式。
 */
#[Group('globaldata')]
final class SafeUnserializeTest extends TestCase
{
    public function testScalarRoundTrips(): void
    {
        self::assertSame(42, SafeUnserialize::value('i:42;'));
        self::assertSame('hello', SafeUnserialize::value('s:5:"hello";'));
        self::assertSame([1, 2, 3], SafeUnserialize::value('a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}'));
        self::assertSame(3.14, SafeUnserialize::value('d:3.14;'));
    }

    public function testFalseIsDistinguishableFromParseFailure(): void
    {
        // 'b:0;' 是合法的 false，绝不能和解析失败混为一谈
        self::assertFalse(SafeUnserialize::value('b:0;'));
        // 损坏的载荷返回 null，而不是把 false 当成业务值
        self::assertNull(SafeUnserialize::value('i:not_an_int;'));
    }

    public function testEmptyStringIsNull(): void
    {
        self::assertNull(SafeUnserialize::value(''));
    }

    public function testNullPayload(): void
    {
        self::assertNull(SafeUnserialize::value('N;'));
    }

    public function testObjectInjectionBlockedByDefault(): void
    {
        $payload = 'O:8:"stdClass":0:{}';
        $value = SafeUnserialize::value($payload);

        // 默认 allowed_classes=false：对象绝不能原样还原
        self::assertIsObject($value);
        self::assertNotInstanceOf(\stdClass::class, $value);
        self::assertSame('__PHP_Incomplete_Class', get_class($value));
    }

    public function testGadgetClassIsNeverInstantiated(): void
    {
        // 一个带 __wakeup 的「危险」类，验证其构造/唤醒逻辑不会被触发
        $payload = 'O:48:"Kode\Process\Tests\GlobalData\EvilGadget":0:{}';
        $value = SafeUnserialize::value($payload);

        self::assertNotInstanceOf(EvilGadget::class, $value);
        self::assertFalse(EvilGadget::$woke, '危险类的 __wakeup 绝不应被调用');
    }

    public function testOptInWhitelistRestoresObject(): void
    {
        try {
            $payload = 'O:8:"stdClass":0:{}';
            $value = SafeUnserialize::value($payload, [\stdClass::class]);
            self::assertInstanceOf(\stdClass::class, $value);
        } finally {
            // 不影响后续用例的默认安全态
            SafeUnserialize::value('i:1;', false);
        }
    }
}

final class EvilGadget
{
    public static bool $woke = false;

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}
