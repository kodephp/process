<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Kode;
use Kode\Process\Protocol\ProtocolFactory;
use Kode\Process\Runtime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Protocol\Http2\Http2Session;
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

    /** serve 选项 http2MaxHeaderListSize 必须被读取（默认取 Http2Session 常量） */
    public function testHttp2MaxHeaderListSizeOptionIsRead(): void
    {
        $ref = new \ReflectionClass(NativeRuntime::class);
        $prop = $ref->getProperty('http2MaxHeaderListSize');
        $prop->setAccessible(true);
        $method = $ref->getMethod('applyOptions');
        $method->setAccessible(true);

        $rt = new NativeRuntime();
        $method->invoke($rt, ['http2MaxHeaderListSize' => 8192]);
        $this->assertSame(8192, $prop->getValue($rt));

        $rtDefault = new NativeRuntime();
        $method->invoke($rtDefault, []);
        $this->assertSame(Http2Session::DEFAULT_MAX_HEADER_LIST_SIZE, $prop->getValue($rtDefault));
    }

    /**
     * start() 之前通过 addTimer() 注册的定时器必须进入「待重放」队列，
     * 且 $timers[$id] 存 null —— 否则 runWorker() 会把它当底层 timer id 解包，
     * 在 strict_types 下抛 TypeError，导致 worker 启动即崩、master 满速 refork。
     */
    public function testAddTimerBeforeStartIsQueuedForReplay(): void
    {
        $rt = new NativeRuntime();
        $id = $rt->addTimer(1.0, static fn () => null, true);
        $this->assertGreaterThan(0, $id);

        $ref     = new \ReflectionClass(NativeRuntime::class);
        $pending = $ref->getProperty('pendingTimers');
        $pending->setAccessible(true);
        $timers = $ref->getProperty('timers');
        $timers->setAccessible(true);

        $this->assertArrayHasKey($id, $pending->getValue($rt), 'start 前注册的定时器应进入待重放队列');
        $this->assertNull($timers->getValue($rt)[$id], '未启动时 $timers[$id] 应为 null，避免被当底层 id 解包崩溃');

        // delTimer 能正确移除待重放项
        $this->assertTrue($rt->delTimer($id));
        $this->assertArrayNotHasKey($id, $pending->getValue($rt));
    }

    /**
     * master 的停机总超时须覆盖 worker 的优雅宽限期，否则会在宽限期内 SIGKILL
     * 在途请求（gracefulShutdownTimeout=30 而 stopTimeout=5 时必现）。
     */
    public function testStopTimeoutCoversGracefulShutdownTimeout(): void
    {
        $ref    = new \ReflectionClass(NativeRuntime::class);
        $apply  = $ref->getMethod('applyOptions');
        $apply->setAccessible(true);
        $stopProp = $ref->getProperty('stopTimeout');
        $stopProp->setAccessible(true);

        $rt = new NativeRuntime();
        $apply->invoke($rt, ['gracefulShutdownTimeout' => 30, 'stopTimeout' => 5]);
        $this->assertGreaterThanOrEqual(31, $stopProp->getValue($rt));
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

    /**
     * F1 回归：并发建立大量连接时，Native 必须把监听 socket 上排队的连接**全部** accept 掉，
     * 而非每次可读事件只 accept 一个就返回（旧实现在边缘触发事件循环 / macOS SelectLoop 下
     * 会导致其余排队连接被「搁置」→ 客户端 connect 被拒或挂起）。
     *
     * 用单 worker 最大化复现概率（无 SO_REUSEPORT 多 worker 抢接干扰），一次性异步建连 50 个，
     * 断言每个连接都拿到正确回显。
     */
    public function testNativeConcurrentAcceptDrainsQueue(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeServerScript($port, 1); // 单 worker
        $proc   = proc_open([PHP_BINARY, $script, (string)$port], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $n      = 50;
            $conns  = [];
            // 一次性并发建立 N 个连接（异步 connect，使其在监听队列里排队）
            for ($i = 0; $i < $n; $i++) {
                $fp = @stream_socket_client(
                    "tcp://127.0.0.1:{$port}",
                    $errno, $errstr,
                    2.0,
                    STREAM_CLIENT_ASYNC_CONNECT
                );
                if ($fp === false) {
                    $this->fail("无法创建第 {$i} 个连接: {$errstr} ({$errno})");
                }
                stream_set_blocking($fp, false);
                $conns[$i] = $fp;
            }

            // 全部写出请求（非阻塞，边可写边写）
            $deadline = microtime(true) + 5.0;
            $written  = array_fill(0, $n, false);
            while (microtime(true) < $deadline && array_sum($written) < $n) {
                foreach ($conns as $i => $fp) {
                    if ($written[$i]) {
                        continue;
                    }
                    if (@fwrite($fp, "c{$i}\n") !== false) {
                        $written[$i] = true;
                    }
                }
                usleep(10_000);
            }

            // 读取全部响应
            $responses = array_fill(0, $n, null);
            $got       = array_fill(0, $n, false);
            while (microtime(true) < $deadline && array_sum($got) < $n) {
                foreach ($conns as $i => $fp) {
                    if ($got[$i]) {
                        continue;
                    }
                    $line = @fgets($fp, 1024);
                    if ($line !== false && $line !== '') {
                        $responses[$i] = $line;
                        $got[$i]       = true;
                    }
                }
                usleep(10_000);
            }

            foreach ($conns as $fp) {
                fclose($fp);
            }

            // 关键断言：每一个并发连接都必须拿到正确响应
            for ($i = 0; $i < $n; $i++) {
                $this->assertTrue($got[$i], "第 {$i} 个并发连接未拿到响应（F1 排空修复前典型失败）");
                $this->assertStringContainsString("pong:c{$i}", (string)$responses[$i]);
            }
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
     * 审计加固回归：HTTP 头部累积阶段 recvBuffer 硬上限（Slowloris 防护）。
     *
     * 客户端持续发送不含完整头块（\r\n\r\n）的超大缓冲，服务器应在超过
     * MAX_HEADER_BUFFER（64KB）时主动断开，而非任由 recvBuffer 无限增长打爆 worker。
     */
    public function testNativeHttpHeaderBufferCap(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $handler = 'function ($conn, $data): void {'
            . '$text = is_string($data) ? $data : json_encode($data);'
            . '$conn->send("pong:" . $text);'
            . '}';
        $script = $this->writeHttpServerScript($port, ['workers' => 1], $handler);
        $proc   = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native HTTP 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "HTTP 连接失败: {$errstr} ({$errno})");
            stream_set_timeout($fp, 3);

            // 发送 70KB 不含 \r\n\r\n 的伪头块，触发头部缓冲上限
            fwrite($fp, str_repeat('X', 70000));

            $buf    = '';
            $closed = false;
            for ($i = 0; $i < 40; $i++) {
                $chunk = @fread($fp, 1024);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    // 服务器竟然回了数据 → 防护失效
                    $buf .= $chunk;
                    break;
                }
                if (feof($fp)) {
                    $closed = true;
                    break;
                }
                usleep(100_000); // fread 超时（无数据）：继续等，不立即判定
            }

            $this->assertTrue($closed, '服务器未在头部缓冲超限时关闭连接（Slowloris 防护失效）');
            $this->assertStringNotContainsString(
                "\r\n\r\n",
                $buf,
                '超限连接不应被派发到 handler（缺少头部上限保护）'
            );
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
     * 审计加固回归：慢读超时回收（滴流型 Slowloris 时间维度防护）。
     *
     * 客户端只发「不完整请求头」（缺最终的 \r\n\r\n）且此后不再发送任何字节。
     * 默认 heartbeat=0 下这种连接不会被「空闲回收」（滴流字节可让心跳判定活跃），
     * 但 readTimeout 只看「不完整请求滞留时长」，应在超时后主动断开，
     * 而非任其永久占着连接、缓慢喂数据。
     */
    public function testNativeReadTimeoutRecyclesSlowReader(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $handler = 'function ($conn, $data): void {'
            . '$text = is_string($data) ? $data : json_encode($data);'
            . '$conn->send("pong:" . $text);'
            . '}';
        // readTimeout=1：不完整请求滞留超过 1s 即回收
        $script = $this->writeHttpServerScript($port, ['workers' => 1, 'readTimeout' => 1], $handler);
        $proc   = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native HTTP 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "HTTP 连接失败: {$errstr} ({$errno})");
            stream_set_timeout($fp, 2);

            // 只发半截请求头（无最终空行），且此后不再发送任何字节
            fwrite($fp, "GET / HTTP/1.1\r\nHost: example.com\r\n");

            $closed = false;
            $buf    = '';
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $chunk = @fread($fp, 1024);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    $buf .= $chunk; // 收到了数据（异常：handler 不应被触发）
                    break;
                }
                if (feof($fp)) {
                    $closed = true;
                    break;
                }
                usleep(100_000);
            }

            $this->assertTrue($closed, '服务器未在 readTimeout 内回收慢读连接（滴流型 Slowloris 防护失效）');
            $this->assertStringNotContainsString('pong:', $buf, '不完整请求不应被派发到 handler');
            fclose($fp);
        } finally {
            $this->stopProc($proc, $script);
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

    private function writeServerScript(int $port, int $workers = 2): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('text://127.0.0.1:{$port}', ['workers' => {$workers}], 'native')
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

    /**
     * 审计加固回归：WebSocket 升级请求与首帧在同一包内管道发送时不得丢帧。
     *
     * 旧实现握手成功后整体 clearBuffer()，会丢弃紧随升级请求之后的首帧（部分客户端
     * 把 upgrade 与第一帧合并发送），表现为握手成功但首条消息石沉大海。修复后仅截取
     * 请求部分，剩余字节落到帧处理循环立即消费。
     */
    public function testNativeWebSocketHandshakePipelinedFrame(): void
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
            $fp  = $this->wsConnect($port);
            $key = base64_encode(random_bytes(16));

            // 握手请求与一条掩码文本帧合并为单个写操作
            $handshake = "GET / HTTP/1.1\r\n"
                . "Host: 127.0.0.1\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
            $firstFrame = $this->wsMaskedFrame(0x1, 'piped-first');

            fwrite($fp, $handshake . $firstFrame);

            // 101 握手响应与首帧回显可能在同一 TCP 段内合并到达，一次性读完再判定，
            // 避免先读 101 把后续回显字节从流里「吃」掉导致误判丢帧。
            stream_set_timeout($fp, 3);
            $all = '';
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $chunk = @fread($fp, 4096);
                if ($chunk === '' || $chunk === false) {
                    if (feof($fp)) {
                        break;
                    }
                    usleep(50_000);
                    continue;
                }
                $all .= $chunk;
                if (str_contains($all, "\r\n\r\n") && str_contains($all, 'piped-first')) {
                    break;
                }
            }

            $this->assertStringContainsString('101', $all, 'WS 握手未返回 101');
            // 回显载荷里出现 'piped-first' 即证明管道过来的首帧被正确处理、未丢失
            $this->assertStringContainsString(
                'piped-first',
                $all,
                '升级请求后管道过来的首帧被丢弃（丢帧 bug）'
            );

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

    /**
     * UDP 回包路由：单次可读事件内循环排空所有挂起数据报。
     *
     * 连发 50 个数据报（突发的，先写满不读），服务端 handler 把每包序号记录进集合，
     * 验证全部送达且顺序正确。边缘触发 loop（ev）下若 receiveUdp 不循环排空会丢包，
     * 水平触发（select）下两种实现都能收全——本测试同时覆盖了正确性与循环排空。
     */
    public function testNativeUdpReceivesAllDatagrams(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port    = $this->findFreePort();
        $setFile = tempnam(sys_get_temp_dir(), 'kode_udp_set_');
        @unlink($setFile); // 避免 tempnam 创建的空文件干扰 server 端的 unserialize
        $script  = $this->writeUdpCountServerScript($port, $setFile);
        $proc    = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native UDP 服务器子进程');
        // 必须等 worker 真正 bind UDP 端口后再发包：未监听端口的数据报会被内核丢弃
        // （ICMP port unreachable），而非缓冲到后来的监听套接字。
        usleep(2_000_000);

        try {
            $fp = @fsockopen('udp://127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "UDP 连接失败: {$errstr} ({$errno})");

            for ($i = 1; $i <= 50; $i++) {
                fwrite($fp, pack('n', $i));
            }
            fclose($fp);

            // 等服务端处理完所有排队包
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline && !file_exists($setFile)) {
                usleep(20_000);
            }

            $set = file_exists($setFile) ? @unserialize(file_get_contents($setFile)) : [];
            $this->assertCount(50, $set, 'UDP 数据报未全部送达（可能循环排空缺失导致丢包）');
            $this->assertSame(range(1, 50), $set, 'UDP 数据报顺序应被保留');
        } finally {
            $this->stopProc($proc, $script);
            @unlink($setFile);
        }
    }

    /**
     * UDP 回包路由：大包防截断。
     *
     * 此前 receiveUdp 用 READ_CHUNK(65535) 作 recvfrom 缓冲区，超过该长度的 UDP 包
     * 会被静默截断（recvfrom 不报错，只返回前段）——UDP 数据报理论最大 65507 字节，
     * 因此 65535 字节以内的包就被截掉尾部。修复后改用 UDP_MAX_PACKET(65507)，
     * 发送理论最大包应被完整接收。
     */
    public function testNativeUdpLargePacketNotTruncated(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port    = $this->findFreePort();
        $lenFile = tempnam(sys_get_temp_dir(), 'kode_udp_len_');
        $script  = $this->writeUdpSizeServerScript($port, $lenFile);
        $proc    = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native UDP 服务器子进程');
        usleep(2_000_000);

        // 确定性断言：recvfrom 缓冲上限应为 UDP 数据报理论最大值 65507，
        // 而非旧的 READ_CHUNK(65535)——后者会让更大的包被静默截断。
        $ref = new \ReflectionClass(NativeRuntime::class);
        $this->assertSame(65507, $ref->getConstant('UDP_MAX_PACKET'), 'receiveUdp 应使用 65507 缓冲上限');

        try {
            $fp = @fsockopen('udp://127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "UDP 连接失败: {$errstr} ({$errno})");

            // 理论最大 UDP 负载（IPv4）。注意：macOS loopback 受 net.inet.udp.maxdgram
            // 限制（默认约 9216 字节）无法发送此大包；Linux 上可达 65507。平台不支持时跳过动态验证。
            $payload = str_repeat('A', 65507);
            $sent    = @fwrite($fp, $payload);
            if ($sent === false || $sent < 65507) {
                fclose($fp);
                $this->markTestSkipped('当前平台 loopback UDP 上限不足 65507 字节，跳过动态大包验证（静态常量断言已覆盖）');
            }
            fclose($fp);

            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline && !file_exists($lenFile)) {
                usleep(20_000);
            }

            $got = file_exists($lenFile) ? (int)file_get_contents($lenFile) : 0;
            $this->assertSame(65507, $got, 'UDP 大包被截断（应完整收到 65507 字节）');
        } finally {
            $this->stopProc($proc, $script);
            @unlink($lenFile);
        }
    }

    /**
     * TCP 连接状态机：message handler 抛异常后，运行时应主动回收该连接，
     * 而不是把它留在连接表等心跳超时（半响应挂住客户端 + 连接泄漏）。
     *
     * 起 text 服务，handler 抛异常（已注册 error 处理器兜底），验证客户端在发消息后
     * 读到 EOF（连接被关闭），证明连接被主动回收。
     */
    public function testNativeTcpHandlerExceptionClosesConnection(): void
    {
        if (!NativeRuntime::isAvailable()) {
            $this->markTestSkipped('需要 PHP CLI + ext-pcntl + ext-posix');
        }

        $port   = $this->findFreePort();
        $script = $this->writeTextThrowServerScript($port);
        $proc   = proc_open([PHP_BINARY, $script], [], $pipes);
        $this->assertIsResource($proc, '无法启动 Native 服务器子进程');
        $this->waitForPort($port, 4.0);

        try {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            $this->assertNotFalse($fp, "连接失败: {$errstr} ({$errno})");
            // 2 秒读超时：用于区分「连接被主动关闭（立即 EOF）」与「连接未关闭（等到超时）」。
            stream_set_timeout($fp, 2);

            fwrite($fp, "trigger\n");

            // handler 抛异常 → 连接应被主动关闭 → 客户端立即读到 EOF（而非阻塞到超时）。
            $resp = @fread($fp, 1024);
            $meta = stream_get_meta_data($fp);
            $this->assertFalse($meta['timed_out'], 'handler 异常后连接应被主动关闭，不应阻塞到读超时');
            $this->assertSame('', (string)$resp, 'handler 异常后连接应被主动关闭（读到 EOF）');

            fclose($fp);
        } finally {
            $this->stopProc($proc, $script);
        }
    }

    private function writeUdpCountServerScript(int $port, string $file): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('udp://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('error', function (): void {})
    ->on('message', function (\$conn, \$data): void {
        \$seq = unpack('n', \$data)[1] ?? 0;
        \$raw = (file_exists('{$file}') && filesize('{$file}') > 0) ? file_get_contents('{$file}') : '';
        \$set = \$raw !== '' ? unserialize(\$raw) : [];
        \$set[] = \$seq;
        file_put_contents('{$file}', serialize(\$set));
    })
    ->start();
PHP;
        $f = tempnam(sys_get_temp_dir(), 'kode_udp_count_');
        file_put_contents($f, $code);
        return $f;
    }

    private function writeUdpSizeServerScript(int $port, string $file): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('udp://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('error', function (): void {})
    ->on('message', function (\$conn, \$data): void {
        file_put_contents('{$file}', (string)strlen(\$data));
    })
    ->start();
PHP;
        $f = tempnam(sys_get_temp_dir(), 'kode_udp_size_');
        file_put_contents($f, $code);
        return $f;
    }

    private function writeTextThrowServerScript(int $port): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = <<<PHP
<?php
require '{$autoload}';
use Kode\\Process\\Kode;

Kode::serve('text://127.0.0.1:{$port}', ['workers' => 1], 'native')
    ->on('error', function (): void {})
    ->on('message', function (\$conn, \$data): void {
        throw new \\RuntimeException('handler boom');
    })
    ->start();
PHP;
        $f = tempnam(sys_get_temp_dir(), 'kode_text_throw_');
        file_put_contents($f, $code);
        return $f;
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
