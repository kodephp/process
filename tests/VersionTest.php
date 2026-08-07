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
        $this->assertSame(50103, Version::getId());
    }

    public function testVersionComponents(): void
    {
        $this->assertSame(5, Version::getMajor());
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

        $this->assertSame('5.1.3', $info['version']);
        $this->assertSame(50103, $info['version_id']);
        $this->assertSame('8.3.0', $info['minimum_php']);
        $this->assertTrue($info['php_supported']);
        $this->assertIsArray($info['features']);
        $this->assertIsArray($info['optional_extensions']);

        // ZTS / 并行探测字段自 3.2.0 起存在
        $this->assertArrayHasKey('zts', $info);
        $this->assertArrayHasKey('parallel', $info);
        $this->assertArrayHasKey('parallel_backend', $info);
        $this->assertArrayHasKey('pthreads', $info);
        $this->assertIsBool($info['zts']);
        $this->assertIsBool($info['parallel']);
        $this->assertContains($info['parallel_backend'], ['ext-parallel', 'kode-parallel', 'none']);
        $this->assertIsBool($info['pthreads']);
    }

    public function testZtsAndParallelDetection(): void
    {
        // 当前 CI / 本地为 NTS 构建，仅断言方法可用且返回一致
        $this->assertSame(Version::isZts(), Version::IS_ZTS === 1);
        $this->assertSame(Version::supportsParallel(), Version::isZts() && (extension_loaded('parallel') || class_exists('parallel\\Runtime')));
        $this->assertContains(Version::parallelBackend(), ['ext-parallel', 'kode-parallel', 'none']);
        $this->assertSame(Version::supportsPthreads(), extension_loaded('pthreads'));
    }

    public function testVersionComparison(): void
    {
        $this->assertTrue(Version::isEqualTo('5.1.3'));
        $this->assertTrue(Version::isGreaterThan('2.9.0'));
        $this->assertTrue(Version::isLessThan('5.2.0'));
        $this->assertFalse(Version::isGreaterThan('5.1.3'));
    }

    public function testToString(): void
    {
        $this->assertSame('5.1.3', (string) new Version());
    }
}
