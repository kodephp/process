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

    public function testRequirementsReportsKnownRuntimes(): void
    {
        $reqs = Runtime::requirements();

        foreach (['native', 'swoole', 'workerman'] as $name) {
            $this->assertArrayHasKey($name, $reqs, "requirements() 应含 {$name}");
            $entry = $reqs[$name];

            $this->assertIsBool($entry['available']);
            $this->assertIsArray($entry['extensions']);
            $this->assertIsArray($entry['missing_extensions']);
            $this->assertIsBool($entry['missing_package']);
            $this->assertIsString($entry['hint']);
            $this->assertNotEmpty($entry['hint'], "{$name} 应有安装提示");
        }

        // 当前环境三类运行时均可用的前提下，缺失列表应为空
        foreach ($reqs as $entry) {
            if ($entry['available']) {
                $this->assertSame([], $entry['missing_extensions']);
            }
        }
    }

    public function testRequirementsMarksWorkermanPackage(): void
    {
        $reqs = Runtime::requirements();

        $this->assertSame('workerman/workerman', $reqs['workerman']['package']);
        // 本机已装 workerman（composer require），故 missing_package 应为 false
        $this->assertFalse($reqs['workerman']['missing_package']);
    }

    public function testMissingExtensionsReturnsConfiguredList(): void
    {
        // 本机可用，故返回空数组；结构应为 list<string>
        $this->assertSame([], Runtime::missingExtensions('native'));
        $this->assertSame([], Runtime::missingExtensions('swoole'));
        $this->assertSame([], Runtime::missingExtensions('workerman'));
        // 未知驱动无映射，返回空数组（不抛异常）
        $this->assertSame([], Runtime::missingExtensions('road-runner'));
    }

    public function testMakeUnavailableIncludesMissingExtensionHint(): void
    {
        // 用最小桩驱动：实现 RuntimeInterface 但 isAvailable() 恒为 false，
        // 验证 make() 抛出的异常消息包含安装提示（缺失扩展检测的可观测出口）。
        $stub = new class () implements \Kode\Process\Runtime\RuntimeInterface {
            public static function isAvailable(): bool { return false; }
            public static function type(): \Kode\Process\Runtime\RuntimeType { return \Kode\Process\Runtime\RuntimeType::Workerman; }
            public static function version(): ?string { return null; }
            public function listen(string $address, array $options = []): static { return $this; }
            public function on(string $event, callable $handler): static { return $this; }
            public function start(): void {}
            public function stop(bool $graceful = true): void {}
            public function reload(): void {}
            public function addTimer(float $interval, callable $callback, bool $periodic = true): int { return 0; }
            public function delTimer(int $timerId): bool { return false; }
            public function supports(\Kode\Process\Runtime\Capability $cap): bool { return false; }
            public function capabilities(): array { return []; }
            public function stats(): array { return []; }
            public function isRunning(): bool { return false; }
            public function workerId(): int { return 0; }
            public function connections(): array { return []; }
            public function broadcast(string $data, bool $raw = false): int { return 0; }
            public function task(mixed $data): bool { return false; }
        };

        Runtime::register('stub-unavail', $stub::class);

        try {
            $thrown = null;
            try {
                Runtime::make('stub-unavail');
            } catch (\Kode\Process\Runtime\Exception\RuntimeNotSupportedException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, '不可用运行时应抛出 RuntimeNotSupportedException');
            $this->assertNotEmpty($thrown->getMessage());
        } finally {
            Runtime::reset();
        }
    }
}
