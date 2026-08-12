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

    public function testProcessHelpListsSubcommands(): void
    {
        $out = $this->runKode('process help');

        $this->assertStringContainsString('start', $out);
        $this->assertStringContainsString('stop', $out);
        $this->assertStringContainsString('restart', $out);
        $this->assertStringContainsString('status', $out);
    }

    public function testProcessStartMissingFileErrors(): void
    {
        $out = $this->runKode('process start /nonexistent-daemon-file.php');

        $this->assertStringContainsString('not found', $out);
    }

    /**
     * 端到端冒烟：用独立 PID 文件启动一个真实 daemon，验证 status，再 stop 并清理。
     *
     * 用 nohup + & 在后台拉起，轮询 PID 文件就绪后读 status，最后 stop 并确认 PID 文件被清理。
     */
    public function testProcessStartStopSmoke(): void
    {
        $pidFile = tempnam(sys_get_temp_dir(), 'kode-daemon-pid');
        @unlink($pidFile);

        $daemonFile = tempnam(sys_get_temp_dir(), 'kode-daemon-php');
        file_put_contents($daemonFile, <<<'PHP'
<?php
use Kode\Process\Kode;
return Kode::daemon()
    ->task(function (): void { usleep(10_000); })
    ->every(1)
    ->workers(1);
PHP);

        try {
            $this->launchBackground(escapeshellarg($daemonFile), $pidFile);

            // 等待 daemon 就绪（写 PID 文件）
            $ready = false;
            for ($i = 0; $i < 60; $i++) {
                if (file_exists($pidFile)) {
                    $ready = true;
                    break;
                }
                usleep(100_000);
            }
            $this->assertTrue($ready, 'daemon 应在数秒内写入 PID 文件');

            $status = $this->runKodeWithEnv($pidFile, 'process status');
            $this->assertStringContainsString('running', $status);

            $this->runKodeWithEnv($pidFile, 'process stop');

            // 等待 PID 文件被清理
            $gone = false;
            for ($i = 0; $i < 60; $i++) {
                if (!file_exists($pidFile)) {
                    $gone = true;
                    break;
                }
                usleep(100_000);
            }
            $this->assertTrue($gone, 'daemon 停止后应清理 PID 文件');
        } finally {
            @unlink($daemonFile);
            @unlink($pidFile);
        }
    }

    private function launchBackground(string $fileArg, string $pidFile): void
    {
        $cmd = sprintf(
            'nohup env KODE_DAEMON_PID_FILE=%s %s %s process start %s >/dev/null 2>&1 &',
            escapeshellarg($pidFile),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->bin),
            $fileArg
        );

        exec($cmd);
    }

    private function runKodeWithEnv(string $pidFile, string $args): string
    {
        $cmd = sprintf(
            'env KODE_DAEMON_PID_FILE=%s %s %s %s 2>&1',
            escapeshellarg($pidFile),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->bin),
            $args
        );

        $out = shell_exec($cmd);

        return is_string($out) ? $out : '';
    }
}
