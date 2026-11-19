<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;

/**
 * maxBody 必须在三个运行时下都是同一套口径。
 *
 * Native 有完整回归（含真实 socket 413）；Swoole / Workerman 的超限响应行为由底层决定，
 * 这里至少钉住"上限值确实来自 maxBody"，防止切换运行时后上传又被各自默认值卡回去。
 */
final class MaxBodyRuntimeTest extends TestCase
{
    public function testSwoolePackageMaxLengthFollowsMaxBody(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Runtime/Driver/SwooleRuntime.php');

        self::assertStringContainsString(
            "'package_max_length' => max(1, (int)(\$opts['maxBody'] ?? HttpProtocol::MAX_LENGTH)),",
            $src
        );
    }

    public function testWorkermanMaxPackageSizeFollowsMaxBody(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Runtime/Driver/WorkermanRuntime.php');

        self::assertStringContainsString(
            '$worker->maxPackageSize = max(1, (int)($opts[\'maxBody\'] ?? HttpProtocol::MAX_LENGTH));',
            $src
        );
    }
}
