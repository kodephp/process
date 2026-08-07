<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/kode 命令行入口的冒烟测试（仅覆盖无副作用的命令：help / check）。
 *
 * restart / start / stop 涉及 detached 进程与临时 PID 文件，留给手动与集成验证。
 */
final class BinKodeTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = __DIR__ . '/../bin/kode';
    }

    private function runKode(string $args): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . $args . ' 2>&1';

        $out = shell_exec($cmd);

        return is_string($out) ? $out : '';
    }

    public function testHelpListsRestartAndCheck(): void
    {
        $out = $this->runKode('help');

        $this->assertStringContainsString('restart', $out);
        $this->assertStringContainsString('check', $out);
    }

    public function testCheckReportsRuntimes(): void
    {
        $out = $this->runKode('check');

        $this->assertStringContainsString('运行时依赖自检', $out);
        $this->assertStringContainsString('native', $out);
        $this->assertStringContainsString('swoole', $out);
        $this->assertStringContainsString('workerman', $out);
    }

    public function testInfoPrintsVersion(): void
    {
        $out = $this->runKode('info');

        $this->assertStringContainsString('Kode Process', $out);
    }
}
