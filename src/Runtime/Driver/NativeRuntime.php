<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Protocol\LengthPrefix;
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
        return ['tcp', 'http', 'websocket', 'text', 'frame', 'udp', 'unix', 'ssl'];
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

    // ------------------------------------------------------------- 启动

    public function start(): void
    {
        $listener = $this->requireListener();
        $opts     = $listener['options'];

        $this->reusePort       = (bool)($opts['reusePort'] ?? false);
        $this->workerCount     = max(1, (int)($opts['workers'] ?? 4));
        $this->taskWorkerCount = max(0, (int)($opts['taskWorkers'] ?? 0));
        $this->maxRequest      = max(0, (int)($opts['maxRequest'] ?? 0));
        $this->stopTimeout     = max(1, (int)($opts['stopTimeout'] ?? 5));
        $this->heartbeat       = max(0, (int)($opts['heartbeat'] ?? 0));
        $this->keepAlive       = (bool)($opts['keepAlive'] ?? true);
        $this->maxSendBuffer   = max(65536, (int)($opts['maxSendBuffer'] ?? NativeConnection::DEFAULT_MAX_SEND_BUFFER));
        $this->serverName      = (string)($opts['name'] ?? 'kode-process');
        $this->pidFile         = isset($opts['pidFile']) ? (string)$opts['pidFile'] : null;

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

        foreach ($entries as $entry) {
            $sock     = $entry['socket'];
            $listener = $entry['listener'];
            stream_set_blocking($sock, false);

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
        if ($this->heartbeat > 0) {
            $loop->addTimer(min(10.0, (float)$this->heartbeat), function (): void {
                $this->recycleIdleConnections();
            }, true);
        }

        $stop = static function () use ($loop): void {
            $loop->stop();
        };
        $loop->onSignal(SIGTERM, $stop);
        $loop->onSignal(SIGUSR1, $stop);
        $loop->onSignal(SIGINT, $stop);

        $this->fire('workerStart', $workerId);
        $loop->run();

        foreach ($this->connections as $conn) {
            $conn->close();
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
        $deadline = microtime(true) - $this->heartbeat;
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
        stream_set_blocking($connSock, false);

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
        $this->fire('message', $conn, $data);
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

            $frame = substr($buf, 0, $len);
            $conn->setBuffer(substr($buf, $len));

            $message = $protoClass::decode($frame, $conn);

            // WebSocket 控制帧由运行时自动处理，保持与 Swoole / Workerman 一致：
            // 对端 ping → 自动回 pong；对端 pong → 静默忽略。
            // 用户 on('message') 只收到应用消息（text / binary / close），无需自己处理保活。
            if ($scheme === 'websocket' && is_array($message)) {
                $type = $message['type'] ?? null;
                if ($type === 'ping') {
                    $conn->sendRaw(WebSocketProtocol::encodePong($message['data'] ?? ''));
                    continue;
                }
                if ($type === 'pong') {
                    continue;
                }
            }

            $this->fire('message', $conn, $message);
            $this->countRequest();

            if (!$conn->isAlive()) {
                $this->closeConnection($conn);
                return;
            }

            // HTTP：按 keep-alive 决定复用还是收尾
            if ($scheme === 'http' && !$this->shouldKeepAlive($message)) {
                $conn->closeAfterFlush();
                if (!$conn->isAlive()) {
                    $this->closeConnection($conn);
                }
                return;
            }
        }
    }

    /**
     * HTTP/1.1 默认长连接，HTTP/1.0 默认短连接，`Connection` 头显式覆盖。
     */
    private function shouldKeepAlive(mixed $message): bool
    {
        if (!$this->keepAlive || !is_array($message)) {
            return false;
        }

        $headers = $message['headers'] ?? [];
        $value   = '';
        if (is_array($headers)) {
            foreach ($headers as $name => $v) {
                if (strcasecmp((string)$name, 'Connection') === 0) {
                    $value = strtolower((string)$v);
                    break;
                }
            }
        }

        if ($value !== '') {
            return !str_contains($value, 'close');
        }

        return ($message['protocol'] ?? 'HTTP/1.1') !== 'HTTP/1.0';
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
            if (!empty($opts['backlog'])) {
                $ctx['socket']['backlog'] = (int)$opts['backlog'];
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
            default         => null,
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
