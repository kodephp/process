<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use InvalidArgumentException;
use Kode\Process\Runtime;
use Kode\Process\Runtime\Driver\NativeRuntime;
use Kode\Process\Runtime\Driver\SwooleRuntime;
use Kode\Process\Runtime\Driver\WorkermanRuntime;
use Kode\Process\Runtime\RuntimeInterface;
use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * 运行时门面：择优、显式创建、自定义驱动注册、环境自检。
 *
 * 门面的核心职责是「按可用性择优」，因此断言全部围绕当前环境实际安装情况展开，
 * 不假定 Swoole 一定存在——Workerman 是纯 PHP 依赖（已写入 require），始终可用。
 */
final class RuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::reset();
    }

    public function testWorkermanIsAlwaysSupported(): void
    {
        $this->assertTrue(Runtime::isSupported(RuntimeType::Workerman));
        $this->assertContains('workerman', Runtime::available());
    }

    public function testMakeWorkermanReturnsRuntimeInterface(): void
    {
        $rt = Runtime::make('workerman');

        $this->assertInstanceOf(RuntimeInterface::class, $rt);
        $this->assertInstanceOf(WorkermanRuntime::class, $rt);
        $this->assertSame(RuntimeType::Workerman, $rt::type());
        $this->assertFalse($rt->isRunning());
    }

    public function testMakeAcceptsEnumAndString(): void
    {
        $this->assertInstanceOf(WorkermanRuntime::class, Runtime::make(RuntimeType::Workerman));
        $this->assertInstanceOf(WorkermanRuntime::class, Runtime::make('WORKERMAN'));
    }

    public function testPreferredFollowsPriorityOrder(): void
    {
        // 自研 Native 权重最高且零扩展依赖，在 CLI 下恒为默认运行时
        $expected = NativeRuntime::isAvailable()
            ? RuntimeType::Native
            : (SwooleRuntime::isAvailable() ? RuntimeType::Swoole : RuntimeType::Workerman);

        $this->assertSame($expected, Runtime::preferred());
    }

    public function testAutoRespectsExplicitPreference(): void
    {
        // 显式偏好可覆盖默认择优，业务代码依旧面向 RuntimeInterface
        $rt = Runtime::auto(['workerman']);

        $this->assertInstanceOf(WorkermanRuntime::class, $rt);
        $this->assertSame(RuntimeType::Workerman, $rt::type());
    }

    public function testAvailableIsSortedByPriorityDesc(): void
    {
        $available = Runtime::available();

        $this->assertNotEmpty($available);
        $this->assertSame(Runtime::preferred()->value, $available[0]);

        $priorities = array_map(
            static fn (string $n): int => RuntimeType::from($n)->priority(),
            $available
        );
        $sorted = $priorities;
        rsort($sorted);

        $this->assertSame($sorted, $priorities);
    }

    public function testAutoReturnsPreferredRuntime(): void
    {
        $rt = Runtime::auto();

        $this->assertSame(Runtime::preferred(), $rt::type());
    }

    public function testAutoHonoursExplicitPreference(): void
    {
        $rt = Runtime::auto([RuntimeType::Workerman]);

        $this->assertSame(RuntimeType::Workerman, $rt::type());
    }

    public function testAutoSkipsUnavailablePreferenceAndFallsBack(): void
    {
        // 用一个必然不存在的驱动名开头，验证会跳过而不是抛异常
        $rt = Runtime::auto(['does-not-exist', RuntimeType::Workerman]);

        $this->assertSame(RuntimeType::Workerman, $rt::type());
    }

    public function testUnknownRuntimeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/未知运行时/');

        Runtime::make('roadrunner');
    }

    public function testIsSupportedReturnsFalseForUnknownName(): void
    {
        $this->assertFalse(Runtime::isSupported('roadrunner'));
    }

    public function testRegisterCustomDriver(): void
    {
        Runtime::register('custom', WorkermanRuntime::class);

        $this->assertTrue(Runtime::isSupported('custom'));
        $this->assertContains('custom', Runtime::available());
        $this->assertInstanceOf(WorkermanRuntime::class, Runtime::make('custom'));
    }

    public function testRegisterRejectsNonRuntimeClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/必须实现/');

        Runtime::register('bogus', \stdClass::class);
    }

    public function testResetDropsCustomDrivers(): void
    {
        Runtime::register('custom', WorkermanRuntime::class);
        Runtime::reset();

        $this->assertFalse(Runtime::isSupported('custom'));
    }

    public function testDiagnoseShape(): void
    {
        $report = Runtime::diagnose();

        $this->assertArrayHasKey('preferred', $report);
        $this->assertArrayHasKey('loop', $report);
        $this->assertArrayHasKey('runtimes', $report);
        $this->assertArrayHasKey('recommendation', $report);
        // 非 Linux 环境下不应有事件循环安装建议
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertNull($report['recommendation']);
        }

        foreach (['swoole', 'workerman'] as $name) {
            $this->assertArrayHasKey($name, $report['runtimes']);
            $this->assertIsBool($report['runtimes'][$name]['available']);
            $this->assertIsInt($report['runtimes'][$name]['priority']);
        }

        $this->assertSame(Runtime::preferred()->value, $report['preferred']);
    }

    public function testDiagnoseReportsVersionOnlyWhenAvailable(): void
    {
        $report = Runtime::diagnose()['runtimes'];

        foreach ($report as $entry) {
            if (!$entry['available']) {
                $this->assertNull($entry['version']);
            }
        }

        $this->assertNotNull($report['workerman']['version']);
    }
}
