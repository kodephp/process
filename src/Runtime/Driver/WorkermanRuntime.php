<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Http\Request;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\AbstractRuntime;
use Kode\Process\Runtime\Capability;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;
use Kode\Process\Runtime\RuntimeType;

/**
 * Workerman 运行时适配器。
 *
 * 应用已经跑在 Workerman 上时，本适配器让你继续使用 Workerman 的 I/O 栈，
 * 同时获得本包的进程编排、共享表、IPC 等能力——不引入第二套 I/O 实现。
 *
 * 依赖：composer require workerman/workerman ^5.0（本包不强制依赖）
 */
final class WorkermanRuntime extends AbstractRuntime
{
    private const WORKER_CLASS = '\Workerman\Worker';

    /** Workerman 在 http 场景下交付的请求类，用于识别是否需要包装 */
    private const WORKERMAN_REQUEST = '\Workerman\Protocols\Http\Request';

    /** @var array<int, object> Workerman\Worker 实例 */
    private array $workers = [];

    private int $currentWorkerId = 0;

    private ?object $currentWorker = null;

    private bool $gzipEnabled = true;

    public static function isAvailable(): bool
    {
        return class_exists(self::WORKER_CLASS);
    }

    public static function type(): RuntimeType
    {
        return RuntimeType::Workerman;
    }

    public static function version(): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }
        $class = self::WORKER_CLASS;
        if (defined($class . '::VERSION')) {
            return (string)constant($class . '::VERSION');
        }
        return method_exists($class, 'version') ? (string)$class::version() : null;
    }

    public function __construct()
    {
        if (!self::isAvailable()) {
            throw RuntimeNotSupportedException::unavailable(
                RuntimeType::Workerman,
                '请执行 composer require workerman/workerman ^5.0'
            );
        }
    }

    protected function supportedSchemes(): array
    {
        return ['tcp', 'udp', 'http', 'websocket', 'ws', 'wss', 'unix', 'ssl', 'text', 'frame'];
    }

    public function capabilities(): array
    {
        return [
            Capability::SharedTable,
            Capability::UdpServer,
            Capability::UnixSocket,
            Capability::Ssl,
            Capability::HotReload,
            Capability::ReusePort,
            Capability::WebSocket,
            Capability::Timer,
        ];
    }

    public function start(): void
    {
        $this->requireListener();
        $workerClass = self::WORKER_CLASS;

        foreach ($this->listeners as $index => $listener) {
            $opts    = $listener['options'];
            $address = $this->buildAddress($listener);
            $this->gzipEnabled = (bool)($opts['gzip'] ?? true);

            $context = [];
            if (isset($opts['ssl'])) {
                $context['ssl'] = $opts['ssl'];
            }
            if (!empty($opts['backlog'])) {
                $context['socket']['backlog'] = (int)$opts['backlog'];
            }

            /** @var object $worker */
            $worker        = new $workerClass($address, $context);
            $worker->count = (int)($opts['workers'] ?? 4);
            $worker->name  = (string)($opts['name'] ?? 'kode-process');

            if (!empty($opts['reusePort'])) {
                $worker->reusePort = true;
            }
            if (!empty($opts['maxRequest'])) {
                $worker->maxRequest = (int)$opts['maxRequest'];
            }
            if (isset($opts['ssl'])) {
                $worker->transport = 'ssl';
            }

            $this->bindEvents($worker);
            $this->workers[$index] = $worker;
        }

        $this->running = true;
        $restoreArgv   = $this->normalizeArgv();
        try {
            $workerClass::runAll();
        } finally {
            $restoreArgv();
            $this->running = false;
        }
    }

    /**
     * 屏蔽 Workerman 的 argv 约定，保证跨运行时行为一致。
     *
     * Workerman 会解析 `$argv[1]` 作为 start/stop/reload/status 子命令，参数不合法时
     * 直接打印用法并 exit——这会让「同一份业务代码切换运行时」的承诺失效（Native /
     * Swoole 都不解析 argv）。因此默认注入合成的 `['<script>', 'start']`。
     *
     * 需要 Workerman 原生 CLI（stop/reload/status/-d 守护）时，
     * 传 `['workermanCli' => true]` 显式保留真实 argv。
     *
     * @return callable():void 还原原始 argv 的闭包
     */
    private function normalizeArgv(): callable
    {
        $opts = $this->listeners[array_key_first($this->listeners)]['options'] ?? [];

        if (!empty($opts['workermanCli'])) {
            return static fn (): null => null;
        }

        $originalArgv = $_SERVER['argv'] ?? [];
        $originalArgc = $_SERVER['argc'] ?? 0;

        $command = !empty($opts['daemonize']) ? ['start', '-d'] : ['start'];
        $synthetic = array_merge([$originalArgv[0] ?? 'kode-process'], $command);

        $GLOBALS['argv'] = $_SERVER['argv'] = $synthetic;
        $GLOBALS['argc'] = $_SERVER['argc'] = count($synthetic);

        return static function () use ($originalArgv, $originalArgc): void {
            $GLOBALS['argv'] = $_SERVER['argv'] = $originalArgv;
            $GLOBALS['argc'] = $_SERVER['argc'] = $originalArgc;
        };
    }

    /**
     * 把统一事件桥接到 Workerman 的回调属性上。
     */
    private function bindEvents(object $worker): void
    {
        $worker->onWorkerStart = function (object $w): void {
            $this->currentWorker   = $w;
            $this->currentWorkerId = (int)($w->id ?? 0);
            $this->fire('workerStart', $this->currentWorkerId);
        };

        $worker->onWorkerStop = function (object $w): void {
            $this->fire('workerStop', (int)($w->id ?? 0));
        };

        if ($this->hasHandler('connect')) {
            $worker->onConnect = function (object $conn): void {
                $this->fire('connect', new WorkermanConnection($conn));
            };
        }

        if ($this->hasHandler('message')) {
            $worker->onMessage = function (object $conn, mixed $data): void {
                $wrap = new WorkermanConnection($conn);

                // HTTP 场景下 Workerman 交来的是 Workerman\Protocols\Http\Request 对象，
                // 统一包装成 Kode\Process\Http\Request 再交给业务——否则同一份 handler
                // 换到 Native 上就会因为字段访问方式不同而崩。
                // 非 HTTP（tcp/ws/text/frame）原样透传字符串。
                $requestClass = self::WORKERMAN_REQUEST;
                if ($data instanceof $requestClass) {
                    $request = Request::fromWorkerman($data);
                    if ($this->gzipEnabled && HttpProtocol::acceptsGzip($request->header('accept-encoding', '') ?? '')) {
                        $wrap->setGzipAuto(true);
                    }
                    $data = $request;
                }

                $this->fireMessage($wrap, $data);
                if ($wrap->isChunkStarted()) {
                    $wrap->endChunk();
                }
            };
        }

        if ($this->hasHandler('close')) {
            $worker->onClose = function (object $conn): void {
                $this->fire('close', new WorkermanConnection($conn));
            };
        }

        if ($this->hasHandler('error')) {
            $worker->onError = function (object $conn, mixed $code, mixed $msg): void {
                $this->fire('error', new WorkermanConnection($conn), new \RuntimeException((string)$msg, (int)$code));
            };
        }
    }

    /**
     * @param array{scheme:string, host:string, port:int, path:string, options:array<string,mixed>} $listener
     */
    private function buildAddress(array $listener): string
    {
        if ($listener['scheme'] === 'unix') {
            return 'unix://' . $listener['path'];
        }
        // Workerman 的 ssl 通过 transport 设置，地址仍用 tcp/http
        $scheme = $listener['scheme'] === 'ssl' ? 'tcp' : $listener['scheme'];
        return sprintf('%s://%s:%d', $scheme, $listener['host'], $listener['port']);
    }

    public function stop(bool $graceful = true): void
    {
        $workerClass = self::WORKER_CLASS;
        $this->running = false;

        if (method_exists($workerClass, 'stopAll')) {
            $graceful ? $workerClass::stopAll(0) : $workerClass::stopAll(SIGKILL);
        }
    }

    public function reload(): void
    {
        $workerClass = self::WORKER_CLASS;
        if (!method_exists($workerClass, 'reloadAll')) {
            throw RuntimeNotSupportedException::capability(RuntimeType::Workerman, Capability::HotReload);
        }
        $workerClass::reloadAll();
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = true): int
    {
        $timerClass = '\Workerman\Timer';
        if (!class_exists($timerClass)) {
            throw RuntimeNotSupportedException::capability(RuntimeType::Workerman, Capability::Timer);
        }

        $id = ++$this->timerSeq;

        // 异常隔离：定时回调抛异常绝不能穿透 Workerman 事件循环、打死 worker。
        // 与 Native 三个 Loop 的定时器回调统一约定一致。
        // 一次性定时器触发后底层已自动移除，顺手清掉本端映射，避免陈旧的 timer id 残留。
        $wrapped = function () use ($callback, $id, $periodic): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                \error_log(sprintf('WorkermanRuntime: timer#%d 回调异常已隔离，循环继续: %s', $id, $e->getMessage()));
            }
            if (!$periodic) {
                unset($this->timers[$id]);
            }
        };

        $this->timers[$id] = (int)$timerClass::add($interval, $wrapped, [], $periodic);
        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        $timerClass = '\Workerman\Timer';
        $ok = class_exists($timerClass) && $timerClass::del($this->timers[$timerId]);
        unset($this->timers[$timerId]);
        return (bool)$ok;
    }

    public function workerId(): int
    {
        return $this->currentWorkerId;
    }

    /**
     * 当前 worker 的连接（Workerman\Worker::$connections）。
     *
     * @return array<int, \Kode\Process\Runtime\ConnectionInterface>
     */
    public function connections(): array
    {
        $raw = $this->currentWorker->connections ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id => $conn) {
            if (is_object($conn)) {
                $out[(int)$id] = new WorkermanConnection($conn);
            }
        }
        return $out;
    }

    public function stats(): array
    {
        return parent::stats() + [
            'workers'        => count($this->workers),
            'worker_id'      => $this->currentWorkerId,
            'connections'    => count($this->connections()),
            'event_loop'     => $this->detectEventLoop(),
            'recommendation' => self::eventLoopRecommendation(),
        ];
    }

    /** Workerman 实际选用的事件循环（event / select 等） */
    private function detectEventLoop(): string
    {
        if (extension_loaded('event')) {
            return 'event';
        }
        if (extension_loaded('ev')) {
            return 'ev';
        }
        return 'select';
    }

    /**
     * Linux 上 Workerman 用 ext-event 事件循环吞吐显著更高（Workerman 官方也建议）。
     * 若当前是 Linux 且未装 ext-event / ext-ev，返回一条安装建议；否则返回 null。
     */
    public static function eventLoopRecommendation(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        if (extension_loaded('event') || extension_loaded('ev')) {
            return null;
        }
        return '建议安装 ext-event 以获得更高吞吐：'
            . 'sudo apt-get install -y libevent-dev && sudo pecl install event';
    }
}
