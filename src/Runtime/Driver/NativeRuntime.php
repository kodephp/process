<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Protocol\ProtocolInterface;
use Kode\Process\Protocol\TcpProtocol;
use Kode\Process\Protocol\TextProtocol;
use Kode\Process\Protocol\WebSocketProtocol;
use Kode\Process\Reactor\SelectLoop;
use Kode\Process\Runtime\AbstractRuntime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;
use Kode\Process\Version;

/**
 * 自研（Native）运行时：纯 PHP 的 master-worker 多进程服务器。
 *
 * 设计目标（依据 docs/gate-report.md）：
 *  - 零扩展依赖（仅用 pcntl / posix，事件循环用 {@see SelectLoop}），
 *    任何 PHP 8.3+ CLI 环境都能跑，与 Swoole / Workerman 在 {@see \Kode\Process\Runtime\RuntimeInterface}
 *    下无缝切换。
 *  - 功能覆盖更广、实现更可控：标准 prefork 模型、worker 监督重启、平滑重载、
 *    优雅停机、信号管理，全部由本包自研掌控，不依赖第三方运行时。
 *  - 吞吐目标：持平 Workerman（Amdahl 上限 +14.9%，30% 门槛已证明数学不可达）。
 *
 * 进程模型：master 进程 fork 出 N 个 worker，worker 各自运行 {@see SelectLoop} 事件循环，
 * 通过继承/SO_REUSEPORT 共享监听套接字，连接处理复用本包 Protocol 协议系统。
 */
final class NativeRuntime extends AbstractRuntime
{
    /**
     * 逻辑协议 → 真实流传输。http / websocket / text 都是跑在 TCP 之上的应用层协议，
     * 监听套接字统一用 tcp 传输，具体协议由 Protocol 系统在连接层解析。
     *
     * @var array<string, string>
     */
    private const STREAM_TRANSPORT = [
        'tcp'       => 'tcp',
        'http'      => 'tcp',
        'websocket' => 'tcp',
        'text'      => 'tcp',
        'unix'      => 'unix',
        'ssl'       => 'ssl',
    ];

    /** @var array<int, resource> 非 reuseport 模式下 master 预开的监听套接字（子进程继承） */
    private array $sharedSockets = [];

    /** @var array<int, int> master 维护的 worker PID → 序号 */
    private array $workerPids = [];

    private int $workerIndex = 0;

    private bool $reusePort = false;

    /** @var list<array{scheme:string,host:string,port:int,path:string,options:array<string,mixed>}> */
    private array $listenerSnapshot = [];

    private bool $isMaster = false;

    private bool $isWorker = false;

    private int $workerId = 0;

    private ?SelectLoop $workerLoop = null;

    /** @var array<int, NativeConnection> 当前 worker 持有的连接（key = (int) socket） */
    private array $workerConnections = [];

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
        return ['tcp', 'http', 'websocket', 'text', 'unix'];
    }

    public function capabilities(): array
    {
        return [
            Capability::SharedTable,
            Capability::UnixSocket,
            Capability::HotReload,
            Capability::ReusePort,
            Capability::WebSocket,
            Capability::Timer,
            Capability::AsyncIo,
        ];
    }

    public function start(): void
    {
        $listener = $this->requireListener();
        $this->reusePort        = (bool)($listener['options']['reusePort'] ?? false);
        $workerCount            = max(1, (int)($listener['options']['workers'] ?? 4));
        $this->listenerSnapshot = $this->listeners;
        $this->running          = true;

        if (!$this->reusePort) {
            foreach ($this->listeners as $i => $l) {
                $this->sharedSockets[$i] = $this->openServerSocket($l, false);
            }
        }

        $this->workerIndex = 0;
        for ($i = 0; $i < $workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->runMaster();
    }

    private function spawnWorker(int $index): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            return;
        }
        if ($pid === 0) {
            $entries = [];
            foreach ($this->listeners as $i => $l) {
                $socket   = $this->reusePort ? $this->openServerSocket($l, true) : $this->sharedSockets[$i];
                $entries[] = ['socket' => $socket, 'listener' => $l];
            }
            $this->runWorker($index, $entries);
            exit(0);
        }
        $this->workerPids[$pid] = $index;
    }

    private function runWorker(int $workerId, array $entries): void
    {
        $this->isWorker = true;
        $this->workerId = $workerId;

        pcntl_async_signals(true);
        $loop = new SelectLoop();
        $this->workerLoop = $loop;

        foreach ($entries as $entry) {
            $sock = $entry['socket'];
            $listener = $entry['listener'];
            stream_set_blocking($sock, false);
            $loop->onReadable($sock, function ($s) use ($loop, $listener): void {
                $this->accept($s, $loop, $listener);
            });
        }

        foreach ($this->timers as $t) {
            $loop->addTimer($t['interval'], $t['callback'], $t['periodic']);
        }

        $loop->onSignal(SIGTERM, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->onSignal(SIGUSR1, static function () use ($loop): void {
            $loop->stop();
        });

        $this->fire('workerStart', $workerId);
        $loop->run();

        foreach ($this->workerConnections as $conn) {
            $conn->close();
        }
        $this->workerConnections = [];
        $this->fire('workerStop', $workerId);
        $this->workerLoop = null;
    }

    private function accept($serverSock, SelectLoop $loop, array $listener): void
    {
        $connSock = @stream_socket_accept($serverSock, 0, $peerName);
        if ($connSock === false) {
            return; // 惊群：其它 worker 已抢先 accept
        }
        stream_set_blocking($connSock, false);

        $protoClass = $this->protocolClassFor($listener['scheme']);
        $conn = new NativeConnection($connSock, (string)($peerName ?? ''), $protoClass);
        $id = (int)$connSock;
        $this->workerConnections[$id] = $conn;

        $loop->onReadable($connSock, function ($s) use ($loop, $conn, $listener): void {
            $this->handleClientRead($s, $conn, $loop, $listener);
        });

        $this->fire('connect', $conn);
    }

    private function handleClientRead($clientSock, NativeConnection $conn, SelectLoop $loop, array $listener): void
    {
        $data = @fread($clientSock, 65535);
        if ($data === false || $data === '') {
            $this->closeClient($clientSock, $conn, $loop);
            return;
        }
        $conn->appendBuffer($data);

        $scheme = $listener['scheme'];

        // WebSocket：首包为 HTTP 握手，完成后转入帧处理
        if ($scheme === 'websocket' && !$conn->isHandshakeDone()) {
            if (!$conn->hasFullHttpRequest()) {
                return;
            }
            $req = $conn->getBuffer();
            if (!WebSocketProtocol::isHandshakeRequest($req)) {
                $this->closeClient($clientSock, $conn, $loop);
                return;
            }
            $conn->sendRaw((string)WebSocketProtocol::handshake($req));
            $conn->setHandshakeDone();
            $conn->clearBuffer();
            return;
        }

        $protoClass = $conn->protocolClass();
        if ($protoClass === null) {
            $this->closeClient($clientSock, $conn, $loop);
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
                $this->closeClient($clientSock, $conn, $loop);
                return;
            }
            $frame  = substr($buf, 0, $len);
            $conn->setBuffer(substr($buf, $len));
            $message = $protoClass::decode($frame, $conn);
            $this->fire('message', $conn, $message);
            if (!$conn->isAlive()) {
                $this->closeClient($clientSock, $conn, $loop);
                return;
            }
        }

        // HTTP 请求默认按请求/响应一次性处理，处理完即关闭（keep-alive 后续增强）
        if ($scheme === 'http') {
            $this->closeClient($clientSock, $conn, $loop);
        }
    }

    private function closeClient($sock, NativeConnection $conn, SelectLoop $loop): void
    {
        $id = (int)$sock;
        if (!isset($this->workerConnections[$id])) {
            return;
        }
        $loop->offReadable($sock);
        unset($this->workerConnections[$id]);
        $this->fire('close', $conn);
        $conn->close();
    }

    private function runMaster(): void
    {
        $this->isMaster = true;
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
            // 子进程状态变更由主循环 pcntl_wait 处理
        });

        while ($this->running) {
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                unset($this->workerPids[$pid]);
                if ($this->running) {
                    $this->spawnWorker($this->workerIndex++);
                }
            }
            usleep(200_000);
        }

        // 收尾：确保残留 worker 已退出
        foreach (array_keys($this->workerPids) as $p) {
            posix_kill($p, SIGTERM);
        }
        $deadline = time() + 5;
        while ($this->workerPids !== [] && time() < $deadline) {
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                unset($this->workerPids[$pid]);
            }
            usleep(100_000);
        }
    }

    private function shutdownMaster(): void
    {
        $this->running = false;
        foreach (array_keys($this->workerPids) as $pid) {
            posix_kill($pid, SIGTERM);
        }
    }

    private function reloadMaster(): void
    {
        foreach (array_keys($this->workerPids) as $pid) {
            posix_kill($pid, SIGUSR1); // worker 停止循环 → 退出 → 主循环自动 refork
        }
    }

    private function openServerSocket(array $listener, bool $reusePort): mixed
    {
        $scheme = $listener['scheme'];
        $opts   = $listener['options'];

        if ($scheme === 'unix') {
            $path = $listener['path'];
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
            $address = 'unix://' . $path;
            $flags   = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $context = null;
        } else {
            $transport = self::STREAM_TRANSPORT[$scheme] ?? 'tcp';
            $address = sprintf('%s://%s:%d', $transport, $listener['host'], $listener['port']);
            $flags   = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

            $ctx = [];
            if ($reusePort && defined('SO_REUSEPORT')) {
                $ctx['socket']['so_reuseport'] = 1;
            }
            if (!empty($opts['backlog'])) {
                $ctx['socket']['backlog'] = (int)$opts['backlog'];
            }
            $context = $ctx === [] ? null : stream_context_create($ctx);
        }

        $sock = @stream_socket_server($address, $errno, $errstr, $flags, $context);
        if ($sock === false) {
            throw new \RuntimeException(sprintf(
                'Native 监听失败 %s: %s (%d)',
                $address,
                $errstr ?: 'unknown',
                $errno
            ));
        }
        stream_set_blocking($sock, false);

        return $sock;
    }

    private function protocolClassFor(string $scheme): ?string
    {
        return match ($scheme) {
            'http'      => HttpProtocol::class,
            'websocket' => WebSocketProtocol::class,
            'text'      => TextProtocol::class,
            'tcp',
            'unix'      => TcpProtocol::class,
            default     => null,
        };
    }

    public function stop(bool $graceful = true): void
    {
        if ($this->isWorker) {
            $this->workerLoop?->stop();
            return;
        }
        $this->running = false;
        foreach (array_keys($this->workerPids) as $pid) {
            posix_kill($pid, $graceful ? SIGTERM : SIGKILL);
        }
    }

    public function reload(): void
    {
        if ($this->isWorker) {
            $this->workerLoop?->stop();
            return;
        }
        if ($this->workerPids === []) {
            throw new RuntimeNotSupportedException('服务尚未启动，无法 reload');
        }
        foreach (array_keys($this->workerPids) as $pid) {
            posix_kill($pid, SIGUSR1);
        }
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = true): int
    {
        $id = ++$this->timerSeq;
        $this->timers[$id] = [
            'interval' => $interval,
            'callback' => $callback,
            'periodic' => $periodic,
        ];
        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        unset($this->timers[$timerId]);
        return true;
    }

    public function stats(): array
    {
        return parent::stats() + [
            'model'   => 'master-worker (prefork)',
            'workers' => count($this->workerPids),
            'loop'    => SelectLoop::name(),
        ];
    }
}
