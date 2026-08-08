<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Http\Request;
use Kode\Process\Runtime\AbstractRuntime;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;

/**
 * Swoole 运行时适配器。
 *
 * 应用已经跑在 Swoole 上时，本适配器复用 Swoole 的 I/O 栈与协程能力。
 *
 * 注意：默认使用 SWOOLE_BASE 模式。SWOOLE_PROCESS 模式存在 reactor→worker 的
 * 管道转发开销，实测吞吐低约 8%；配置 taskWorkers > 0 时会自动切到 PROCESS 模式，
 * 因为 Task 工作进程只在该模式下可用。
 *
 * 依赖：pecl install swoole（本包不强制依赖）
 */
final class SwooleRuntime extends AbstractRuntime
{
    private ?object $server = null;

    private int $currentWorkerId = 0;

    private int $taskWorkerCount = 0;

    private bool $gzipEnabled = true;

    public static function isAvailable(): bool
    {
        return extension_loaded('swoole') && class_exists('\Swoole\Server');
    }

    public static function type(): RuntimeType
    {
        return RuntimeType::Swoole;
    }

    public static function version(): ?string
    {
        return self::isAvailable() ? (string)swoole_version() : null;
    }

    public function __construct()
    {
        if (!self::isAvailable()) {
            throw RuntimeNotSupportedException::unavailable(
                RuntimeType::Swoole,
                '请执行 pecl install swoole'
            );
        }
    }

    protected function supportedSchemes(): array
    {
        return ['tcp', 'udp', 'http', 'websocket', 'ws', 'unix', 'ssl'];
    }

    public function capabilities(): array
    {
        return [
            Capability::Coroutine,
            Capability::SharedTable,
            Capability::TaskWorker,
            Capability::UdpServer,
            Capability::UnixSocket,
            Capability::Ssl,
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
        $opts     = $listener['options'];
        $scheme   = $listener['scheme'];

        $this->taskWorkerCount = max(0, (int)($opts['taskWorkers'] ?? 0));
        $this->gzipEnabled     = (bool)($opts['gzip'] ?? true);

        // Task 进程必须由 PROCESS 模式承载，配置了 taskWorkers 时自动切换
        $mode = ($opts['mode'] ?? null) === 'process' || $this->taskWorkerCount > 0
            ? SWOOLE_PROCESS
            : SWOOLE_BASE;
        $sockType = isset($opts['ssl']) ? (SWOOLE_SOCK_TCP | SWOOLE_SSL) : SWOOLE_SOCK_TCP;

        $class = match ($scheme) {
            'http'                 => '\Swoole\Http\Server',
            'websocket', 'ws'      => '\Swoole\WebSocket\Server',
            'udp'                  => '\Swoole\Server',
            default                => '\Swoole\Server',
        };
        if ($scheme === 'udp') {
            $sockType = SWOOLE_SOCK_UDP;
        }

        $host = $scheme === 'unix' ? $listener['path'] : $listener['host'];
        $port = $scheme === 'unix' ? 0 : $listener['port'];

        /** @var object $server */
        $server = new $class($host, $port, $mode, $sockType);

        $settings = [
            'worker_num'       => (int)($opts['workers'] ?? 4),
            'log_level'        => $opts['logLevel'] ?? SWOOLE_LOG_ERROR,
            'enable_coroutine' => (bool)($opts['coroutine'] ?? false),
            'backlog'          => (int)($opts['backlog'] ?? 65535),
        ];
        if (!empty($opts['maxRequest'])) {
            $settings['max_request'] = (int)$opts['maxRequest'];
        }
        if ($this->taskWorkerCount > 0) {
            $settings['task_worker_num']      = $this->taskWorkerCount;
            $settings['task_enable_coroutine'] = false;
        }
        if (isset($opts['ssl']) && is_array($opts['ssl'])) {
            $settings += [
                'ssl_cert_file' => $opts['ssl']['local_cert'] ?? null,
                'ssl_key_file'  => $opts['ssl']['local_pk'] ?? null,
            ];
            $settings = array_filter($settings, static fn ($v) => $v !== null);
        }
        $server->set($settings);

        $this->bindEvents($server, $scheme);
        $this->server  = $server;
        $this->running = true;
        $server->start();
        $this->running = false;
    }

    private function bindEvents(object $server, string $scheme): void
    {
        $server->on('workerStart', function (object $srv, int $workerId): void {
            $this->currentWorkerId = $workerId;
            $this->fire('workerStart', $workerId);
        });
        $server->on('workerStop', function (object $srv, int $workerId): void {
            $this->fire('workerStop', $workerId);
        });

        if ($this->taskWorkerCount > 0) {
            $server->on('task', function (object $srv, int $taskId, int $srcWorkerId, mixed $data): mixed {
                return $this->fire('task', $data, $taskId);
            });
            $server->on('finish', function (object $srv, int $taskId, mixed $result): void {
                $this->fire('finish', $result);
            });
        }

        if ($scheme === 'http') {
            $server->on('request', function (object $req, object $resp) use ($server): void {
                $fd   = (int)($req->fd ?? 0);
                $conn = new SwooleConnection($server, $fd, $resp);
                // 依据 Accept-Encoding 自动启用 gzip（响应体达阈值才压缩，send 时判定）
                if ($this->gzipEnabled && HttpProtocol::acceptsGzip((string)($req->header['accept-encoding'] ?? ''))) {
                    $conn->setGzipAuto(true);
                }
                // 统一交付 Kode\Process\Http\Request，而不是把 Swoole\Http\Request 直接抛给业务：
                // 三个运行时的 handler 因此看到同一个类型、同一套字段。需要 Swoole 专有能力时
                // 用 $request->native() 取回原对象。
                $this->fireMessage($conn, Request::fromSwoole($req));
                if ($conn->isChunkStarted()) {
                    $conn->endChunk();
                }
            });
            return;
        }

        if ($scheme === 'websocket' || $scheme === 'ws') {
            if ($this->hasHandler('connect')) {
                $server->on('open', function (object $srv, object $req): void {
                    $this->fire('connect', new SwooleConnection($srv, (int)$req->fd));
                });
            }
            $server->on('message', function (object $srv, object $frame): void {
                $this->fireMessage(new SwooleConnection($srv, (int)$frame->fd), $frame->data);
            });
            if ($this->hasHandler('close')) {
                $server->on('close', function (object $srv, int $fd): void {
                    $this->fire('close', new SwooleConnection($srv, $fd));
                });
            }
            return;
        }

        // TCP / UDP / Unix
        if ($this->hasHandler('connect')) {
            $server->on('connect', function (object $srv, int $fd): void {
                $this->fire('connect', new SwooleConnection($srv, $fd));
            });
        }

        if ($scheme === 'udp') {
            $server->on('packet', function (object $srv, string $data, array $info): void {
                $this->fireMessage(new SwooleConnection($srv, (int)($info['server_socket'] ?? 0)), $data);
            });
        } else {
            $server->on('receive', function (object $srv, int $fd, int $reactorId, string $data): void {
                $this->fireMessage(new SwooleConnection($srv, $fd), $data);
            });
        }

        if ($this->hasHandler('close')) {
            $server->on('close', function (object $srv, int $fd): void {
                $this->fire('close', new SwooleConnection($srv, $fd));
            });
        }
    }

    public function stop(bool $graceful = true): void
    {
        $this->running = false;
        if ($this->server === null) {
            return;
        }
        $graceful ? $this->server->shutdown() : $this->server->stop();
    }

    public function reload(): void
    {
        if ($this->server === null) {
            throw new RuntimeNotSupportedException('服务尚未启动，无法 reload');
        }
        $this->server->reload();
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = true): int
    {
        $ms = max(1, (int)round($interval * 1000));
        $id = ++$this->timerSeq;

        $this->timers[$id] = $periodic
            ? \Swoole\Timer::tick($ms, $callback)
            : \Swoole\Timer::after($ms, $callback);

        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        $ok = \Swoole\Timer::clear($this->timers[$timerId]);
        unset($this->timers[$timerId]);
        return (bool)$ok;
    }

    /** 底层 Swoole\Server 实例，用于访问 Swoole 专有能力 */
    public function server(): ?object
    {
        return $this->server;
    }

    public function workerId(): int
    {
        return $this->currentWorkerId;
    }

    /**
     * 当前 worker 的连接（Swoole\Server::$connections）。
     *
     * @return array<int, \Kode\Process\Runtime\ConnectionInterface>
     */
    public function connections(): array
    {
        if ($this->server === null || !isset($this->server->connections)) {
            return [];
        }

        $out = [];
        foreach ($this->server->connections as $fd) {
            $out[(int)$fd] = new SwooleConnection($this->server, (int)$fd);
        }
        return $out;
    }

    /**
     * 配置了 taskWorkers 时投递到 Swoole Task 进程，否则降级为进程内同步执行。
     */
    public function task(mixed $data): bool
    {
        if ($this->taskWorkerCount > 0 && $this->server !== null && method_exists($this->server, 'task')) {
            return $this->server->task($data) !== false;
        }
        return parent::task($data);
    }

    public function stats(): array
    {
        $base = parent::stats() + [
            'worker_id'    => $this->currentWorkerId,
            'task_workers' => $this->taskWorkerCount,
        ];
        if ($this->server !== null && method_exists($this->server, 'stats')) {
            $base['swoole'] = $this->server->stats();
        }
        return $base;
    }
}
