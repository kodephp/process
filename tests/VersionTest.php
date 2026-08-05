<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testVersionFormat(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::get());
    }

    public function testVersionId(): void
    {
        $this->assertSame(30100, Version::getId());
    }

    public function testVersionComponents(): void
    {
        $this->assertSame(3, Version::getMajor());
        $this->assertSame(1, Version::getMinor());
        $this->assertGreaterThanOrEqual(0, Version::getPatch());
    }

    public function testVersionIdMatchesComponents(): void
    {
        $expected = Version::getMajor() * 10000 + Version::getMinor() * 100 + Version::getPatch();

        $this->assertSame($expected, Version::getId());
    }

    public function testVersionStringMatchesComponents(): void
    {
        $this->assertSame(
            Version::getMajor() . '.' . Version::getMinor() . '.' . Version::getPatch(),
            Version::get()
        );
    }

    public function testPhpVersion(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\./', Version::getPhpVersion());
        $this->assertGreaterThanOrEqual(80300, Version::getPhpVersionId());
    }

    public function testMinimumPhpBaseline(): void
    {
        $this->assertSame('8.3.0', Version::MINIMUM_PHP_VERSION);
        $this->assertSame(80300, Version::MINIMUM_PHP_VERSION_ID);
        $this->assertTrue(Version::isPhpSupported());
    }

    public function testFeatureDetectionOnlyReportsForwardLookingFeatures(): void
    {
        $features = Version::getFeatures();

        $this->assertIsArray($features);
        $this->assertArrayHasKey('property_hooks', $features);
        $this->assertArrayHasKey('clone_with', $features);
        $this->assertArrayHasKey('pipe_operator', $features);

        // 8.3 已无条件支持的特性不应再出现在探测结果中
        $this->assertArrayNotHasKey('fiber', $features);
        $this->assertArrayNotHasKey('enums', $features);
        $this->assertArrayNotHasKey('readonly', $features);
    }

    public function testFeatureFlagsMatchRuntime(): void
    {
        $this->assertSame(PHP_VERSION_ID >= 80400, Version::supportsPropertyHooks());
        $this->assertSame(PHP_VERSION_ID >= 80500, Version::supportsCloneWith());
        $this->assertSame(PHP_VERSION_ID >= 80500, Version::supportsPipeOperator());
    }

    public function testExactlyOneMinorVersionMatches(): void
    {
        $matches = array_filter([
            Version::isPhp83(),
            Version::isPhp84(),
            Version::isPhp85(),
        ]);

        $this->assertLessThanOrEqual(1, count($matches));
    }

    public function testRequiredExtensionsAreLoaded(): void
    {
        $this->assertSame([], Version::getMissingExtensions());
    }

    public function testCheckEnvironmentPasses(): void
    {
        $this->assertSame([], Version::checkEnvironment());
    }

    public function testRequireSupportedEnvironmentDoesNotThrow(): void
    {
        Version::requireSupportedEnvironment();

        $this->assertTrue(true);
    }

    public function testGetInfoStructure(): void
    {
        $info = Version::getInfo();

        $this->assertSame('3.1.0', $info['version']);
        $this->assertSame(30100, $info['version_id']);
        $this->assertSame('8.3.0', $info['minimum_php']);
        $this->assertTrue($info['php_supported']);
        $this->assertIsArray($info['features']);
        $this->assertIsArray($info['optional_extensions']);
    }

    public function testVersionComparison(): void
    {
        $this->assertTrue(Version::isEqualTo('3.1.0'));
        $this->assertTrue(Version::isGreaterThan('2.9.0'));
        $this->assertTrue(Version::isLessThan('3.2.0'));
        $this->assertFalse(Version::isGreaterThan('4.0.0'));
    }

    public function testToString(): void
    {
        $this->assertSame('3.1.0', (string) new Version());
    }
}
