<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\Driver\WorkermanRuntime;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * Workerman 兼容适配器。
 *
 * 本适配器的定位是「宿主已经跑在 Workerman 上时复用它的 I/O 栈」，
 * 因此断言集中在契约映射是否正确，而不是重复验证 Workerman 自身的行为。
 */
final class WorkermanRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WorkermanRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 workerman/workerman ^5.0');
        }
    }

    public function testMetadata(): void
    {
        $this->assertSame(RuntimeType::Workerman, WorkermanRuntime::type());
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', (string) WorkermanRuntime::version());
    }

    public function testSupportedSchemes(): void
    {
        $rt = new WorkermanRuntime();

        foreach (['tcp', 'udp', 'http', 'websocket', 'unix', 'text', 'frame'] as $i => $scheme) {
            $address = $scheme === 'unix'
                ? 'unix:///tmp/kode-wm-test.sock'
                : sprintf('%s://127.0.0.1:%d', $scheme, 19100 + $i);

            $rt->listen($address);
        }

        $this->assertSame(7, $rt->stats()['listeners']);
    }

    public function testUnsupportedSchemeThrows(): void
    {
        $this->expectException(RuntimeNotSupportedException::class);
        $this->expectExceptionMessageMatches('/不支持协议/');

        (new WorkermanRuntime())->listen('mqtt://127.0.0.1:1883');
    }

    public function testStatsReportsDetectedEventLoop(): void
    {
        $stats = (new WorkermanRuntime())->stats();

        $this->assertSame('workerman', $stats['runtime']);
        $this->assertSame(0, $stats['workers'], '未 start() 前不应有 worker 实例');
        $this->assertContains($stats['event_loop'], ['event', 'ev', 'select']);
    }

    public function testDelTimerOnUnknownIdReturnsFalse(): void
    {
        $this->assertFalse((new WorkermanRuntime())->delTimer(4242));
    }

    /**
     * 定时回调抛异常必须被隔离，绝不能穿透 Workerman 事件循环打死 worker。
     *
     * Workerman 的 Timer::add 只能在 Worker 事件循环内注册，故用子进程起一个真实
     * Workerman worker，在 workerStart 里注册抛异常的周期定时器。若异常未被隔离，
     * worker 进程会被致命错误打死、本测试断言进程存活即失败。
     */
    public function testThrowingTimerCallbackIsIsolated(): void
    {
        $port    = $this->freePort();
        $script  = $this->writeTimerServerScript($port);
        $logFile = sys_get_temp_dir() . '/kode-wm-timer-' . getmypid() . '-' . $port . '.log';
        $pid     = $this->spawnCapturing($script, $logFile);

        try {
            $this->assertTrue($this->waitForPort($port), "Workerman 服务未能在超时内监听 {$port}");

            // 让周期定时器触发若干次（含异常被隔离的回调）
            usleep(1_200_000);

            // 进程仍存活：异常隔离生效，worker 未被未捕获异常打死
            $this->assertTrue(@posix_kill($pid, 0), 'worker 进程在定时回调抛异常后已退出，说明异常未被隔离');

            // 隔离日志出现：证明定时器确实触发且异常被捕获而非致命
            $log = (string)@file_get_contents($logFile);
            $this->assertStringContainsString('WorkermanRuntime: timer#', $log);
        } finally {
            $this->terminate($pid);
            @unlink($script);
            @unlink($logFile);
        }
    }

    public function testEndToEndHttpRoundTrip(): void
    {
        $port   = $this->freePort();
        $script = $this->writeServerScript($port);
        $pid    = $this->spawn($script);

        try {
            $this->assertTrue($this->waitForPort($port), "Workerman 服务未能在超时内监听 {$port}");

            $response = $this->httpGet($port, '/wm');

            $this->assertStringContainsString('HTTP/1.1 200', $response);
            $this->assertStringContainsString('kode-workerman:/wm', $response);
        } finally {
            $this->terminate($pid);
            @unlink($script);
        }
    }

    private function writeServerScript(int $port): string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $file     = sys_get_temp_dir() . '/kode-wm-e2e-' . getmypid() . '-' . $port . '.php';

        // Workerman 从 argv 读子命令，这里显式伪造 argv 以 daemon 之外的方式启动
        $code = <<<PHP
        <?php
        \$argv = [__FILE__, 'start'];
        \$_SERVER['argv'] = \$argv;
        require '{$autoload}';

        use Kode\\Process\\Runtime;

        \$rt = Runtime::make('workerman');
        \$rt->listen('http://127.0.0.1:{$port}', ['workers' => 2, 'name' => 'kode-wm-e2e']);
        \$rt->on('message', static function (\$conn, \$req): void {
            \$path = method_exists(\$req, 'path') ? \$req->path() : '?';
            \$conn->send('kode-workerman:' . \$path);
        });
        \$rt->start();
        PHP;

        file_put_contents($file, $code);

        return $file;
    }

    private function spawn(string $script): int
    {
        $cmd = sprintf('%s %s >/dev/null 2>&1 & echo $!', escapeshellarg(PHP_BINARY), escapeshellarg($script));

        $pid = (int) shell_exec($cmd);
        $this->assertGreaterThan(0, $pid, '无法启动 Workerman 测试服务');

        return $pid;
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock);

        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function waitForPort(int $port, float $timeout = 8.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    private function httpGet(int $port, string $path): string
    {
        $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
        self::assertIsResource($conn, "连接失败：[{$errno}] {$errstr}");

        stream_set_timeout($conn, 2);
        fwrite($conn, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        $response = stream_get_contents($conn);
        fclose($conn);

        return (string) $response;
    }

    private function terminate(int $pid): void
    {
        @posix_kill($pid, SIGTERM);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && @posix_kill($pid, 0)) {
            usleep(50_000);
        }

        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, SIGKILL);
        }
    }

    /**
     * 写一个会注册「抛异常周期定时器」的 Workerman 服务脚本（在 workerStart 内注册）。
     */
    private function writeTimerServerScript(int $port): string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $file     = sys_get_temp_dir() . '/kode-wm-timer-' . getmypid() . '-' . $port . '.php';

        $code = <<<PHP
        <?php
        \$argv = [__FILE__, 'start'];
        \$_SERVER['argv'] = \$argv;
        require '{$autoload}';

        use Kode\\Process\\Runtime;

        \$rt = Runtime::make('workerman');
        \$rt->listen('http://127.0.0.1:{$port}', ['workers' => 1, 'name' => 'kode-wm-timer']);
        \$rt->on('workerStart', static function () use (\$rt): void {
            \$rt->addTimer(0.05, static function (): void {
                throw new \\RuntimeException('boom-from-timer');
            });
        });
        \$rt->on('message', static function (\$conn, \$req): void {
            \$conn->send('ok');
        });
        \$rt->start();
        PHP;

        file_put_contents($file, $code);

        return $file;
    }

    /**
     * 启动子进程并把 stderr 重定向到 \$logFile（用于断言隔离日志出现）。
     */
    private function spawnCapturing(string $script, string $logFile): int
    {
        $cmd = sprintf(
            '%s %s >/dev/null 2>%s & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($logFile)
        );

        $pid = (int)shell_exec($cmd);
        $this->assertGreaterThan(0, $pid, '无法启动 Workerman 定时器测试服务');

        return $pid;
    }

    /**
     * message handler 抛异常被 error 处理器兜底后，Workerman 必须主动关闭 TCP 连接，
     * 否则客户端会挂起等待（与 Native/Swoole 的回收一致）。
     */
    public function testMessageHandlerExceptionClosesTcpConnection(): void
    {
        $port   = $this->freePort();
        $script = $this->writeThrowServerScript($port);
        $pid    = $this->spawn($script);

        try {
            $this->assertTrue($this->waitForPort($port, 8.0), "Workerman 服务未能在超时内监听 {$port}");

            $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
            $this->assertIsResource($fp, "连接失败：[{$errno}] {$errstr}");
            stream_set_timeout($fp, 2);
            fwrite($fp, "ping\n");

            // handler 抛异常被兜底并主动关闭连接：客户端应快速收到 EOF（''），而非挂起
            $response = @fread($fp, 1024);
            $meta     = stream_get_meta_data($fp);
            fclose($fp);

            $this->assertSame('', (string)$response, 'handler 异常后连接应被主动关闭（EOF），不应有半响应残留');
            $this->assertFalse($meta['timed_out'], 'handler 异常后连接应被主动关闭，不应挂起等待超时');
        } finally {
            $this->terminate($pid);
            @unlink($script);
        }
    }

    private function writeThrowServerScript(int $port): string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $file     = sys_get_temp_dir() . '/kode-wm-throw-' . getmypid() . '-' . $port . '.php';

        $code = <<<PHP
        <?php
        \$argv = [__FILE__, 'start'];
        \$_SERVER['argv'] = \$argv;
        require '{$autoload}';
        use Kode\\Process\\Runtime;

        \$rt = Runtime::make('workerman');
        \$rt->listen('tcp://127.0.0.1:{$port}', ['workers' => 1]);
        \$rt->on('message', static function (\$conn, \$data): void {
            throw new \\RuntimeException('boom-from-message');
        });
        \$rt->on('error', static function (\$conn, \$e): void {
            \\error_log('wm-msg-err: ' . \$e->getMessage());
        });
        \$rt->start();
        PHP;

        file_put_contents($file, $code);

        return $file;
    }
}
