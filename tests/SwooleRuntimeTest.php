<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Driver\SwooleRuntime;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * Swoole 兼容适配器的契约映射测试。
 *
 * 不在此重复验证 Swoole 自身的 I/O 行为——只确认地址协议、能力集、
 * 定时器与状态快照按本包契约正确暴露。
 */
final class SwooleRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!SwooleRuntime::isAvailable()) {
            $this->markTestSkipped('未安装 ext-swoole');
        }
    }

    public function testMetadata(): void
    {
        $this->assertSame(RuntimeType::Swoole, SwooleRuntime::type());
        $this->assertSame(swoole_version(), SwooleRuntime::version());
    }

    public function testCapabilitiesIncludeSwooleExclusives(): void
    {
        $rt = new SwooleRuntime();

        $this->assertTrue($rt->supports(Capability::Coroutine));
        $this->assertTrue($rt->supports(Capability::TaskWorker));
        $this->assertTrue($rt->supports(Capability::AsyncIo));
        $this->assertTrue($rt->supports(Capability::UdpServer));
    }

    public function testSupportedSchemes(): void
    {
        $rt = new SwooleRuntime();

        foreach (['tcp', 'udp', 'http', 'websocket'] as $i => $scheme) {
            $rt->listen(sprintf('%s://127.0.0.1:%d', $scheme, 19200 + $i));
        }

        $this->assertSame(4, $rt->stats()['listeners']);
    }

    public function testUnsupportedSchemeThrows(): void
    {
        $this->expectException(RuntimeNotSupportedException::class);
        $this->expectExceptionMessageMatches('/不支持协议/');

        (new SwooleRuntime())->listen('frame://127.0.0.1:19299');
    }

    public function testStatsBeforeStart(): void
    {
        $stats = (new SwooleRuntime())->stats();

        $this->assertSame('swoole', $stats['runtime']);
        $this->assertFalse($stats['running']);
        $this->assertSame(0, $stats['listeners']);
    }

    public function testDelTimerOnUnknownIdReturnsFalse(): void
    {
        $this->assertFalse((new SwooleRuntime())->delTimer(4242));
    }

    /**
     * 定时回调抛异常必须被隔离，绝不能穿透 Swoole 事件循环打死 worker。
     *
     * 用原生 Swoole 定时器驱动 reactor 退出，让上面的周期定时器真正触发若干次；
     * 若异常未被隔离，进程会在此处之前崩溃、测试直接失败。
     */
    public function testThrowingTimerCallbackIsIsolated(): void
    {
        $rt = new SwooleRuntime();
        $fired = new \stdClass();
        $fired->count = 0;

        $id = $rt->addTimer(0.02, static function () use ($fired): void {
            $fired->count++;
            throw new \RuntimeException('boom-from-timer');
        });
        $this->assertIsInt($id);

        \Swoole\Timer::after(300, static fn() => \Swoole\Event::exit());
        \Swoole\Event::wait();

        // 进程没崩（异常被隔离）且定时器确实多次触发
        self::assertGreaterThan(0, $fired->count);
    }

    /**
     * 一次性定时器触发后底层已自动移除，本端映射也应清理：delTimer 应返回 false。
     */
    public function testOneShotTimerCleansUpMapAfterFiring(): void
    {
        $rt = new SwooleRuntime();

        // 触发前可正常删除（尚未移除）
        $id = $rt->addTimer(0.02, static fn() => null, false);
        $this->assertIsInt($id);
        $this->assertTrue($rt->delTimer($id));

        // 再注册一个，让它真正触发一次
        $id2 = $rt->addTimer(0.02, static fn() => null, false);
        \Swoole\Timer::after(300, static fn() => \Swoole\Event::exit());
        \Swoole\Event::wait();

        // 触发后映射已清理：delTimer 返回 false（避免陈旧的 timer id 残留）
        self::assertFalse($rt->delTimer($id2));
    }

    /**
     * message handler 抛异常被 error 处理器兜底后，Swoole 必须主动关闭 TCP 连接，
     * 否则客户端会挂起等待直到超时（与 Native 的 closeConnection 回收一致）。
     */
    public function testMessageHandlerExceptionClosesTcpConnection(): void
    {
        $port   = $this->freePort();
        $script = $this->writeThrowServerScript($port);
        $pid    = $this->spawn($script);

        try {
            $this->assertTrue($this->waitForPort($port, 8.0), "Swoole 服务未能在超时内监听 {$port}");

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

    /**
     * Swoole HTTP 场景：message handler 抛异常被兜底后，运行时通过 close() 干净结束
     * HTTP 响应（空 200），客户端不会挂起；若未回收则连接无响应、客户端读超时。
     */
    public function testHttpHandlerExceptionEndsResponse(): void
    {
        $port   = $this->freePort();
        $script = $this->writeHttpThrowServerScript($port);
        $pid    = $this->spawn($script);

        try {
            $this->assertTrue($this->waitForPort($port, 8.0), "Swoole HTTP 服务未能在超时内监听 {$port}");

            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
            $this->assertIsResource($conn, "连接失败：[{$errno}] {$errstr}");
            stream_set_timeout($conn, 2);
            fwrite($conn, "GET /boom HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

            $response = @stream_get_contents($conn);
            $meta     = stream_get_meta_data($conn);
            fclose($conn);

            $this->assertStringContainsString('HTTP/1.1 200', (string)$response, 'handler 异常后应干净结束 HTTP 响应（空 200），不应挂起');
            $this->assertFalse($meta['timed_out'], 'handler 异常后应返回响应，不应读超时');
        } finally {
            $this->terminate($pid);
            @unlink($script);
        }
    }

    private function writeThrowServerScript(int $port): string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $file     = sys_get_temp_dir() . '/kode-sw-throw-' . getmypid() . '-' . $port . '.php';

        $code = <<<PHP
        <?php
        require '{$autoload}';
        use Kode\\Process\\Runtime;

        \$rt = Runtime::make('swoole');
        \$rt->listen('tcp://127.0.0.1:{$port}', ['workers' => 1]);
        \$rt->on('message', static function (\$conn, \$data): void {
            throw new \\RuntimeException('boom-from-message');
        });
        \$rt->on('error', static function (\$conn, \$e): void {
            \\error_log('sw-msg-err: ' . \$e->getMessage());
        });
        \$rt->start();
        PHP;

        file_put_contents($file, $code);

        return $file;
    }

    private function writeHttpThrowServerScript(int $port): string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $file     = sys_get_temp_dir() . '/kode-sw-http-throw-' . getmypid() . '-' . $port . '.php';

        $code = <<<PHP
        <?php
        require '{$autoload}';
        use Kode\\Process\\Runtime;

        \$rt = Runtime::make('swoole');
        \$rt->listen('http://127.0.0.1:{$port}', ['workers' => 1]);
        \$rt->on('message', static function (\$conn, \$req): void {
            throw new \\RuntimeException('boom-from-message');
        });
        \$rt->on('error', static function (\$conn, \$e): void {
            \\error_log('sw-http-msg-err: ' . \$e->getMessage());
        });
        \$rt->start();
        PHP;

        file_put_contents($file, $code);

        return $file;
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

    private function spawn(string $script): int
    {
        $cmd = sprintf('%s %s >/dev/null 2>&1 & echo $!', escapeshellarg(PHP_BINARY), escapeshellarg($script));

        $pid = (int) shell_exec($cmd);
        $this->assertGreaterThan(0, $pid, '无法启动 Swoole 测试服务');

        return $pid;
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
}
