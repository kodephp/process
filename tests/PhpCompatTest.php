<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\PhpCompat;
use PHPUnit\Framework\TestCase;

final class PhpCompatTest extends TestCase
{
    public function testVersionDetection(): void
    {
        $this->assertNotEmpty(PhpCompat::version());
        $this->assertGreaterThanOrEqual(80300, PhpCompat::versionId());
    }

    public function testExactlyOneMinorVersionMatches(): void
    {
        $matches = array_filter([
            PhpCompat::isPhp83(),
            PhpCompat::isPhp84(),
            PhpCompat::isPhp85(),
            PhpCompat::isPhp86(),
        ]);

        $this->assertLessThanOrEqual(1, count($matches));
    }

    public function testForwardLookingFeatureFlags(): void
    {
        $this->assertSame(PHP_VERSION_ID >= 80400, PhpCompat::hasPropertyHooks());
        $this->assertSame(PHP_VERSION_ID >= 80400, PhpCompat::hasAsymmetricVisibility());
        $this->assertSame(PHP_VERSION_ID >= 80500, PhpCompat::hasCloneWith());
        $this->assertSame(PHP_VERSION_ID >= 80500, PhpCompat::hasPipeOperator());
        $this->assertSame(PHP_VERSION_ID >= 80500, PhpCompat::supportsPipe());
    }

    /**
     * 能力探测必须与实际可调用性一致：
     * 用版本号推断会在「函数被 disable_functions 禁用」或「版本号判断写错」时失配。
     */
    public function testCapabilityProbesMatchActualAvailability(): void
    {
        $this->assertSame(function_exists('array_find'), PhpCompat::hasArrayFind());
        $this->assertSame(function_exists('fpow'), PhpCompat::hasFpow());
        $this->assertSame(extension_loaded('uri'), PhpCompat::hasUriExtension());
    }

    public function testArrayFind(): void
    {
        $array = [1, 2, 3, 4, 5];

        $this->assertSame(4, PhpCompat::arrayFind($array, static fn ($v) => $v > 3));
        $this->assertNull(PhpCompat::arrayFind($array, static fn ($v) => $v > 10));
    }

    public function testArrayFindKey(): void
    {
        $array = ['a' => 1, 'b' => 2, 'c' => 3];

        $this->assertSame('b', PhpCompat::arrayFindKey($array, static fn ($v) => $v === 2));
        $this->assertNull(PhpCompat::arrayFindKey($array, static fn ($v) => $v === 99));
    }

    public function testArrayFindKeyReceivesKeyArgument(): void
    {
        $array = ['a' => 1, 'b' => 2];

        $this->assertSame('b', PhpCompat::arrayFindKey($array, static fn ($v, $k) => $k === 'b'));
    }

    public function testArrayAny(): void
    {
        $array = [1, 2, 3, 4, 5];

        $this->assertTrue(PhpCompat::arrayAny($array, static fn ($v) => $v > 3));
        $this->assertFalse(PhpCompat::arrayAny($array, static fn ($v) => $v > 10));
        $this->assertFalse(PhpCompat::arrayAny([], static fn ($v) => true));
    }

    public function testArrayAll(): void
    {
        $array = [1, 2, 3, 4, 5];

        $this->assertTrue(PhpCompat::arrayAll($array, static fn ($v) => $v > 0));
        $this->assertFalse(PhpCompat::arrayAll($array, static fn ($v) => $v > 3));
        $this->assertTrue(PhpCompat::arrayAll([], static fn ($v) => false));
    }

    public function testFpow(): void
    {
        $this->assertSame(8.0, PhpCompat::fpow(2.0, 3.0));
        $this->assertSame(1.0, PhpCompat::fpow(5.0, 0.0));
    }

    public function testPipe(): void
    {
        $result = PhpCompat::pipe(
            '  HELLO WORLD  ',
            'trim',
            'strtolower',
            static fn (string $s): string => str_replace(' ', '-', $s)
        );

        $this->assertSame('hello-world', $result);
    }

    public function testPipeWithoutCallbacksReturnsInput(): void
    {
        $this->assertSame('x', PhpCompat::pipe('x'));
    }

    public function testCreatePipeIsReusable(): void
    {
        $slugify = PhpCompat::createPipe(
            'trim',
            'strtolower',
            static fn (string $s): string => str_replace(' ', '-', $s)
        );

        $this->assertSame('a-b', $slugify(' A B '));
        $this->assertSame('c-d', $slugify(' C D '));
    }

    public function testRemovedLegacyProbesAreGone(): void
    {
        // PHP 8.3 基线下这些恒真探测已移除，防止回流
        foreach (['isPhp81', 'isPhp82', 'hasEnums', 'hasReadonlyProperties', 'hasNeverType'] as $method) {
            $this->assertFalse(
                method_exists(PhpCompat::class, $method),
                "已废弃的探测方法 {$method}() 不应重新出现"
            );
        }
    }
}
