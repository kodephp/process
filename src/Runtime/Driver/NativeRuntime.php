<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Http\Request;
use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Http2Exception;
use Kode\Process\Protocol\Http2\Http2Session;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Protocol\LengthPrefix;
use Kode\Process\Protocol\ProtocolFactory;
use Kode\Process\Protocol\TcpProtocol;
use Kode\Process\Protocol\TextProtocol;
use Kode\Process\Protocol\WebSocketProtocol;
use Kode\Process\Reactor\LoopFactory;
use Kode\Process\Reactor\LoopInterface;
use Kode\Process\Runtime\AbstractRuntime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\ConnectionInterface;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;
use Kode\Process\Version;

/**
 * 自研（Native）运行时：本包默认的纯 PHP master-worker 多进程服务器。
 *
 * 零扩展依赖——只用 CLI 自带的 pcntl / posix，事件循环走 {@see LoopFactory}
 * 自动择优（装了 ext-event / ext-ev 就下沉到 C 层多路复用，否则用 stream_select 兜底）。
 * 因此它在任意 PHP 8.3+ CLI 环境都能直接跑，同时又能吃到扩展带来的加速。
 *
 * 进程模型：
 * ```
 * master ─┬─ worker #0..N-1   事件循环 + 连接处理（继承监听套接字 / SO_REUSEPORT）
 *         └─ task  #0..M-1    专用任务进程，通过 socketpair 接收投递、回传结果
 * ```
 *
 * 相对早期版本补齐的能力（对齐 Swoole / Workerman 的优点）：
 *  - 可插拔事件循环（event / ev / select），不再写死 select
 *  - HTTP keep-alive 长连接复用，不再一请求一断开
 *  - 异步发送缓冲 + 背压保护，慢客户端不阻塞 worker
 *  - UDP 服务端、SSL/TLS、frame（长度前缀）协议
 *  - Task 工作进程（{@see task()}）、maxRequest 自动回收、空闲连接心跳
 *  - 守护化、PID 文件、进程标题、降权运行、优雅停机超时
 *
 * 支持的监听地址：
 * `tcp:// http:// websocket:// text:// frame:// udp:// unix:// ssl://`
 *
 * @see RuntimeType::Native
 */
final class NativeRuntime extends AbstractRuntime
{
    /**
     * 逻辑协议 → 真实流传输。http / websocket / text / frame 都是跑在 TCP 之上的
     * 应用层协议，监听套接字统一用 tcp 传输，具体协议由 Protocol 系统在连接层解析。
     *
     * @var array<string, string>
     */
    private const array STREAM_TRANSPORT = [
        'tcp'       => 'tcp',
        'http'      => 'tcp',
        'websocket' => 'tcp',
        'text'      => 'tcp',
        'frame'     => 'tcp',
        'ssl'       => 'tcp',
        'udp'       => 'udp',
        'unix'      => 'unix',
    ];

    /** 单次读取字节数 */
    private const int READ_CHUNK = 65535;

    /** @var array<int, resource> 非 reusePort 模式下 master 预开的监听套接字（子进程继承） */
    private array $sharedSockets = [];

    /** @var array<int, int> master 维护的 worker PID → 序号 */
    private array $workerPids = [];

    /** @var array<int, int> master 维护的 task 进程 PID → 序号 */
    private array $taskPids = [];

    /**
     * 任务管道：`[workerIndex][taskIndex] = [workerEnd, taskEnd]`。
     * 每对 (worker, task) 独占一条管道，写入端唯一，避免多写者报文交错，
     * 结果也能准确回到发起投递的那个 worker。
     *
     * @var array<int, array<int, array{0: resource, 1: resource}>>
     */
    private array $taskPipes = [];

    private int $workerCount = 1;

    private int $taskWorkerCount = 0;

    private bool $reusePort = false;

    private bool $isWorker = false;

    private bool $isTaskWorker = false;

    private int $workerId = 0;

    private ?LoopInterface $loop = null;

    /** @var array<int, NativeConnection> 当前 worker 持有的连接（key = 连接 ID） */
    private array $connections = [];

    /** @var array<int, resource> 连接 ID → 套接字，便于关闭时反查 */
    private array $connectionSockets = [];

    private int $handledRequests = 0;

    private int $maxRequest = 0;

    private int $stopTimeout = 5;

    private int $heartbeat = 0;

    private bool $keepAlive = true;

    private bool $gzipEnabled = true;

    /** h2c（明文 HTTP/2）总开关，对 http:// 监听生效 */
    private bool $http2Enabled = true;

    private int $http2MaxConcurrentStreams = 128;

    private int $http2InitialWindow = 1048576;

    private int $http2MaxHeaderListSize = 65536;

    /** 优雅关闭宽限期（秒）：SIGTERM 后让在途请求/流完成的最长等待 */
    private int $gracefulShutdownTimeout = 3;

    /** 是否处于优雅关闭中（收到 SIGTERM/SIGINT 后置位） */
    private bool $shuttingDown = false;

    /** 监听套接字集合，关闭时用于停止接收新连接 */
    private array $listenerSockets = [];

    private int $maxSendBuffer = NativeConnection::DEFAULT_MAX_SEND_BUFFER;

    private string $serverName = 'kode-process';

    private ?string $pidFile = null;

    /** @var array<int, string> 任务管道读缓冲（key = (int) socket） */
    private array $pipeBuffers = [];

    private int $taskRoundRobin = 0;

    public static function isAvailable(): bool
    {
        return PHP_SAPI === 'cli'
            && extension_loaded('pcntl')
            && extension_loaded('posix')
            && function_exists('pcntl_fork');
    }

    public static function type(): RuntimeType
    {
        return RuntimeType::Native;
    }

    public static function version(): ?string
    {
        return Version::VERSION;
    }

    /**
     * SO_REUSEPORT 的平台默认策略（见 {@see start()} 中的说明）。
     *
     * 抽成独立方法便于单元测试，也集中了「何时默认开启端口复用」的决策。
     */
    public static function defaultReusePort(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && defined('SO_REUSEPORT');
    }

    public function __construct()
    {
        if (!self::isAvailable()) {
            throw RuntimeNotSupportedException::unavailable(
                RuntimeType::Native,
                '需要 PHP CLI + ext-pcntl + ext-posix'
            );
        }
    }

    protected function supportedSchemes(): array
    {
        // 内置 scheme 永远支持；已通过 ProtocolFactory::register 注册的自定义协议同样一等公民。
        return array_values(array_unique([
            'tcp', 'http', 'websocket', 'text', 'frame', 'udp', 'unix', 'ssl',
            ...ProtocolFactory::available(),
        ]));
    }

    public function capabilities(): array
    {
        return [
            Capability::SharedTable,
            Capability::TaskWorker,
            Capability::UdpServer,
            Capability::UnixSocket,
            Capability::Ssl,
            Capability::HotReload,
            Capability::ReusePort,
            Capability::WebSocket,
            Capability::Timer,
        ];
    }

    /** 当前 worker 序号；master / 未启动时为 0 */
    public function workerId(): int
    {
        return $this->workerId;
    }

    /**
     * 当前 worker 持有的连接。
     *
     * @return array<int, ConnectionInterface>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    /**
     * 把 serve 选项落到运行时属性。抽出来便于单测（无需真正启动 server）。
     *
     * @param array<string, mixed> $opts
     */
    protected function applyOptions(array $opts): void
    {
        // SO_REUSEPORT 默认策略因平台而异（经同机 A/B 实测验证）：
        //  - Linux：默认开启。每个 worker 拥有独立监听 socket，由内核将新连接直接分发到
        //    某一 worker，彻底消除「fork 共享监听 socket 的惊群争抢」。
        //  - macOS / BSD：默认关闭。其 kqueue + 共享监听 socket 的实现反而比 SO_REUSEPORT
        //    更高效，故沿用共享 socket 路径。不支持 SO_REUSEPORT 的平台一律回退为共享 socket。
        $this->reusePort       = (bool)($opts['reusePort'] ?? self::defaultReusePort());
        $this->workerCount     = max(1, (int)($opts['workers'] ?? 4));
        $this->taskWorkerCount = max(0, (int)($opts['taskWorkers'] ?? 0));
        $this->maxRequest      = max(0, (int)($opts['maxRequest'] ?? 0));
        $this->stopTimeout     = max(1, (int)($opts['stopTimeout'] ?? 5));
        $this->heartbeat       = max(0, (int)($opts['heartbeat'] ?? 0));
        $this->keepAlive       = (bool)($opts['keepAlive'] ?? true);
        $this->gzipEnabled     = (bool)($opts['gzip'] ?? true);

        // HTTP/2（h2c）：默认开启，与 HTTP/1.1 在同一端口自动协商，
        // 客户端不支持 h2 时完全不受影响（既不改握手也不加解析开销）。
        $this->http2Enabled              = (bool)($opts['http2'] ?? true);
        $this->http2MaxConcurrentStreams = max(1, (int)($opts['http2MaxConcurrentStreams'] ?? 128));
        $this->http2InitialWindow        = max(Frame::DEFAULT_WINDOW_SIZE, (int)($opts['http2InitialWindow'] ?? 1048576));
        $this->http2MaxHeaderListSize    = max(0, (int)($opts['http2MaxHeaderListSize'] ?? Http2Session::DEFAULT_MAX_HEADER_LIST_SIZE));
        $this->gracefulShutdownTimeout   = max(0, (int)($opts['gracefulShutdownTimeout'] ?? 3));

        $this->maxSendBuffer   = max(65536, (int)($opts['maxSendBuffer'] ?? NativeConnection::DEFAULT_MAX_SEND_BUFFER));
        $this->serverName      = (string)($opts['name'] ?? 'kode-process');
        $this->pidFile         = isset($opts['pidFile']) ? (string)$opts['pidFile'] : null;
    }

    // ------------------------------------------------------------- 启动

    public function start(): void
    {
        $listener = $this->requireListener();
        $opts     = $listener['options'];

        // SO_REUSEPORT 默认策略因平台而异（经同机 A/B 实测验证）：
        //  - Linux：默认开启。每个 worker 拥有独立监听 socket，由内核将新连接直接分发到
        //    某一 worker，彻底消除「fork 共享监听 socket 的惊群争抢」（否则高并发下 accept
        //    会唤醒所有 worker 只留一个成功，上下文切换风暴导致 P99 飙升）。
        //  - macOS / BSD：默认关闭。其 kqueue + 共享监听 socket 的实现反而比 SO_REUSEPORT
        //    更高效（实测开启后吞吐下降约 1/3、尾部延迟更差），故沿用共享 socket 路径。
        // 不支持 SO_REUSEPORT 的平台一律回退为共享 socket。
        $this->applyOptions($opts);

        if (!empty($opts['daemonize'])) {
            $this->daemonize((string)($opts['logFile'] ?? '/dev/null'));
        }

        if (!$this->reusePort) {
            foreach ($this->listeners as $i => $l) {
                $this->sharedSockets[$i] = $this->openServerSocket($l, false);
            }
        }

        $this->createTaskPipes();
        $this->writePidFile();
        $this->setProcessTitle('master');
        $this->running = true;

        for ($t = 0; $t < $this->taskWorkerCount; $t++) {
            $this->spawnTaskWorker($t);
        }
        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->runMaster();
    }

    /** 标准两次 fork + setsid 脱离终端 */
    private function daemonize(string $logFile): void
    {
        if (pcntl_fork() > 0) {
            exit(0);
        }
        if (posix_setsid() === -1) {
            throw new \RuntimeException('daemonize 失败：posix_setsid() 返回 -1');
        }
        if (pcntl_fork() > 0) {
            exit(0);
        }
        umask(0);

        $target = $logFile !== '' ? $logFile : '/dev/null';
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        $STDOUT = fopen($target, 'a');
        $STDERR = fopen($target, 'a');
    }

    private function writePidFile(): void
    {
        if ($this->pidFile === null || $this->pidFile === '') {
            return;
        }
        @file_put_contents($this->pidFile, (string)posix_getpid());
    }

    private function setProcessTitle(string $role): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }
        @cli_set_process_title(sprintf('%s: %s', $this->serverName, $role));
    }

    /** 按需降权（以 root 启动、以普通用户运行） */
    private function dropPrivileges(): void
    {
        $opts = $this->primaryOptions();
        $user = $opts['user'] ?? null;
        if ($user === null || posix_getuid() !== 0) {
            return;
        }
        $group = $opts['group'] ?? null;
        if (is_string($group) && $group !== '') {
            $gi = posix_getgrnam($group);
            if ($gi !== false) {
                posix_setgid((int)$gi['gid']);
            }
        }
        $ui = posix_getpwnam((string)$user);
        if ($ui !== false) {
            posix_setuid((int)$ui['uid']);
        }
    }

    // ------------------------------------------------------- 任务进程管道

    private function createTaskPipes(): void
    {
        if ($this->taskWorkerCount === 0) {
            return;
        }
        for ($w = 0; $w < $this->workerCount; $w++) {
            for ($t = 0; $t < $this->taskWorkerCount; $t++) {
                $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false) {
                    throw new \RuntimeException('创建 task 管道失败：stream_socket_pair()');
                }
                $this->taskPipes[$w][$t] = [$pair[0], $pair[1]];
            }
        }
    }

    /**
     * 投递任务到 task 工作进程（轮询选择）。
     *
     * 未配置 `taskWorkers` 或不在 worker 进程内时，**优雅降级**为进程内同步执行
     * （见 {@see AbstractRuntime::task()}），保证同一份业务代码在三种运行时下
     * 行为一致——切换底层无需改动调用方。
     */
    public function task(mixed $data): bool
    {
        // 无 task 进程可用 → 同步兜底，不抛异常
        if ($this->taskWorkerCount === 0 || !$this->isWorker) {
            return parent::task($data);
        }

        $t    = $this->taskRoundRobin++ % $this->taskWorkerCount;
        $pipe = $this->taskPipes[$this->workerId][$t][0] ?? null;
        if (!is_resource($pipe)) {
            return parent::task($data);
        }

        return @fwrite($pipe, self::packMessage($data)) !== false;
    }

    private static function packMessage(mixed $payload): string
    {
        $body = serialize($payload);
        return pack('N', strlen($body)) . $body;
    }

    /**
     * 从管道缓冲中取出所有完整报文。
     *
     * @return list<mixed>
     */
    private function drainMessages(mixed $pipe): array
    {
        $key  = (int)$pipe;
        $data = @fread($pipe, self::READ_CHUNK);
        if ($data === false || $data === '') {
            return [];
        }
        $buffer = ($this->pipeBuffers[$key] ?? '') . $data;

        $out = [];
        while (strlen($buffer) >= 4) {
            $head = unpack('Nlen', $buffer);
            if ($head === false) {
                break;
            }
            $len = $head['len'];
            if (strlen($buffer) < 4 + $len) {
                break;
            }
            $out[]  = unserialize(substr($buffer, 4, $len));
            $buffer = substr($buffer, 4 + $len);
        }
        $this->pipeBuffers[$key] = $buffer;

        return $out;
    }

    // ---------------------------------------------------------- 进程孵化

    private function spawnWorker(int $index): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            return;
        }
        if ($pid === 0) {
            $entries = [];
            foreach ($this->listeners as $i => $l) {
                $socket    = $this->reusePort ? $this->openServerSocket($l, true) : $this->sharedSockets[$i];
                $entries[] = ['socket' => $socket, 'listener' => $l];
            }
            $this->closeForeignTaskEnds($index, true);
            $this->runWorker($index, $entries);
            exit(0);
        }
        $this->workerPids[$pid] = $index;
    }

    private function spawnTaskWorker(int $index): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            return;
        }
        if ($pid === 0) {
            $this->closeForeignTaskEnds($index, false);
            $this->runTaskWorker($index);
            exit(0);
        }
        $this->taskPids[$pid] = $index;
    }

    /**
     * 子进程只保留自己那一侧的管道端，其余全部关闭，避免 fd 泄漏。
     *
     * @param bool $asWorker true=worker（保留 [w][*][0]），false=task（保留 [*][t][1]）
     */
    private function closeForeignTaskEnds(int $index, bool $asWorker): void
    {
        foreach ($this->taskPipes as $w => $row) {
            foreach ($row as $t => $pair) {
                $keep = $asWorker ? ($w === $index ? 0 : -1) : ($t === $index ? 1 : -1);
                foreach ([0, 1] as $side) {
                    if ($side !== $keep && is_resource($pair[$side])) {
                        @fclose($pair[$side]);
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------ worker

    /**
     * @param list<array{socket: resource, listener: array<string, mixed>}> $entries
     */
    private function runWorker(int $workerId, array $entries): void
    {
        $this->isWorker = true;
        $this->workerId = $workerId;
        $this->setProcessTitle('worker #' . $workerId);
        $this->dropPrivileges();
        pcntl_async_signals(true);

        $loop       = LoopFactory::create($this->primaryOptions()['loop'] ?? null);
        $this->loop = $loop;

        $this->listenerSockets = [];
        foreach ($entries as $entry) {
            $sock     = $entry['socket'];
            $listener = $entry['listener'];
            stream_set_blocking($sock, false);
            $this->listenerSockets[] = $sock;

            if ($listener['scheme'] === 'udp') {
                $loop->onReadable($sock, function ($s) use ($listener): void {
                    $this->receiveUdp($s, $listener);
                });
                continue;
            }

            $loop->onReadable($sock, function ($s) use ($listener): void {
                $this->accept($s, $listener);
            });
        }

        // task 结果回传管道
        foreach ($this->taskPipes[$workerId] ?? [] as $pair) {
            $pipe = $pair[0];
            stream_set_blocking($pipe, false);
            $loop->onReadable($pipe, function ($p): void {
                foreach ($this->drainMessages($p) as $result) {
                    $this->fire('finish', $result);
                }
            });
        }

        foreach ($this->timers as $t) {
            $loop->addTimer($t['interval'], $t['callback'], $t['periodic']);
        }
        // 共享粗时钟：每秒推进一次，供连接在收发时记录活跃时刻。
        // 秒级精度对空闲回收（周期以十秒计）绰绰有余，却让热路径彻底摆脱 microtime()。
        NativeConnection::tickClock(microtime(true));
        $loop->addTimer(1.0, static function (): void {
            NativeConnection::tickClock(microtime(true));
        }, true);

        if ($this->heartbeat > 0) {
            $loop->addTimer(min(10.0, (float)$this->heartbeat), function (): void {
                $this->recycleIdleConnections();
            }, true);
        }

        $stop = function () use ($loop): void {
            if ($this->shuttingDown) {
                return; // 避免重复触发（同一信号可能被多次投递）
            }
            $this->shuttingDown = true;

            // 1) 停止接收新连接：移除监听套接字的可读监听
            foreach ($this->listenerSockets as $ls) {
                if (is_resource($ls)) {
                    $this->loop?->offReadable($ls);
                }
            }

            // 2) HTTP/2 连接先发 GOAWAY，让对端干净地停止发起新流、
            //    等待在途流完成，而不是被 RST 硬切断。
            foreach ($this->connections as $conn) {
                if (!$conn->isHttp2()) {
                    continue;
                }
                $session = $conn->http2Session();
                if ($session !== null && !$session->isClosed()) {
                    $session->goaway(Frame::ERROR_NO_ERROR);
                    $conn->flushHttp2();
                }
            }

            // 3) 宽限期：让在途请求/流完成；期间连接自然关闭，或超时后强制退出。
            //    所有连接都在宽限期内关闭时，closeConnection() 会提前结束循环。
            $loop->addTimer(max(0.1, $this->gracefulShutdownTimeout), function () use ($loop): void {
                $loop->stop();
            });
        };
        $loop->onSignal(SIGTERM, $stop);
        $loop->onSignal(SIGUSR1, $stop);
        $loop->onSignal(SIGINT, $stop);

        $this->fire('workerStart', $workerId);
        $loop->run();

        foreach ($this->connections as $conn) {
            $conn->gracefulClose();
        }
        $this->connections       = [];
        $this->connectionSockets = [];
        $this->fire('workerStop', $workerId);
        $loop->destroy();
        $this->loop = null;
    }

    private function runTaskWorker(int $taskId): void
    {
        $this->isTaskWorker = true;
        $this->workerId     = $taskId;
        $this->setProcessTitle('task #' . $taskId);
        $this->dropPrivileges();
        pcntl_async_signals(true);

        $loop       = LoopFactory::create($this->primaryOptions()['loop'] ?? null);
        $this->loop = $loop;

        foreach ($this->taskPipes as $row) {
            $pipe = $row[$taskId][1] ?? null;
            if (!is_resource($pipe)) {
                continue;
            }
            stream_set_blocking($pipe, false);
            $loop->onReadable($pipe, function ($p) use ($taskId): void {
                foreach ($this->drainMessages($p) as $payload) {
                    $result = $this->fire('task', $payload, $taskId);
                    if ($result !== null) {
                        @fwrite($p, self::packMessage($result));
                    }
                }
            });
        }

        $stop = static function () use ($loop): void {
            $loop->stop();
        };
        $loop->onSignal(SIGTERM, $stop);
        $loop->onSignal(SIGUSR1, $stop);
        $loop->onSignal(SIGINT, $stop);

        $this->fire('workerStart', $taskId);
        $loop->run();
        $this->fire('workerStop', $taskId);
        $loop->destroy();
    }

    /** 心跳回收：关闭超过 heartbeat 秒没有任何读写的空闲连接 */
    private function recycleIdleConnections(): void
    {
        $now = microtime(true);

        // 推进连接共享时钟：热路径据此记录活跃时刻，无需自行取时间。
        // 刷新与判定在同一次心跳内完成，两者看到的是同一个「现在」。
        NativeConnection::tickClock($now);

        $deadline = $now - $this->heartbeat;
        foreach ($this->connections as $conn) {
            if ($conn->lastActiveAt() < $deadline) {
                $this->closeConnection($conn);
            }
        }
    }

    // -------------------------------------------------------- 连接与收发

    /**
     * @param resource             $serverSock
     * @param array<string, mixed> $listener
     */
    private function accept($serverSock, array $listener): void
    {
        $connSock = @stream_socket_accept($serverSock, 0, $peerName);
        if ($connSock === false) {
            return; // 惊群：其它 worker 已抢先 accept
        }
        $this->tuneSocket($connSock);

        $scheme = (string)$listener['scheme'];
        $conn   = new NativeConnection(
            $connSock,
            (string)($peerName ?? ''),
            $this->protocolClassFor($scheme),
            $this->loop,
            null,
            $this->maxSendBuffer
        );

        $isSsl = $scheme === 'ssl' || isset($listener['options']['ssl']);
        if ($isSsl) {
            $conn->setSslPending();
        }

        $this->connections[$conn->id()]       = $conn;
        $this->connectionSockets[$conn->id()] = $connSock;

        $this->loop?->onReadable($connSock, function ($s) use ($conn, $listener): void {
            $this->handleClientRead($s, $conn, $listener);
        });

        if (!$isSsl) {
            $this->fire('connect', $conn);
        }
    }

    /**
     * 新连接的套接字调优——这是 Native 相对「照搬默认值」实现的主要吞吐来源。
     *
     * 1. 非阻塞：事件驱动的前提。
     * 2. 关闭 PHP 流的用户态读写缓冲：默认 PHP 会把内核数据先拷进 8KB 的流缓冲、
     *    再拷给 PHP 字符串，等于每个请求多一次 memcpy 和可能的多次 read()；
     *    置 0 后 fread 直落 read(2)，一次系统调用取走整个请求。
     * 3. chunk size 对齐单次读取上限：避免 fread(READ_CHUNK) 被默认 8KB 切成多次读。
     *
     * TCP_NODELAY 在监听套接字上设置并由 accept 继承，见 {@see openServerSocket()}。
     *
     * @param resource $sock
     */
    private function tuneSocket($sock): void
    {
        stream_set_blocking($sock, false);
        // 关闭 PHP 用户态流缓冲：事件循环已按就绪读写，额外缓冲只增加拷贝与延迟。
        stream_set_read_buffer($sock, 0);
        stream_set_write_buffer($sock, 0);
        stream_set_chunk_size($sock, self::READ_CHUNK);
    }

    /**
     * @param resource             $serverSock
     * @param array<string, mixed> $listener
     */
    private function receiveUdp($serverSock, array $listener): void
    {
        $peer = '';
        $data = @stream_socket_recvfrom($serverSock, self::READ_CHUNK, 0, $peer);
        if ($data === false || $data === '') {
            return;
        }
        $conn = new NativeConnection(
            $serverSock,
            $peer,
            $this->protocolClassFor((string)$listener['scheme']),
            $this->loop,
            $peer,
            $this->maxSendBuffer
        );
        $this->fireMessage($conn, $data);
        $this->countRequest();
    }

    /**
     * @param resource             $sock
     * @param array<string, mixed> $listener
     */
    private function handleClientRead($sock, NativeConnection $conn, array $listener): void
    {
        if (!$conn->isSslReady() && !$this->finishSslHandshake($sock, $conn)) {
            return;
        }

        $data = @fread($sock, self::READ_CHUNK);
        if ($data === false || $data === '') {
            $this->closeConnection($conn);
            return;
        }
        $conn->appendBuffer($data);

        $scheme = (string)$listener['scheme'];

        // HTTP/2：已升级的连接后续字节全部交给会话状态机
        if ($conn->isHttp2()) {
            $this->handleHttp2Read($conn);
            return;
        }

        // h2c prior-knowledge：客户端直接以连接前奏开场（curl --http2-prior-knowledge、
        // gRPC 等）。只在协议尚未定型的首包比较 4 字节，定型后此分支被布尔短路跳过。
        if ($scheme === 'http' && $this->http2Enabled && !$conn->isHandshakeDone()) {
            $buf  = $conn->getBuffer();
            $seen = min(strlen($buf), 4);
            if (strncmp($buf, 'PRI ', $seen) === 0) {
                if ($seen < 4) {
                    return; // 还分不清是 h2 前奏还是 1.1 请求，等更多字节
                }
                $this->startHttp2($conn);
                $this->handleHttp2Read($conn);
                return;
            }
            $conn->setHandshakeDone(); // 判定为 HTTP/1.1，后续读不再探测
        }

        // WebSocket：首包为 HTTP 握手，完成后转入帧处理
        if ($scheme === 'websocket' && !$conn->isHandshakeDone()) {
            if (!$conn->hasFullHttpRequest()) {
                return;
            }
            $req = $conn->getBuffer();
            if (!WebSocketProtocol::isHandshakeRequest($req)) {
                $this->closeConnection($conn);
                return;
            }
            $conn->sendRaw((string)WebSocketProtocol::handshake($req));
            $conn->setHandshakeDone();
            $conn->clearBuffer();
            return;
        }

        $protoClass = $conn->protocolClass();
        if ($protoClass === null) {
            $this->closeConnection($conn);
            return;
        }

        $isHttp = $scheme === 'http';
        $isWs   = $scheme === 'websocket';

        while (true) {
            $buf = $conn->getBuffer();
            if ($buf === '') {
                break;
            }

            $len = $protoClass::input($buf, $conn);
            if ($len === 0) {
                break; // 需要更多数据
            }
            if ($len === -1) {
                $this->closeConnection($conn);
                return;
            }

            // 常态是「一次读到的就是恰好一条完整报文」，此时整段即为帧、剩余为空，
            // 两次 substr 都只是把同样的字节再拷一遍。
            if ($len === strlen($buf)) {
                $frame = $buf;
                $conn->clearBuffer();
            } else {
                $frame = substr($buf, 0, $len);
                $conn->setBuffer(substr($buf, $len));
            }

            $message = $protoClass::decode($frame, $conn);

            // WebSocket 控制帧由运行时自动处理，保持与 Swoole / Workerman 一致：
            // 对端 ping → 自动回 pong；对端 pong → 静默忽略。
            // 用户 on('message') 只收到应用消息（text / binary / close），无需自己处理保活。
            if ($isWs && is_array($message)) {
                $type = $message['type'] ?? null;
                if ($type === 'ping') {
                    $conn->sendRaw(WebSocketProtocol::encodePong($message['data'] ?? ''));
                    continue;
                }
                if ($type === 'pong') {
                    continue;
                }
                if ($type === 'message') {
                    $reassembled = $this->reassemblyWebSocketFragment($conn, $message);
                    if ($reassembled === null) {
                        // 协议错误：reassemblyWebSocketFragment 已关闭连接
                        return;
                    }
                    if ($reassembled === false) {
                        // 分片尚未收齐，等待更多帧，不向业务派发
                        continue;
                    }
                    $message = $reassembled;
                }
            }

            // Connection 头在 h2c 升级与 keep-alive 两处都要用，是本轮唯一必然被读到的头部。
            // 在这里扫一次往下传，避免同一行报文被反复定向查找。
            $connectionHeader = null;

            if ($isHttp && $message instanceof Request) {
                // gzip / h2c / keep-alive 三处判定都走 Request::rawHeader() 的定向扫描：
                // 只在原始报文里找那一行，不触发整块头部解析。业务不碰请求字段时，
                // 一个请求从头到尾不会产生任何 header 数组。
                // gzip 自动标记按连接求解一次即可：客户端能力在 keep-alive 内恒定，
                // 首请求探测后置位 resolved，后续请求免扫描（小响应占比高时省去大量无效查找）。
                if ($this->gzipEnabled && !$conn->isGzipAutoResolved()) {
                    $conn->setGzipAuto($this->acceptsGzip($message->rawHeader('Accept-Encoding')));
                }

                $connectionHeader = $message->rawHeader('Connection');

                // `Upgrade: h2c` —— 回 101 并把本请求接管为流 1，连接转入 HTTP/2。
                // RFC 7540 §3.2 要求升级请求必须同时带 `Connection: Upgrade, HTTP2-Settings`，
                // 复用上面那一次 Connection 扫描即可把绝大多数普通请求挡在门外，
                // 不必再为 Upgrade / HTTP2-Settings 各扫一遍报文。
                if ($this->http2Enabled
                    && $connectionHeader !== ''
                    && stripos($connectionHeader, 'upgrade') !== false
                    && $this->wantsH2cUpgrade($message)
                ) {
                    $this->upgradeToHttp2($conn, $message);
                    return;
                }
            }

            $this->fireMessage($conn, $message);
            $this->countRequest();

            // 这里只需知道业务有没有在 handler 里主动关掉连接，用状态位判断即可；
            // isAlive() 还会做一次 feof() 流探测，那是每请求都要付出的真实开销。
            if ($conn->isClosed()) {
                $this->closeConnection($conn);
                return;
            }

            if (!$isHttp) {
                continue;
            }

            // HTTP：流式响应（chunked）在 handler 返回后自动补发终止块
            if ($conn->isChunkStarted()) {
                $conn->endChunk();
            }

            // HTTP：按 keep-alive 决定复用还是收尾（Connection 头上面已扫过，直接复用）
            if (!$this->shouldKeepAlive($message, $connectionHeader)) {
                $conn->closeAfterFlush();
                if ($conn->isClosed()) {
                    $this->closeConnection($conn);
                }
                return;
            }
        }
    }

    // ------------------------------------------------------------- HTTP/2

    /**
     * 判断 HTTP/1.1 请求是否请求升级到 h2c（RFC 7540 §3.2）。
     *
     * 三个条件缺一不可：`Upgrade: h2c`、`Connection` 含 upgrade、
     * 携带 `HTTP2-Settings`。不满足就当普通 1.1 请求处理，绝不误升级。
     */
    private function wantsH2cUpgrade(Request $request): bool
    {
        // 快速门禁：升级请求必然带 Upgrade 头，而现实中不带它的请求占绝大多数。
        // rawHeader() 一次 strpos 就能排除，不必为一个几乎从不出现的头解析整块头部。
        $upgrade = $request->rawHeader('Upgrade');
        if ($upgrade === '' || strcasecmp($upgrade, 'h2c') !== 0) {
            return false;
        }

        if (!str_contains(strtolower($request->rawHeader('Connection')), 'upgrade')) {
            return false;
        }

        return $request->rawHeader('HTTP2-Settings') !== '';
    }

    /**
     * 为连接创建 HTTP/2 会话并立刻发出本端 SETTINGS。
     *
     * 服务端 SETTINGS 是「服务端连接前奏」，允许在收到客户端前奏之前就发出，
     * 提前发能省一个 RTT。
     */
    private function startHttp2(NativeConnection $conn): Http2Session
    {
        $session = new Http2Session(
            maxConcurrentStreams: $this->http2MaxConcurrentStreams,
            initialWindowSize: $this->http2InitialWindow,
            maxHeaderListSize: $this->http2MaxHeaderListSize,
        );
        $conn->attachHttp2($session);
        $conn->setHandshakeDone();
        $session->sendLocalSettings();
        $conn->flushHttp2();

        return $session;
    }

    /**
     * 执行 h2c 升级：回 101 → 建会话 → 把触发升级的请求作为流 1 派发。
     */
    private function upgradeToHttp2(NativeConnection $conn, Request $request): void
    {
        $conn->sendRaw("HTTP/1.1 101 Switching Protocols\r\nConnection: Upgrade\r\nUpgrade: h2c\r\n\r\n");

        $session = $this->startHttp2($conn);
        $session->applyUpgradeSettings($request->rawHeader('HTTP2-Settings'));

        $adopted = $session->adoptUpgradedRequest($request->toArray());
        $this->dispatchHttp2Request($conn, $session, $adopted);

        if ($conn->isAlive()) {
            // 101 之后客户端才补发连接前奏，剩余缓冲交给会话
            $this->handleHttp2Read($conn);
        }
    }

    /**
     * HTTP/2 连接的读处理：喂字节 → 拿完整请求 → 逐条派发 → 冲刷输出。
     */
    private function handleHttp2Read(NativeConnection $conn): void
    {
        $session = $conn->http2Session();
        if ($session === null) {
            return;
        }

        $data = $conn->getBuffer();
        if ($data !== '') {
            $conn->clearBuffer();
        }

        try {
            $requests = $session->feed($data);
        } catch (Http2Exception $e) {
            // 连接级错误：GOAWAY 带上最后处理的流 ID，写净后断开
            $session->goaway($e->errorCode(), $e->getMessage());
            $conn->flushHttp2();
            $conn->closeAfterFlush();
            if (!$conn->isAlive()) {
                $this->closeConnection($conn);
            }
            return;
        }

        foreach ($requests as $item) {
            $this->dispatchHttp2Request($conn, $session, $item);
            if (!$conn->isAlive()) {
                $this->closeConnection($conn);
                return;
            }
        }

        $conn->flushHttp2();

        if ($session->isClosed()) {
            $conn->closeAfterFlush();
            if (!$conn->isAlive()) {
                $this->closeConnection($conn);
            }
        }
    }

    /**
     * 把一条 HTTP/2 请求包装成流视图后交给业务 handler。
     *
     * 业务拿到的 `$conn` 是 {@see Http2Stream}，`$request` 结构与 HTTP/1.1 完全一致，
     * 因此同一份 handler 无需任何改动即可同时服务 1.1 与 2。
     *
     * @param array{stream: int, request: array<string, mixed>} $item
     */
    private function dispatchHttp2Request(NativeConnection $conn, Http2Session $session, array $item): void
    {
        $stream = new Http2Stream($conn, $session, $item['stream']);

        if ($this->gzipEnabled && $this->acceptsGzip((string)($item['request']['headers']['accept-encoding'] ?? ''))) {
            $stream->setGzipAuto(true);
        }

        // 与 HTTP/1.1 交付同一个类型：同一份 handler 无需分支即可服务 1.1 与 2
        $this->fireMessage($stream, Request::fromArray($item['request']));
        $this->countRequest();

        // 业务只发了头没发体（beginChunked 风格）时补上结束标记，避免客户端一直等
        if (!$stream->isResponded() && $stream->isChunkStarted()) {
            $stream->endChunk();
        }
    }

    /**
     * WebSocket 分片重组（RFC 6455 §5.4）
     *
     * 单帧消息原样返回；多帧分片（FIN=0 首帧 + 续帧）累积到连接对象，
     * 收到 FIN=1 的续帧后拼成完整消息返回。控制帧（ping/pong/close）
     * 由调用方在外部先行处理，不会进入此处。
     *
     * @return array|false|null 完整消息数组（派发）；false=分片未收齐（等待更多帧）；
     *                           null=协议错误（连接已关闭）
     */
    private function reassemblyWebSocketFragment(NativeConnection $conn, array $message): array|false|null
    {
        $fin    = (int)($message['fin'] ?? 1);
        $opcode = (int)($message['opcode'] ?? WebSocketProtocol::OPCODE_TEXT);

        // 独立完整帧（FIN=1 且非续帧）：分片中途不得插入非续帧
        if ($fin === 1 && $opcode !== WebSocketProtocol::OPCODE_CONTINUATION) {
            if ($conn->isFragmenting()) {
                $this->closeConnection($conn);
                return null;
            }
            return $message;
        }

        // 中间分片（FIN=0）
        if ($fin === 0) {
            if ($opcode === WebSocketProtocol::OPCODE_CONTINUATION) {
                if (!$conn->isFragmenting()) {        // 续帧但无首帧
                    $this->closeConnection($conn);
                    return null;
                }
                $conn->appendFragment($message['data'] ?? '');
            } else {                                   // 首帧（text/binary）
                if ($conn->isFragmenting()) {         // 上一个分片未结束
                    $this->closeConnection($conn);
                    return null;
                }
                $conn->startFragment($opcode, $message['data'] ?? '');
            }
            if ($conn->fragmentSize() > WebSocketProtocol::MAX_PAYLOAD_LENGTH) {
                $this->closeConnection($conn);
                return null;
            }
            return false;                             // 等待更多分片
        }

        // 末帧（FIN=1 且为续帧）
        if (!$conn->isFragmenting()) {
            $this->closeConnection($conn);
            return null;
        }
        $conn->appendFragment($message['data'] ?? '');
        if ($conn->fragmentSize() > WebSocketProtocol::MAX_PAYLOAD_LENGTH) {
            $this->closeConnection($conn);
            return null;
        }
        return $conn->finishFragment();
    }

    /**
     * HTTP/1.1 默认长连接，HTTP/1.0 默认短连接，`Connection` 头显式覆盖。
     */
    /**
     * 判断请求是否接受 gzip 压缩（委托 {@see HttpProtocol::acceptsGzip}）。
     */
    private function acceptsGzip(string $header): bool
    {
        return HttpProtocol::acceptsGzip($header);
    }

    /**
     * @param string|null $connectionHeader 调用方若已扫过 Connection 头可直接传入，避免重复扫描
     */
    private function shouldKeepAlive(mixed $message, ?string $connectionHeader = null): bool
    {
        if (!$this->keepAlive || !$message instanceof Request) {
            return false;
        }

        $value = $connectionHeader ?? $message->rawHeader('Connection');

        if ($value !== '') {
            return stripos($value, 'close') === false;
        }

        // 没带 Connection 头：HTTP/1.1 默认持久连接，只有 1.0 才收尾。
        // isHttp10() 直接比对请求行末尾 8 字节，不为一个版本号触发整行解析。
        return !$message->isHttp10();
    }

    /**
     * 非阻塞完成 TLS 握手；返回 true 表示握手已完成可继续读数据。
     *
     * @param resource $sock
     */
    private function finishSslHandshake($sock, NativeConnection $conn): bool
    {
        $result = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($result === true) {
            $conn->setSslReady();
            $this->fire('connect', $conn);
            return true;
        }
        if ($result === false) {
            $this->closeConnection($conn);
        }
        return false; // 0 表示需要更多数据，继续等待可读
    }

    private function closeConnection(NativeConnection $conn): void
    {
        $id = $conn->id();
        if (!isset($this->connections[$id])) {
            return;
        }
        $sock = $this->connectionSockets[$id] ?? null;
        if ($sock !== null) {
            $this->loop?->offReadable($sock);
            $this->loop?->offWritable($sock);
        }
        unset($this->connections[$id], $this->connectionSockets[$id]);
        $this->fire('close', $conn);
        $conn->close();

        // 优雅关闭期间：所有连接都自然关闭后，提前结束循环，无需等满宽限期。
        if ($this->shuttingDown && $this->connections === []) {
            $this->loop?->stop();
        }
    }

    /** maxRequest 计数：达到阈值后停止事件循环，由 master 拉起新 worker */
    private function countRequest(): void
    {
        if ($this->maxRequest <= 0) {
            return;
        }
        if (++$this->handledRequests >= $this->maxRequest) {
            $this->loop?->stop();
        }
    }

    // ------------------------------------------------------------ master

    private function runMaster(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->shutdownMaster();
        });
        pcntl_signal(SIGINT, function (): void {
            $this->shutdownMaster();
        });
        pcntl_signal(SIGUSR1, function (): void {
            $this->reloadMaster();
        });
        pcntl_signal(SIGCHLD, static function (): void {
            // 子进程状态变更统一由主循环 pcntl_wait 收割
        });

        while ($this->running) {
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                $this->respawn($pid);
                continue;
            }
            usleep(100_000);
        }

        $this->reapAll();
    }

    private function respawn(int $pid): void
    {
        if (isset($this->workerPids[$pid])) {
            $index = $this->workerPids[$pid];
            unset($this->workerPids[$pid]);
            if ($this->running) {
                $this->spawnWorker($index);
            }
            return;
        }
        if (isset($this->taskPids[$pid])) {
            $index = $this->taskPids[$pid];
            unset($this->taskPids[$pid]);
            if ($this->running) {
                $this->spawnTaskWorker($index);
            }
        }
    }

    private function shutdownMaster(): void
    {
        $this->running = false;
        foreach (array_keys($this->workerPids + $this->taskPids) as $pid) {
            @posix_kill($pid, SIGTERM);
        }
    }

    private function reloadMaster(): void
    {
        // worker 收到 SIGUSR1 后停止循环退出，master 在收割时自动拉起新进程
        foreach (array_keys($this->workerPids + $this->taskPids) as $pid) {
            @posix_kill($pid, SIGUSR1);
        }
    }

    /** 停机收尾：等待子进程退出，超时后强杀 */
    private function reapAll(): void
    {
        $deadline = microtime(true) + $this->stopTimeout;
        while (($this->workerPids !== [] || $this->taskPids !== []) && microtime(true) < $deadline) {
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                unset($this->workerPids[$pid], $this->taskPids[$pid]);
                continue;
            }
            usleep(50_000);
        }
        foreach (array_keys($this->workerPids + $this->taskPids) as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        if ($this->pidFile !== null && $this->pidFile !== '' && is_file($this->pidFile)) {
            @unlink($this->pidFile);
        }
    }

    // ------------------------------------------------------------ 套接字

    /**
     * @param array<string, mixed> $listener
     * @return resource
     */
    private function openServerSocket(array $listener, bool $reusePort): mixed
    {
        $scheme = (string)$listener['scheme'];
        $opts   = $listener['options'];
        $ctx    = [];

        if ($scheme === 'unix') {
            $path = (string)$listener['path'];
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
            $address = 'unix://' . $path;
        } else {
            $transport = self::STREAM_TRANSPORT[$scheme] ?? 'tcp';
            $address   = sprintf('%s://%s:%d', $transport, $listener['host'], $listener['port']);

            if ($reusePort && defined('SO_REUSEPORT')) {
                $ctx['socket']['so_reuseport'] = 1;
            }
            // 默认加大半连接队列：默认 backlog（多数系统 128）在瞬时高并发下会丢 SYN，
            // 表现为压测客户端出现连接错误与长尾延迟。
            $ctx['socket']['backlog'] = max(128, (int)($opts['backlog'] ?? 1024));

            // 关闭 Nagle。HTTP 响应基本是「一次写完就等下一个请求」，
            // Nagle 的合并等待只会平白增加 RTT；accept 出来的连接继承此选项。
            if ($transport === 'tcp' && ($opts['tcpNoDelay'] ?? true)) {
                $ctx['socket']['tcp_nodelay'] = true;
            }
        }

        if (isset($opts['ssl']) && is_array($opts['ssl'])) {
            $ctx['ssl'] = $opts['ssl'] + ['verify_peer' => false, 'allow_self_signed' => true];
        }

        $flags = $scheme === 'udp'
            ? STREAM_SERVER_BIND
            : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        $context = $ctx === [] ? null : stream_context_create($ctx);
        $sock    = @stream_socket_server($address, $errno, $errstr, $flags, $context);

        if ($sock === false) {
            throw new \RuntimeException(sprintf(
                'Native 监听失败 %s: %s (%d)',
                $address,
                $errstr !== '' ? $errstr : 'unknown',
                $errno
            ));
        }
        stream_set_blocking($sock, false);

        return $sock;
    }

    /** @return class-string|null */
    private function protocolClassFor(string $scheme): ?string
    {
        return match ($scheme) {
            'http'          => HttpProtocol::class,
            'websocket'     => WebSocketProtocol::class,
            'text'          => TextProtocol::class,
            'frame'         => LengthPrefix::class,
            'tcp',
            'udp',
            'ssl',
            'unix'          => TcpProtocol::class,
            default         => ProtocolFactory::classFor($scheme),
        };
    }

    // ------------------------------------------------------------ 生命周期

    public function stop(bool $graceful = true): void
    {
        if ($this->isWorker || $this->isTaskWorker) {
            $this->loop?->stop();
            return;
        }
        $this->running = false;
        foreach (array_keys($this->workerPids + $this->taskPids) as $pid) {
            @posix_kill($pid, $graceful ? SIGTERM : SIGKILL);
        }
    }

    public function reload(): void
    {
        if ($this->isWorker || $this->isTaskWorker) {
            $this->loop?->stop();
            return;
        }
        if ($this->workerPids === []) {
            throw new RuntimeNotSupportedException('服务尚未启动，无法 reload');
        }
        $this->reloadMaster();
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = true): int
    {
        $id = ++$this->timerSeq;
        // 运行中的 worker 直接落到事件循环，未启动时留待 workerStart 统一注册
        $loopId = $this->loop?->addTimer($interval, $callback, $periodic);
        // 存「本包 ID → 底层 loop timer ID」映射（未启动时为 null），供 delTimer 真正注销
        $this->timers[$id] = $loopId;

        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        $loopId = $this->timers[$timerId];
        unset($this->timers[$timerId]);

        // 底层事件循环的定时器必须显式移除，否则仍会在下一轮触发
        if ($loopId !== null) {
            $this->loop?->delTimer($loopId);
        }

        return true;
    }

    public function stats(): array
    {
        return parent::stats() + [
            'model'        => 'master-worker (prefork)',
            'workers'      => $this->isWorker ? 1 : count($this->workerPids),
            'task_workers' => $this->isWorker ? $this->taskWorkerCount : count($this->taskPids),
            'worker_id'    => $this->workerId,
            'loop'         => $this->loop !== null ? $this->loop::name() : LoopFactory::preferred(),
            'connections'  => count($this->connections),
            'requests'     => $this->handledRequests,
            'keep_alive'   => $this->keepAlive,
        ];
    }
}
