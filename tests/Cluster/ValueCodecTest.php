<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Store\ValueCodec;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ValueCodec 受控反序列化（集群存储对象注入防护）。
 *
 * RedisStore / GlobalDataStore 是**网络可达**的：能连上后端的人可把任意字节写进共享键。
 * decodeValue 此前用裸 unserialize()（无 allowed_classes 限制），等同把对象注入面
 * 直接暴露给网络。本用例锁住「默认禁止还原类」这一修复后的不变式。
 */
#[Group('cluster')]
final class ValueCodecTest extends TestCase
{
    /**
     * 用一个小载体类把 trait 的 protected 方法暴露出来测试。
     */
    private function codec(): object
    {
        return new class() {
            use ValueCodec;

            public function enc(mixed $v): string
            {
                return $this->encodeValue($v);
            }

            public function dec(mixed $v): mixed
            {
                return $this->decodeValue($v);
            }
        };
    }

    public function testIntegerRoundTripsAsInt(): void
    {
        $c = $this->codec();
        self::assertSame(42, $c->dec($c->enc(42)));
        self::assertSame(-7, $c->dec($c->enc(-7)));
    }

    public function testNumericStringIsNotMistakenForInt(): void
    {
        $c = $this->codec();
        // '007' 这种不是合法十进制整数，应保持字符串
        self::assertSame('007', $c->dec($c->enc('007')));
    }

    public function testArrayRoundTrips(): void
    {
        $c = $this->codec();
        $v = ['a' => 1, 'nested' => [2, 3], 'flag' => false];
        self::assertSame($v, $c->dec($c->enc($v)));
    }

    public function testObjectInjectionBlockedByDefault(): void
    {
        $c = $this->codec();
        $serialized = $c->enc(new \stdClass());

        $decoded = $c->dec($serialized);
        self::assertIsObject($decoded);
        self::assertNotInstanceOf(\stdClass::class, $decoded);
        self::assertSame('__PHP_Incomplete_Class', get_class($decoded));
    }

    public function testPoisonedPayloadFromUntrustedStoreIsNotInstantiated(): void
    {
        $c = $this->codec();
        // 模拟后端被投毒写入的恶意对象载荷（带 MAGIC 前缀）
        $malicious = "\0K" . 'O:8:"stdClass":0:{}';
        $out = $c->dec($malicious);

        self::assertNotInstanceOf(\stdClass::class, $out);
    }

    public function testOptInAllowsWhitelistedClass(): void
    {
        $c = $this->codec();
        try {
            $c->setAllowedClasses([\stdClass::class]);
            $decoded = $c->dec($c->enc(new \stdClass()));
            self::assertInstanceOf(\stdClass::class, $decoded);
        } finally {
            $c->setAllowedClasses(false);
        }
    }

    public function testCorruptedPayloadReturnsNullNotFalse(): void
    {
        $c = $this->codec();
        $corrupted = "\0K" . 'i:not_an_int;';
        self::assertNull($c->dec($corrupted));
    }
}
