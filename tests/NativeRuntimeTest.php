<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Kode;
use Kode\Process\Protocol\ProtocolFactory;
use Kode\Process\Runtime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Driver\NativeRuntime;
use Kode\Process\Runtime\RuntimeType;
use Kode\Process\Tests\Fixtures\EchoProtocol;
use PHPUnit\Framework\TestCase;

/**
 * NativeRuntime 单元测试 + 子进程冒烟测试。
 *
 * 冒烟部分会真正 fork 出 master-worker 并起一个 text 服务，依赖 ext-pcntl / ext-posix；
 * 不满足时整段 markTestSkipped，避免污染 CI。
 */
final class NativeRuntimeTest extends TestCase
{
    private string $lineBuf = '';

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

    public function testDefaultReusePortFollowsPlatform(): void
    {
        // 经同机 A/B 实测：Linux 默认开启 SO_REUSEPORT 消除惊群；
        // macOS/BSD 默认关闭（其 kqueue + 共享 socket 更高效）。
        $expected = PHP_OS_FAMILY === 'Linux' && defined('SO_REUSEPORT');
        $this->assertSame($expected, NativeRuntime::defaultReusePort());
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

    /**
     * WebSocket 自动 ping/pong：真实起 Native WS 服务，验证
     *  - 对端 ping → 服务端自动回 pong（opcode 0xA、未掩码、载荷一致）
     *  - ping 不泄漏到用户 on('message')
     *  - 文本消息正常回显
     *  - 对端 pong → 服务端静默忽略，连接保持存活
     */
    public function testNativeWebSocketAutoPingPong(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeWsServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native WS 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = $this->wsConnect($port);
            $this->wsHandshake($fp);

            // 1) 发掩码 ping，期望服务端自动回 pong，且不泄漏到用户回调
            fwrite($fp, $this->wsMaskedFrame(0x9, 'hb'));
            $gotPong = false;
            for ($i = 0; $i < 10; $i++) {
                $frame = $this->wsReadFrame($fp);
                if ($frame === null) {
                    break;
                }
                if ($frame['opcode'] === 0xA) {
                    $this->assertSame('hb', $frame['payload'], 'pong 载荷应与 ping 一致');
                    $gotPong = true;
                    break;
                }
                if ($frame['opcode'] === 0x1) {
                    $this->assertStringNotContainsString(
                        '"type":"ping"',
                        $frame['payload'],
                        'ping 不应泄漏到用户 on(message)'
                    );
                }
            }
            $this->assertTrue($gotPong, '服务端未对 ping 自动回 pong');

            // 2) 文本消息正常回显（用户回调只收应用消息）
            fwrite($fp, $this->wsMaskedFrame(0x1, 'hello'));
            $gotEcho = false;
            for ($i = 0; $i < 10; $i++) {
                $frame = $this->wsReadFrame($fp);
                if ($frame === null) {
                    break;
                }
                if ($frame['opcode'] === 0x1 && str_contains($frame['payload'], 'hello')) {
                    $gotEcho = true;
                    break;
                }
            }
            $this->assertTrue($gotEcho, '文本消息未正确回显');

            // 3) 发 pong 帧，服务端静默忽略；再发文本确认连接存活
            fwrite($fp, $this->wsMaskedFrame(0xA, 'x'));
            fwrite($fp, $this->wsMaskedFrame(0x1, 'still-alive'));
            $alive = false;
            for ($i = 0; $i < 10; $i++) {
                $frame = $this->wsReadFrame($fp);
                if ($frame === null) {
                    break;
                }
                if ($frame['opcode'] === 0x1 && str_contains($frame['payload'], 'still-alive')) {
                    $alive = true;
                    break;
                }
            }
            $this->assertTrue($alive, '服务端未静默忽略 pong，连接异常');

            fclose($fp);
        } finally {
            $status = proc_get_status($proc);
            $pid = $status['pid'] ?? null;
            if ($pid !== null && $pid > 0) {
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

    /**
     * WebSocket 分片重组：真实起 Native WS 服务，把一条文本消息拆成 3 帧
     * （FIN=0 text / FIN=0 continuation / FIN=1 continuation），验证服务端只
     * 向用户回调派发一次完整消息，而非按帧碎片化派发（修复前的缺陷）。
     */
    public function testNativeWebSocketFragmentReassembly(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeWsServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native WS 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = $this->wsConnect($port);
            $this->wsHandshake($fp);

            // 首帧 FIN=0 opcode=1(text) "Hello "
            $frags  = $this->wsMaskedFrame(0x1, 'Hello ', false);
            // 续帧 FIN=0 opcode=0(continuation) "wor"
            $frags .= $this->wsMaskedFrame(0x0, 'wor', false);
            // 末帧 FIN=1 opcode=0(continuation) "ld"
            $frags .= $this->wsMaskedFrame(0x0, 'ld', true);
            fwrite($fp, $frags);

            // 必须只收到一条回显，且内容为完整重组后的 "Hello world"
            $gotFull = false;
            for ($i = 0; $i < 10; $i++) {
                $frame = $this->wsReadFrame($fp);
                if ($frame === null) {
                    break;
                }
                if ($frame['opcode'] === 0x1 && str_contains($frame['payload'], 'Hello world')) {
                    $gotFull = true;
                    break;
                }
            }
            $this->assertTrue($gotFull, '分片消息未被重组为完整消息派发');

            fclose($fp);
        } finally {
            $status = proc_get_status($proc);
            $pid = $status['pid'] ?? null;
            if ($pid !== null && $pid > 0) {
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

    private function wsConnect(int $port): mixed
    {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
        $this->assertNotFalse($fp, "WS 连接失败: {$errstr} ({$errno})");
        return $fp;
    }

    private function wsHandshake($fp): void
    {
        $key = base64_encode(random_bytes(16));
        $req = "GET / HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($fp, $req);

        $buf = '';
        while (!str_contains($buf, "\r\n\r\n") && ($chunk = fread($fp, 1024)) !== '') {
            $buf .= $chunk;
        }
        $this->assertStringContainsString('101', $buf, 'WS 握手未返回 101');
    }

    private function wsMaskedFrame(int $opcode, string $payload, bool $fin = true): string
    {
        $finBit = $fin ? 0x80 : 0x00;
        $mask   = random_bytes(4);
        $len    = strlen($payload);
        $header = chr($finBit | $opcode) . chr(0x80 | $len);
        $masked = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
        return $header . $mask . $masked;
    }

    private function wsReadFrame($fp): ?array
    {
        $b0 = $this->readExact($fp, 1);
        if ($b0 === '') {
            return null;
        }
        $b1  = $this->readExact($fp, 1);
        $len = ord($b1) & 0x7F;
        if ($len === 126) {
            $len = unpack('n', $this->readExact($fp, 2))[1];
        } elseif ($len === 127) {
            $len = (int)unpack('J', $this->readExact($fp, 8))[1];
        }
        $payload = $len > 0 ? $this->readExact($fp, $len) : '';
        return ['opcode' => ord($b0) & 0x0F, 'payload' => $payload];
    }

    private function readExact($fp, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($fp, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function writeWsServerScript(int $port): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('websocket://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('message', function (\$conn, \$msg): void {
        \$conn->send('recv:' . json_encode(\$msg, JSON_UNESCAPED_UNICODE));
    })
    ->start();
PHP;
        $file = tempnam(sys_get_temp_dir(), 'kode_ws_');
        file_put_contents($file, $code);
        return $file;
    }

    /**
     * 自定义协议一等公民：注册后 Kode::serve('echo://..') 应能直接用它收发。
     * 验证 ProtocolFactory::register 真正打通到 serve 路径（此前仅登记不生效）。
     */
    public function testNativeCustomProtocolServe(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        // 注册自定义协议（父进程注册，fork 后子进程可见）
        ProtocolFactory::register('echo', EchoProtocol::class);

        // 1) 静态断言：自定义协议已进入 supportedSchemes 且 protocolClassFor 能解析
        $schemes = $this->invokeProtected(NativeRuntime::class, 'supportedSchemes', []);
        $this->assertContains('echo', $schemes, '自定义协议未进入 supportedSchemes');
        $cls = $this->invokePrivate(NativeRuntime::class, 'protocolClassFor', ['echo']);
        $this->assertSame(EchoProtocol::class, $cls, 'protocolClassFor 未返回自定义协议类');

        // 2) 端到端：真实起 Native 服务，客户端发 hello 收到 HELLO
        $port   = $this->findFreePort();
        $script = $this->writeCustomProtocolServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动自定义协议服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "自定义协议连接失败: {$errstr} ({$errno})");

            fwrite($fp, "hello\n");
            $resp = $this->readLine($fp);
            $this->assertSame('HELLO', $resp, '自定义协议回显内容不符');

            // 多发一帧验证连接保持（粘包/半包由 input() 分包）
            fwrite($fp, "wor\nld\n");
            $this->assertSame('WOR', $this->readLine($fp), '第二帧前半段回显不符');
            $this->assertSame('LD', $this->readLine($fp), '第二帧后半段回显不符');

            fclose($fp);
        } finally {
            $status = proc_get_status($proc);
            $pid = $status['pid'] ?? null;
            if ($pid !== null && $pid > 0) {
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

    private function readLine($fp): string
    {
        while (!str_contains($this->lineBuf, "\n")) {
            $chunk = fread($fp, 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $this->lineBuf .= $chunk;
        }
        $pos = strpos($this->lineBuf, "\n");
        if ($pos === false) {
            $line = $this->lineBuf;
            $this->lineBuf = '';
            return rtrim($line, "\n");
        }
        $line = substr($this->lineBuf, 0, $pos);
        $this->lineBuf = substr($this->lineBuf, $pos + 1);
        return $line;
    }

    private function writeCustomProtocolServerScript(int $port): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;
use Kode\\Process\\Protocol\\ProtocolFactory;
use Kode\\Process\\Tests\\Fixtures\\EchoProtocol;

ProtocolFactory::register('echo', EchoProtocol::class);

Kode::serve('echo://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('message', function (\$conn, \$msg): void {
        \$conn->send(strtoupper((string)\$msg));
    })
    ->start();
PHP;
        $file = tempnam(sys_get_temp_dir(), 'kode_echo_');
        file_put_contents($file, $code);
        return $file;
    }

    /**
     * HTTP chunked 流式：真实起 Native HTTP 服务，handler 分 3 块写响应，
     * 验证客户端收到的为完整 Transfer-Encoding: chunked 报文且重组后 body 完整。
     */
    public function testNativeHttpChunkedStreaming(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeChunkedServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');

        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "连接失败: {$errstr} ({$errno})");

            fwrite($fp, "GET /stream HTTP/1.1\r\nHost: localhost\r\nAccept: */*\r\nConnection: keep-alive\r\n\r\n");

            // 读到 chunked 终止块为止
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $c = @fread($fp, 8192);
                if ($c === '' || $c === false) {
                    break;
                }
                $buf .= $c;
            }

            $this->assertStringContainsString("Transfer-Encoding: chunked", $buf);
            $this->assertStringContainsString("Content-Type: text/plain", $buf);

            $body = $this->decodeChunkedBody($buf);
            $this->assertSame('part1-part2-part3', $body);

            fclose($fp);
        } finally {
            $status = proc_get_status($proc);
            $pid = $status['pid'] ?? null;
            if ($pid !== null && $pid > 0) {
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

    private function writeChunkedServerScript(int $port): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('http://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('message', function (\$conn, \$req): void {
        \$conn->beginChunked(200, ['Content-Type' => 'text/plain']);
        \$conn->chunk('part1-');
        \$conn->chunk('part2-');
        \$conn->chunk('part3');
    })
    ->start();
PHP;
        $file = tempnam(sys_get_temp_dir(), 'kode_chunk_');
        file_put_contents($file, $code);
        return $file;
    }

    private function decodeChunkedBody(string $buf): string
    {
        $pos     = strpos($buf, "\r\n\r\n");
        $chunked = substr($buf, $pos + 4);

        $body = '';
        $i    = 0;
        $len  = strlen($chunked);
        while ($i < $len) {
            $lineEnd = strpos($chunked, "\r\n", $i);
            if ($lineEnd === false) {
                break;
            }
            $size = hexdec(substr($chunked, $i, $lineEnd - $i));
            if ($size === 0) {
                break;
            }
            $start = $lineEnd + 2;
            $body .= substr($chunked, $start, $size);
            $i     = $start + $size + 2;
        }
        return $body;
    }

    // ------------------------------------------------------- HTTP gzip 自动压缩

    public function testNativeHttpGzipAutoWhenAccepted(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeHttpServerScript(
            $port,
            ['workers' => 1],
            'function ($conn, $req): void { $conn->send(str_repeat(\'K\', 4000)); }'
        );
        $proc = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            // 携带 Accept-Encoding: gzip → 响应应被压缩
            $resp = $this->httpGet($port, ['Accept-Encoding: gzip']);
            $this->assertStringContainsString('Content-Encoding: gzip', $resp);
            $this->assertSame(str_repeat('K', 4000), @gzdecode($this->httpBody($resp)));

            // 不携带 Accept-Encoding → 不压缩
            $resp2 = $this->httpGet($port, []);
            $this->assertStringNotContainsString('Content-Encoding: gzip', $resp2);
            $this->assertSame(str_repeat('K', 4000), $this->httpBody($resp2));
        } finally {
            $this->stopProc($proc, $script);
        }
    }

    public function testNativeHttpGzipDisabledByOption(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeHttpServerScript(
            $port,
            ['workers' => 1, 'gzip' => false],
            'function ($conn, $req): void { $conn->send(str_repeat(\'K\', 4000)); }'
        );
        $proc = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $resp = $this->httpGet($port, ['Accept-Encoding: gzip']);
            $this->assertStringNotContainsString('Content-Encoding: gzip', $resp);
            $this->assertSame(str_repeat('K', 4000), $this->httpBody($resp));
        } finally {
            $this->stopProc($proc, $script);
        }
    }

    public function testNativeHttpGzipExplicitApi(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeHttpServerScript(
            $port,
            ['workers' => 1],
            'function ($conn, $req): void { $conn->gzip(str_repeat(\'Z\', 4000), 200, [\'Content-Type\' => \'text/plain\']); }'
        );
        $proc = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            // 显式 gzip() 即使不带 Accept-Encoding 也压缩
            $resp = $this->httpGet($port, []);
            $this->assertStringContainsString('Content-Encoding: gzip', $resp);
            $this->assertStringContainsString('Content-Type: text/plain', $resp);
            $this->assertSame(str_repeat('Z', 4000), @gzdecode($this->httpBody($resp)));
        } finally {
            $this->stopProc($proc, $script);
        }
    }

    public function testNativeHttpGzipSkippedForSmallBody(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeHttpServerScript(
            $port,
            ['workers' => 1],
            'function ($conn, $req): void { $conn->send(\'hi\'); }'
        );
        $proc = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $resp = $this->httpGet($port, ['Accept-Encoding: gzip']);
            $this->assertStringNotContainsString('Content-Encoding: gzip', $resp);
            $this->assertStringContainsString("\r\n\r\nhi", $resp);
        } finally {
            $this->stopProc($proc, $script);
        }
    }

    private function writeHttpServerScript(int $port, array $opts, string $handlerCode): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $optCode  = var_export($opts, true);
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('http://127.0.0.1:{$port}', {$optCode}, 'native')
    ->on('message', {$handlerCode})
    ->start();
PHP;
        $file = tempnam(sys_get_temp_dir(), 'kode_http_');
        file_put_contents($file, $code);
        return $file;
    }

    private function httpGet(int $port, array $extraHeaders): string
    {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
        $this->assertNotFalse($fp, "连接失败: {$errstr} ({$errno})");

        $req = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n";
        foreach ($extraHeaders as $h) {
            $req .= $h . "\r\n";
        }
        $req .= "\r\n";

        fwrite($fp, $req);

        $buf = '';
        while (($c = @fread($fp, 8192)) !== '' && $c !== false) {
            $buf .= $c;
        }
        fclose($fp);
        return $buf;
    }

    private function httpBody(string $resp): string
    {
        $pos = strpos($resp, "\r\n\r\n");
        return $pos === false ? '' : substr($resp, $pos + 4);
    }

    private function stopProc($proc, string $script): void
    {
        $status = proc_get_status($proc);
        $pid    = $status['pid'] ?? null;
        if ($pid !== null && $pid > 0) {
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

    private function invokeProtected(string $class, string $method, array $args): mixed
    {
        return $this->invokeMethod($class, $method, $args, false);
    }

    /**
     * HTTP/2 优雅关闭：服务端收到 SIGTERM 后必须先给每条 h2 连接发 GOAWAY，
     * 再继续服务在途请求直至宽限期结束，而不是把连接直接 RST——
     * 否则正在进行的多路复用请求会全部失败，restart 也会中断在途连接。
     */
    public function testNativeHttp2GracefulShutdownSendsGoaway(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeHttp2ServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native h2c 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "连接失败: {$errstr} ({$errno})");
            stream_set_blocking($fp, false);

            // h2c prior-knowledge：发送连接前奏 + 一个空 SETTINGS 帧
            $preface  = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
            $settings = "\x00\x00\x00\x04\x00\x00\x00\x00\x00"; // len0 type4 flags0 stream0
            fwrite($fp, $preface . $settings);

            // 读取服务端初始 SETTINGS，证明 h2c 协商成功、连接已升级
            $initial = $this->readHttp2UntilType($fp, 0x04, 2.0);
            $this->assertTrue($initial, '服务端应回送 SETTINGS，确认 h2c 协商');

            // 触发优雅关闭
            $status = proc_get_status($proc);
            $pid    = $status['pid'] ?? null;
            $this->assertNotNull($pid, '取不到服务器 PID');
            posix_kill($pid, SIGTERM);

            // 宽限期内应读到 GOAWAY
            $gotGoaway = $this->readHttp2UntilType($fp, 0x07, 3.0);
            $this->assertTrue($gotGoaway, 'SIGTERM 后必须向 h2 连接发送 GOAWAY');

            fclose($fp);
        } finally {
            if (isset($proc) && is_resource($proc)) {
                $status = proc_get_status($proc);
                $pid    = $status['pid'] ?? null;
                if ($pid !== null && $pid > 0) {
                    @posix_kill($pid, SIGKILL);
                }
                proc_close($proc);
            }
            @unlink($script);
        }
    }

    private function writeHttp2ServerScript(int $port): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('http://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('message', function (\$conn, \$data): void {
        \$conn->send('ok');
    })
    ->start();
PHP;
        $file = tempnam(sys_get_temp_dir(), 'kode_h2_');
        file_put_contents($file, $code);
        return $file;
    }

    /**
     * 从非阻塞套接字逐帧解析 HTTP/2，遇到指定类型帧即返回 true（超时返回 false）。
     *
     * @param resource $fp
     */
    private function readHttp2UntilType($fp, int $type, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        $buf      = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($fp, 8192);
            if ($chunk === '' || $chunk === false) {
                usleep(5000);
                continue;
            }
            $buf .= $chunk;
            while (strlen($buf) >= 9) {
                $length = (ord($buf[0]) << 16) | (ord($buf[1]) << 8) | ord($buf[2]);
                $t      = ord($buf[3]);
                if (strlen($buf) < 9 + $length) {
                    break; // 帧未完整，等更多数据
                }
                if ($t === $type) {
                    return true;
                }
                $buf = substr($buf, 9 + $length);
            }
        }

        return false;
    }

    private function invokePrivate(string $class, string $method, array $args): mixed
    {
        return $this->invokeMethod($class, $method, $args, true);
    }

    private function invokeMethod(string $class, string $method, array $args, bool $private): mixed
    {
        $r = new \ReflectionMethod($class, $method);
        $r->setAccessible(true);
        // 取一个已构造的运行时实例用于非静态方法调用
        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        return $r->invokeArgs($instance, $args);
    }
}
