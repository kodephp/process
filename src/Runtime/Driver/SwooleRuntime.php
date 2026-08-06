<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Runtime\AbstractRuntime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;

/**
 * Swoole 运行时适配器。
 *
 * 应用已经跑在 Swoole 上时，本适配器复用 Swoole 的 I/O 栈与协程能力。
 *
 * 注意：默认使用 SWOOLE_BASE 模式。SWOOLE_PROCESS 模式存在 reactor→worker 的
 * 管道转发开销，实测吞吐低约 8%（详见 docs/gate-report.md）；
 * 需要 Task 工作进程时才应显式切到 PROCESS 模式。
 *
 * 依赖：pecl install swoole（本包不强制依赖）
 */
final class SwooleRuntime extends AbstractRuntime
{
    private ?object $server = null;

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

        $mode = ($opts['mode'] ?? null) === 'process' ? SWOOLE_PROCESS : SWOOLE_BASE;
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
            $this->fire('workerStart', $workerId);
        });
        $server->on('workerStop', function (object $srv, int $workerId): void {
            $this->fire('workerStop', $workerId);
        });

        if ($scheme === 'http') {
            $server->on('request', function (object $req, object $resp) use ($server): void {
                $fd = (int)($req->fd ?? 0);
                $this->fire('message', new SwooleConnection($server, $fd, $resp), $req);
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
                $this->fire('message', new SwooleConnection($srv, (int)$frame->fd), $frame->data);
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
                $this->fire('message', new SwooleConnection($srv, (int)($info['server_socket'] ?? 0)), $data);
            });
        } else {
            $server->on('receive', function (object $srv, int $fd, int $reactorId, string $data): void {
                $this->fire('message', new SwooleConnection($srv, $fd), $data);
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

    /** 底层 Swoole\Server 实例，用于访问 Swoole 专有能力（如 task 投递） */
    public function server(): ?object
    {
        return $this->server;
    }

    public function stats(): array
    {
        $base = parent::stats();
        if ($this->server !== null && method_exists($this->server, 'stats')) {
            $base['swoole'] = $this->server->stats();
        }
        return $base;
    }
}
