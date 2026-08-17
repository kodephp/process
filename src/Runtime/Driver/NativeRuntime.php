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

    /**
     * 头部累积阶段 recvBuffer 硬上限（Slowloris 防护）。
     *
     * 一旦连接尚未收齐完整头块（`\r\n\r\n` 未出现）而缓冲已超过此值，立即断开——
     * 否则客户端以极低速率持续发送不含完整头块的数据即可让 recvBuffer 无限增长，
     * 单连接即可打爆 worker 内存（千连接级联即内存耗尽）。
     *
     * 该上限独立于 {@see HttpProtocol::MAX_LENGTH}（整请求 10MB，含合法大 body）：
     * 头块定型后进入 {@see HttpProtocol::input()} 的常规长度校验，大 body 不受影响。
     * WebSocket 握手首包同源受此上限约束（原 16KB 内联判断统一收敛到此常量）。
     */
    private const int MAX_HEADER_BUFFER = 65536;

    /**
     * UDP 数据报理论最大长度（IPv4 65535 − 20 IP 头 − 8 UDP 头 = 65507）。
     *
     * `receiveUdp()` 用此值作为 `stream_socket_recvfrom` 的缓冲区上限，
     * 避免更大的数据报被静默截断（recvfrom 不会报错，只会返回被截断的前段字节）。
     */
    private const int UDP_MAX_PACKET = 65507;

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

    /**
     * 慢读超时（秒）。默认 0 = 关闭（零行为变更）。
     * 打开后，任何「不完整请求滞留超过该时长」的连接会被回收——直接掐断滴流型 Slowloris
     * （客户端以极低速率喂数据、始终凑不齐一条完整请求却能让心跳判定「活跃」而永不被回收）。
     * 与 {@see self::MAX_HEADER_BUFFER}（头部累积体积上限）互补：体积上限挡「单连接缓冲撑爆」，
     * 时间上限挡「极慢滴流长期占连接」。详见 {@see recycleSlowReaders()}。
     */
    private int $readTimeout = 0;

    private bool $keepAlive = true;

    private bool $gzipEnabled = true;

    /** h2c（明文 HTTP/2）总开关，对 http:// 监听生效 */
    private bool $http2Enabled = true;

    private int $http2MaxConcurrentStreams = 128;

    private int $http2InitialWindow = 1048576;

    private int $http2MaxHeaderListSize = 65536;

    /** 单条 HTTP/2 流允许的最大请求体字节数（与 HTTP/1.1 MAX_LENGTH 对称的内存防护） */
    private int $http2MaxRequestBody = 10485760;

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

    /** @var array<int, string> 任务管道写缓冲：非阻塞 socketpair 单次 fwrite 可能只写出部分字节，剩余在此续写 */
    private array $taskWriteBuffers = [];

    private int $taskRoundRobin = 0;

    /**
     * worker / task 进程崩溃记录：index => ['at' => 最近退出时刻, 'count' => 秒级内连续退出次数]。
     * 用于崩溃退避，避免启动期异常触发 100% CPU 的 fork 风暴。
     *
     * @var array<int, array{at: float, count: int}>
     */
    private array $crashStats = [];

    /**
     * 在 worker 启动（事件循环就绪）之前通过 {@see addTimer()} 注册的定时器。
     * 这些定时器拿不到底层 loop id，先暂存于此，待 {@see runWorker()} 重放。
     *
     * @var array<int, array{interval: float, callback: callable, periodic: bool}>
     */
    private array $pendingTimers = [];

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
        $this->readTimeout     = max(0, (int)($opts['readTimeout'] ?? 0));
        $this->keepAlive       = (bool)($opts['keepAlive'] ?? true);
        $this->gzipEnabled     = (bool)($opts['gzip'] ?? true);

        // HTTP/2（h2c）：默认开启，与 HTTP/1.1 在同一端口自动协商，
        // 客户端不支持 h2 时完全不受影响（既不改握手也不加解析开销）。
        $this->http2Enabled              = (bool)($opts['http2'] ?? true);
        $this->http2MaxConcurrentStreams = max(1, (int)($opts['http2MaxConcurrentStreams'] ?? 128));
        $this->http2InitialWindow        = max(Frame::DEFAULT_WINDOW_SIZE, (int)($opts['http2InitialWindow'] ?? 1048576));
        $this->http2MaxHeaderListSize    = max(0, (int)($opts['http2MaxHeaderListSize'] ?? Http2Session::DEFAULT_MAX_HEADER_LIST_SIZE));
        $this->http2MaxRequestBody       = max(0, (int)($opts['http2MaxRequestBody'] ?? Http2Session::DEFAULT_MAX_REQUEST_BODY));
        $this->gracefulShutdownTimeout   = max(0, (int)($opts['gracefulShutdownTimeout'] ?? 3));
        // master 的停机总超时必须覆盖 worker 的优雅宽限期，否则会在宽限期内 SIGKILL
        // 在途请求（gracefulShutdownTimeout=30 而 stopTimeout=5 时必现）。取两者较大值。
        $this->stopTimeout                = max($this->stopTimeout, $this->gracefulShutdownTimeout + 1);

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

        // unix 域套接字不支持 SO_REUSEPORT：每个 worker 各自 bind 会互删 socket 文件，
        // 只剩最后一个 worker 能收连接，其余持有已被 unlink 的孤儿 socket。
        // 故 unix 统一走 master 预开、子进程继承的共享 socket（与 !reusePort 同路径）。
        foreach ($this->listeners as $i => $l) {
            if (!$this->reusePort || $l['scheme'] === 'unix') {
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
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        }
        if ($pid < 0) {
            throw new \RuntimeException('daemonize 失败：pcntl_fork() 返回 -1');
        }
        if (posix_setsid() === -1) {
            throw new \RuntimeException('daemonize 失败：posix_setsid() 返回 -1');
        }
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        }
        if ($pid < 0) {
            throw new \RuntimeException('daemonize 失败：二次 pcntl_fork() 返回 -1');
        }

        // 脱离终端后固定工作目录（否则守护进程会钉住启动时的 cwd，阻止卸载文件系统），
        // 并把 stdin 重定向到 /dev/null，避免误读已关闭的终端。
        chdir('/');
        umask(0);

        $target = $logFile !== '' ? $logFile : '/dev/null';
        global $STDIN, $STDOUT, $STDERR;
        @fclose(STDIN);
        @fclose(STDOUT);
        @fclose(STDERR);
        $STDIN  = fopen('/dev/null', 'r');
        $STDOUT = fopen($target, 'a');
        $STDERR = fopen($target, 'a');
    }

    private function writePidFile(): void
    {
        $file = $this->resolvePidFile();
        if ($file === null) {
            return;
        }
        @file_put_contents($file, (string)posix_getpid());
    }

    /** PID 文件路径：显式配置优先，否则回退到 `bin/kode` 通过环境变量透传的标准路径 */
    private function resolvePidFile(): ?string
    {
        $file = $this->pidFile;
        if ($file === null || $file === '') {
            $env = getenv('KODE_PID_FILE');
            if ($env === false || $env === '') {
                return null;
            }
            $file = $env;
        }
        return $file;
    }

    private function setProcessTitle(string $role): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }
        @cli_set_process_title(sprintf('%s: %s', $this->serverName, $role));
    }

    /** 按需降权（以 root 启动、以普通用户运行）。任一环节失败立即抛错，绝不以 root 静默继续。 */
    private function dropPrivileges(): void
    {
        $opts = $this->primaryOptions();
        $user = $opts['user'] ?? null;
        if ($user === null || posix_getuid() !== 0) {
            return;
        }

        $ui = posix_getpwnam((string)$user);
        if ($ui === false) {
            throw new \RuntimeException(sprintf('降权失败：未知用户 "%s"', $user));
        }
        $uid = (int)$ui['uid'];
        $gid = (int)($ui['gid'] ?? 0);

        $group = $opts['group'] ?? null;
        if (is_string($group) && $group !== '') {
            $gi = posix_getgrnam($group);
            if ($gi === false) {
                throw new \RuntimeException(sprintf('降权失败：未知用户组 "%s"', $group));
            }
            $gid = (int)$gi['gid'];
        }

        // 顺序：initgroups → setgid → setuid。先清掉 root 的附加组，再设组、设用户。
        if (!posix_initgroups((string)$user, $gid)) {
            throw new \RuntimeException('降权失败：posix_initgroups() 返回 false');
        }
        if (!posix_setgid($gid)) {
            throw new \RuntimeException(sprintf('降权失败：posix_setgid(%d) 返回 false', $gid));
        }
        if (!posix_setuid($uid)) {
            throw new \RuntimeException(sprintf('降权失败：posix_setuid(%d) 返回 false', $uid));
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

        // 非阻塞 socketpair 单次 fwrite 可能只写出部分字节（载荷 > 内核缓冲即被截断），
        // 直接按返回值判成功会漏发，导致对端按定长帧解析时后续所有报文错位。
        // 改为写入缓冲 + 可写事件续写，保证整帧送达。
        $this->bufferPipeWrite($pipe, self::packMessage($data));

        return true;
    }

    /**
     * 把数据写入任务管道：先尝试直接写，未写完的字节进 {@see taskWriteBuffers}，
     * 注册 onWritable 在可写时续写，直到整帧发完。
     *
     * @param resource $pipe
     */
    private function bufferPipeWrite($pipe, string $data): void
    {
        $key      = (int)$pipe;
        $wasEmpty = !isset($this->taskWriteBuffers[$key]);
        $pending  = ($this->taskWriteBuffers[$key] ?? '') . $data;

        $written = @fwrite($pipe, $pending);
        if ($written === false || $written === 0) {
            $this->taskWriteBuffers[$key] = $pending;
            if ($wasEmpty) {
                $this->loop?->onWritable($pipe, fn($p) => $this->flushPipeWrite($p, $key));
            }
            return;
        }

        $rest = substr($pending, $written);
        if ($rest === '') {
            unset($this->taskWriteBuffers[$key]);
            if (!$wasEmpty) {
                $this->loop?->offWritable($pipe);
            }
            return;
        }

        $this->taskWriteBuffers[$key] = $rest;
        if ($wasEmpty) {
            $this->loop?->onWritable($pipe, fn($p) => $this->flushPipeWrite($p, $key));
        }
    }

    /**
     * onWritable 回调：把缓冲里剩余字节续写出去，写净后注销可写监听。
     *
     * @param resource $pipe
     */
    private function flushPipeWrite($pipe, int $key): void
    {
        $pending = $this->taskWriteBuffers[$key] ?? '';
        if ($pending === '') {
            $this->loop?->offWritable($pipe);
            return;
        }

        $written = @fwrite($pipe, $pending);
        if ($written === false || $written === 0) {
            return; // 暂时仍不可写，等下一次可写事件
        }

        $rest = substr($pending, $written);
        if ($rest === '') {
            unset($this->taskWriteBuffers[$key]);
            $this->loop?->offWritable($pipe);
        } else {
            $this->taskWriteBuffers[$key] = $rest;
        }
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
            // 重置从 master 继承的信号处理器与异步信号开关，避免 fork 到 runWorker
            // 注册自身处理器之前这扇窗口内，子进程以 master 语义向兄弟进程广播信号。
            pcntl_async_signals(false);
            foreach ([SIGTERM, SIGINT, SIGUSR1, SIGCHLD] as $s) {
                pcntl_signal($s, SIG_DFL);
            }
            $entries = [];
            foreach ($this->listeners as $i => $l) {
                $shared  = !$this->reusePort || $l['scheme'] === 'unix';
                $socket  = $shared ? $this->sharedSockets[$i] : $this->openServerSocket($l, true);
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

        // 重放 start() 之前预注册的定时器（此时 loop 已就绪）
        foreach ($this->pendingTimers as $id => $t) {
            $this->timers[$id] = $loop->addTimer($t['interval'], $t['callback'], $t['periodic']);
        }
        $this->pendingTimers = [];
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

        // 慢读回收（滴流型 Slowloris 防护）：readTimeout=0 时不创建定时器、零行为变更。
        // 周期取「不超过 5s 且不超过超时本身的 1/2」，确保超时判定在合理粒度内及时触发。
        if ($this->readTimeout > 0) {
            $loop->addTimer(min(5.0, (float)$this->readTimeout / 2), function (): void {
                $this->recycleSlowReaders();
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
                        $this->bufferPipeWrite($p, self::packMessage($result));
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

    /**
     * 慢读回收：关闭「不完整请求滞留超过 readTimeout 秒」的连接（滴流型 Slowloris 防护）。
     *
     * 与 {@see recycleIdleConnections()}（心跳，回收完全无读写的空闲连接）正交：
     * 滴流型攻击每几十秒发 1 字节即可让心跳判定「活跃」而永不被回收，但它始终无法
     * 凑齐一条完整请求。本方法只看「不完整请求滞留时长」，直接掐断这种占着连接、缓慢喂数据的连接。
     *
     * 仅当 {@see $readTimeout} > 0 时才有定时器驱动本方法；关闭时永不触发，零行为变更。
     * 标记点由连接在读循环里维护（见 {@see NativeConnection::markPartial()} /
     * {@see NativeConnection::clearPartial()}）：缓冲里留有凑不齐完整请求的字节即标记滞留，
     * 请求完成 / 缓冲清空即清除。
     */
    private function recycleSlowReaders(): void
    {
        $deadline = microtime(true) - $this->readTimeout;
        foreach ($this->connections as $conn) {
            $since = $conn->partialSince();
            if ($since > 0.0 && $since < $deadline) {
                $this->closeConnection($conn);
            }
        }
    }

    // -------------------------------------------------------- 连接与收发

    /**
     * @param resource             $serverSock
     * @param array<string, mixed> $listener
     */
    /**
     * @param resource             $serverSock
     * @param array<string, mixed> $listener
     */
    private function accept($serverSock, array $listener): void
    {
        // 一次可读事件内**循环排空**所有挂起的连接：底层事件循环在边缘触发语义下
        // （ev / event 扩展的 EvLoop 等）只在可读状态变化时才回调一次，若不循环
        // accept 直到 EAGAIN，突发连接里除首个外其余会永远等不到下一次回调而被「搁置」，
        // 表现为并发 connect 部分被拒；即便水平触发（stream_select 兜底），循环排空也能
        // 显著降低事件往返开销。这与 Workerman / Swoole 的 while-accept-until-EAGAIN 范式一致。
        do {
            $peerName = '';
            $connSock = @stream_socket_accept($serverSock, 0, $peerName);
            if ($connSock === false) {
                break; // EAGAIN：队列已空（或 SO_REUSEPORT 下本 worker 无可抢连接）
            }
            $this->registerAcceptedConnection($connSock, $listener, $peerName);
        } while (true);
    }

    /**
     * 完成单个已 accept 连接的套接字调优与连接对象装配。
     *
     * @param resource             $connSock
     * @param array<string, mixed> $listener
     * @param string               $peerName
     */
    private function registerAcceptedConnection($connSock, array $listener, string $peerName): void
    {
        $this->tuneSocket($connSock);

        $scheme = (string)$listener['scheme'];
        $conn   = new NativeConnection(
            $connSock,
            $peerName,
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
    /**
     * 接收 UDP 数据报并派发。
     *
     * 一次可读事件内**循环排空**所有挂起的数据报：底层事件循环在边缘触发语义下
     * （ev 扩展的 EvLoop 等）只在可读状态变化时才回调一次，若不循环 recvfrom 直到
     * EAGAIN，排队的数据报会永远等不到下一次回调而**丢包**；即便水平触发，循环排空
     * 也能显著降低突发流量下的事件往返开销。
     *
     * 缓冲区上限用 {@see self::UDP_MAX_PACKET}（65507），覆盖 UDP 数据报理论最大值，
     * 避免更大的包被 `recvfrom` 静默截断。
     *
     * @param resource             $serverSock
     * @param array<string, mixed> $listener
     */
    private function receiveUdp($serverSock, array $listener): void
    {
        $protocolClass = $this->protocolClassFor((string)$listener['scheme']);

        do {
            $peer = '';
            $data = @stream_socket_recvfrom($serverSock, self::UDP_MAX_PACKET, 0, $peer);
            if ($data === false) {
                break; // EAGAIN 或读取出错，无更多数据报
            }
            if ($data === '') {
                continue; // 空数据报：跳过但不终止循环，后面可能还有包
            }

            $conn = new NativeConnection(
                $serverSock,
                $peer,
                $protocolClass,
                $this->loop,
                $peer,
                $this->maxSendBuffer
            );
            $this->fireMessage($conn, $data);
            $this->countRequest();
        } while (true);
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
                $conn->markPartial(); // 不足 4 字节、请求头必不完整，标记慢读滞留
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
            // 握手前的字节不走 HttpProtocol::input() 的长度上限，客户端持续发送
            // 不含 \r\n\r\n 的数据即可无限增长 recvBuffer → 单连接打爆 worker。
            if (strlen($conn->getBuffer()) > self::MAX_HEADER_BUFFER) {
                $this->closeConnection($conn);
                return;
            }
            if (!$conn->hasFullHttpRequest()) {
                $conn->markPartial(); // 握手头未收齐，标记慢读滞留
                return;
            }
            $buf        = $conn->getBuffer();
            $headerEnd  = strpos($buf, "\r\n\r\n");
            $req        = $headerEnd === false ? $buf : substr($buf, 0, $headerEnd + 4);
            if (!WebSocketProtocol::isHandshakeRequest($req)) {
                $this->closeConnection($conn);
                return;
            }
            $conn->sendRaw((string)WebSocketProtocol::handshake($req));
            $conn->setHandshakeDone();
            // 保留升级请求之后管道过来的首帧（部分客户端把 upgrade 与第一帧合并发送），
            // 不能整体 clearBuffer 否则会丢帧。
            $conn->setBuffer($headerEnd === false ? '' : substr($buf, $headerEnd + 4));
            // 不 return：握手请求与首帧可能在同一次 fread 里被整段读入，若直接返回，
            // 遗留在缓冲里的首帧要等到下一次「可读事件」才会被处理——而此时 socket 已无可读
            // 数据、事件永不触发，帧被永久搁置。落到下方帧处理循环立即消费即可。
        }

        $protoClass = $conn->protocolClass();
        if ($protoClass === null) {
            $this->closeConnection($conn);
            return;
        }

        $isHttp = $scheme === 'http';
        $isWs   = $scheme === 'websocket';

        // 头部累积阶段硬上限（Slowloris 防护，见 MAX_HEADER_BUFFER 注释）：
        // 头块未定型（未出现 \r\n\r\n）而缓冲已超过上限立即断开。仅作用于 HTTP 请求头——
        // WebSocket 帧与 HTTP 请求体由各自的 input() 长度校验兜底，不在此限。
        if ($isHttp && !$conn->hasFullHttpRequest()
            && strlen($conn->getBuffer()) > self::MAX_HEADER_BUFFER) {
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

            $ok = $this->fireMessage($conn, $message);
            $this->countRequest();

            // handler 抛异常已被 error 处理器兜底：连接进入不可恢复状态
            // （可能已写出半截响应），必须主动断开，避免半响应挂住客户端、
            // 连接泄漏在连接表直到心跳超时回收。
            if (!$ok) {
                $this->closeConnection($conn);
                return;
            }

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

        // 循环退出：缓冲已清空（请求完成 / keep-alive 等待下一请求）→ 清除慢读标记；
        // 缓冲里还留着凑不齐完整请求的字节 → 标记滞留，交由 recycleSlowReaders() 计时回收。
        if ($conn->getBuffer() === '') {
            $conn->clearPartial();
        } else {
            $conn->markPartial();
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
            maxRequestBodySize: $this->http2MaxRequestBody,
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
        } catch (\Throwable $e) {
            // 任意解码异常（含 HPACK/Huffman 抛出的 ArithmeticError 等）都必须被兜住，
            // 否则会穿透事件循环、静默打死整个 worker 进程，且无任何日志。
            // 连接级错误：GOAWAY 带上最后处理的流 ID，写净后断开。
            if ($e instanceof Http2Exception) {
                $session->goaway($e->errorCode(), $e->getMessage());
            } else {
                $session->goaway(Frame::ERROR_INTERNAL, 'internal error');
                @error_log(sprintf('[kode] HTTP/2 decode error on conn #%d: %s', $conn->id(), $e->getMessage()));
            }
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
        // `bin/kode reload` 发送 SIGHUP（文档约定「kill -HUP = 平滑重载」）。
        // 若不注册，SIGHUP 默认动作为 Term，会把 master 直接打死、worker 变孤儿。
        pcntl_signal(SIGHUP, function (): void {
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
                $this->respawnWithBackoff($index, 'worker');
            }
            return;
        }
        if (isset($this->taskPids[$pid])) {
            $index = $this->taskPids[$pid];
            unset($this->taskPids[$pid]);
            if ($this->running) {
                $this->respawnWithBackoff($index, 'task');
            }
        }
    }

    /**
     * 带退避的进程重生：秒级内连续崩溃时指数退避，连续超过上限判定启动失败并停止 master，
     * 避免「启动即崩 → 满速 refork → CPU 100%」的死循环。
     */
    private function respawnWithBackoff(int $index, string $kind): void
    {
        $now   = microtime(true);
        $crash = $this->crashStats[$index] ?? ['at' => 0.0, 'count' => 0];

        if ($now - $crash['at'] < 1.0) {
            $crash['count']++;
        } else {
            $crash['count'] = 1;
        }
        $crash['at'] = $now;
        $this->crashStats[$index] = $crash;

        if ($crash['count'] > 10) {
            @error_log(sprintf(
                '[kode] %s #%d 秒级内连续崩溃 %d 次，判定启动失败，停止 master',
                $kind,
                $index,
                $crash['count']
            ));
            $this->running = false;

            return;
        }

        if ($crash['count'] > 1) {
            usleep((int)min(1_000_000, 100_000 * (2 ** ($crash['count'] - 1))));
        }

        if ($kind === 'worker') {
            $this->spawnWorker($index);
        } else {
            $this->spawnTaskWorker($index);
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
        $pidFile = $this->resolvePidFile();
        if ($pidFile !== null && is_file($pidFile)) {
            @unlink($pidFile);
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
        // 运行中的 worker 直接落到事件循环；start() 之前调用则暂存，待 runWorker() 重放。
        // 否则 $this->loop 为 null，$this->timers[$id] 会被写成 null，
        // runWorker() 遍历时把 null 当成底层 timer id 解包 → TypeError → worker 启动即崩、master 满速 refork。
        if ($this->loop === null) {
            $this->pendingTimers[$id] = [
                'interval' => $interval,
                'callback' => $callback,
                'periodic' => $periodic,
            ];
            $this->timers[$id] = null;

            return $id;
        }
        $loopId                  = $this->loop->addTimer($interval, $callback, $periodic);
        $this->timers[$id] = $loopId;

        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        // 注意：尚未启动的定时器在 $timers 里存的是 null（待重放），
        // 因此必须用 array_key_exists 而非 isset（isset(null) === false）。
        if (!array_key_exists($timerId, $this->timers) && !isset($this->pendingTimers[$timerId])) {
            return false;
        }
        // 尚未启动：从待重放队列移除即可
        if (isset($this->pendingTimers[$timerId])) {
            unset($this->pendingTimers[$timerId], $this->timers[$timerId]);

            return true;
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
