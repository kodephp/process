<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Kode;
use Kode\Process\Runtime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Driver\NativeRuntime;
use Kode\Process\Runtime\RuntimeType;
use PHPUnit\Framework\TestCase;

/**
 * NativeRuntime 单元测试 + 子进程冒烟测试。
 *
 * 冒烟部分会真正 fork 出 master-worker 并起一个 text 服务，依赖 ext-pcntl / ext-posix；
 * 不满足时整段 markTestSkipped，避免污染 CI。
 */
final class NativeRuntimeTest extends TestCase
{
    public function testIsAvailableReturnsBool(): void
    {
        $this->assertIsBool(NativeRuntime::isAvailable());
    }

    public function testTypeAndVersion(): void
    {
        $this->assertSame(RuntimeType::Native, NativeRuntime::type());
        $this->assertNotNull(NativeRuntime::version());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string)NativeRuntime::version());
    }

    public function testSupportedSchemes(): void
    {
        $ref     = new \ReflectionMethod(NativeRuntime::class, 'supportedSchemes');
        $schemes = $ref->invoke(new NativeRuntime());

        foreach (['tcp', 'http', 'websocket', 'text', 'frame', 'udp', 'unix', 'ssl'] as $expected) {
            $this->assertContains($expected, $schemes, "缺少协议 {$expected}");
        }
        // 未实现的传输不应出现
        $this->assertNotContains('http2', $schemes);
    }

    public function testCapabilitiesHonest(): void
    {
        $caps = array_map(static fn (Capability $c): string => $c->name, (new NativeRuntime())->capabilities());

        foreach (
            ['WebSocket', 'HotReload', 'ReusePort', 'Timer', 'TaskWorker', 'UdpServer', 'Ssl', 'UnixSocket']
            as $expected
        ) {
            $this->assertContains($expected, $caps, "缺少能力 {$expected}");
        }
        // 纯 PHP 实现，不谎报协程与原生异步 I/O 能力
        $this->assertNotContains('Coroutine', $caps);
        $this->assertNotContains('AsyncIo', $caps);
    }

    public function testRegisteredAsDriver(): void
    {
        $this->assertTrue(Runtime::isSupported('native'));
        $this->assertInstanceOf(NativeRuntime::class, Runtime::make('native'));
    }

    public function testIsDefaultRuntime(): void
    {
        // 自研 Native 为默认运行时，权重最高
        $this->assertGreaterThan(RuntimeType::Swoole->priority(), RuntimeType::Native->priority());
        $this->assertGreaterThan(RuntimeType::Workerman->priority(), RuntimeType::Native->priority());
        $this->assertSame(RuntimeType::Native, Runtime::preferred());

        // Native 为本包自研实现，非第三方外部依赖
        $this->assertFalse(RuntimeType::Native->isExternal());
        $this->assertTrue(RuntimeType::Swoole->isExternal());
        $this->assertTrue(RuntimeType::Workerman->isExternal());
    }

    public function testUnifiedApiSurface(): void
    {
        $rt = new NativeRuntime();

        // 三运行时共用的统一 API：切换底层无需改业务代码
        $this->assertSame(0, $rt->workerId());
        $this->assertSame([], $rt->connections());
        $this->assertSame(0, $rt->broadcast('noop'));
    }

    public function testTaskDegradesToSyncWhenNoTaskWorker(): void
    {
        $rt     = new NativeRuntime();
        $seen   = [];

        $rt->on('task', function (mixed $data) use (&$seen): string {
            $seen[] = $data;
            return 'done:' . $data;
        });
        $rt->on('finish', function (mixed $result) use (&$seen): void {
            $seen[] = $result;
        });

        // 未启动时无 task 进程，应就地同步执行并回调 finish
        $this->assertTrue($rt->task('job'));
        $this->assertSame(['job', 'done:job'], $seen);
    }

    public function testStatsExposesRuntimeShape(): void
    {
        $stats = (new NativeRuntime())->stats();

        foreach (['runtime', 'workers', 'task_workers', 'worker_id', 'connections'] as $key) {
            $this->assertArrayHasKey($key, $stats, "stats 缺少键 {$key}");
        }
        $this->assertSame('native', $stats['runtime']);
    }

    /**
     * 子进程冒烟：真实起 master-worker，text 协议回显。
     */
    public function testNativeTextServerRoundTrip(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');

        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "连接失败: {$errstr} ({$errno})");

            fwrite($fp, "ping\n");
            $resp = fgets($fp, 1024);
            $this->assertStringContainsString('pong:ping', (string)$resp);

            // 再发一条验证 worker 持续可用
            fwrite($fp, "hello\n");
            $resp2 = fgets($fp, 1024);
            $this->assertStringContainsString('pong:hello', (string)$resp2);

            fclose($fp);
        } finally {
            $status = proc_get_status($proc);
            $pid = $status['pid'] ?? null;
            if ($pid !== null && $pid > 0) {
                // 先礼后兵：SIGTERM 触发 master 优雅关闭 worker
                @posix_kill($pid, SIGTERM);
                usleep(300_000);
                $still = proc_get_status($proc);
                if ($still['running']) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            proc_close($proc);
            @unlink($script);
        }
    }

    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return 18080; // 兜底
        }
        $name = stream_socket_get_name($sock, false);
        $port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
        fclose($sock);
        return $port;
    }

    private function waitForPort(int $port, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                return;
            }
            usleep(50_000);
        }
    }

    private function writeServerScript(int $port): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('text://127.0.0.1:{$port}', ['workers' => 2], 'native')
    ->on('message', function (\$conn, \$data): void {
        \$text = is_string(\$data) ? \$data : json_encode(\$data);
        \$conn->send('pong:' . \$text);
    })
    ->start();
PHP;
        $file = tempnam(sys_get_temp_dir(), 'kode_native_');
        file_put_contents($file, $code);
        return $file;
    }
}
