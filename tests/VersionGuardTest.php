<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Version;
use PHPUnit\Framework\TestCase;

/**
 * 版本常量防漂移：`Version::VERSION` 会被运行时信息接口回读，包升版时漏改就在这里拦下。
 */
final class VersionGuardTest extends TestCase
{
    public function testVersionConstantMatchesComposerManifest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            $manifest['version'],
            Version::VERSION,
            'src/Version.php 的 VERSION 与 composer.json 的 version 不一致，发版时漏改了一处'
        );
        self::assertSame(Version::VERSION, Version::get(), 'get() 必须回读同一常量');
    }

    public function testNumericConstantsAgreeWithVersionString(): void
    {
        self::assertSame(
            sprintf('%d.%d.%d', Version::MAJOR, Version::MINOR, Version::PATCH),
            Version::VERSION,
            'MAJOR/MINOR/PATCH 与 VERSION 字符串不同步'
        );
        self::assertSame(
            Version::MAJOR * 10000 + Version::MINOR * 100 + Version::PATCH,
            Version::VERSION_ID,
            'VERSION_ID 必须等于 MAJOR*10000 + MINOR*100 + PATCH'
        );
        self::assertSame(Version::VERSION_ID, Version::getId(), 'getId() 必须回读同一常量');
    }
}
