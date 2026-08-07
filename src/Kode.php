<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Fibers\Fibers;
use Kode\Process\Exceptions\ParallelException;
use Kode\Process\Parallel\FutureInterface;
use Kode\Process\Parallel\Parallel;
use Kode\Process\Protocol\ProtocolManager;
use Kode\Process\Reactor\LoopFactory;
use Kode\Process\Reactor\LoopInterface;
use Kode\Process\Async\Async;
use Kode\Process\Async\EventEmitter;
use Kode\Process\Debug\StatusMonitor;
use Kode\Process\Queue\QueueManager;
use Kode\Process\Runtime\RuntimeInterface;
use Kode\Process\Runtime\RuntimeType;
use Kode\Process\Signal\SignalHandler;

/**
 * 静态门面：一行代码起服务。
 *
 * ```php
 * Kode::serve('http://0.0.0.0:8080', ['workers' => 8])
 *     ->on('message', fn($conn, $req) => $conn->send('Hello'))
 *     ->start();
 * ```
 *
 * 网络层交给 {@see Runtime}：**默认使用自研 Native 运行时**（纯 PHP master-worker，
 * 零扩展依赖，事件循环自动择优 ext-event / ext-ev / stream_select）。已有 Swoole /
 * Workerman 技术栈的项目可显式接入——`Kode::serve($addr, $opts, 'swoole')`，三者共用
 * 同一套 {@see RuntimeInterface}，切换底层无需改动业务代码。
 * 内置 {@see Reactor} 是统一的事件循环（Kode::loop()），与运行时正交。
 * 本包同时提供进程编排、共享数据、IPC 与信号能力。
 */
final class Kode
{
    // ------------------------------------------------------------ 版本/环境

    public static function version(): string
    {
        return Version::get();
    }

    public static function phpVersion(): string
    {
        return PhpCompat::version();
    }

    public static function phpVersionId(): int
    {
        return PhpCompat::versionId();
    }

    public static function isPhp83(): bool
    {
        return PhpCompat::isPhp83();
    }

    public static function isPhp84(): bool
    {
        return PhpCompat::isPhp84();
    }

    public static function isPhp85(): bool
    {
        return PhpCompat::isPhp85();
    }

    public static function hasPipeOperator(): bool
    {
        return PhpCompat::hasPipeOperator();
    }

    public static function hasPersistentCurlShare(): bool
    {
        return PhpCompat::hasPersistentCurlShare();
    }

    /**
     * 检查运行环境，返回问题清单（为空表示通过）。
     *
     * @return list<string>
     */
    public static function checkEnvironment(): array
    {
        return Version::checkEnvironment();
    }

    /**
     * 断言运行环境满足要求。
     *
     * @throws Exceptions\ProcessException
     */
    public static function requireEnvironment(): void
    {
        Version::requireSupportedEnvironment();
    }

    /**
     * 版本与运行环境信息。
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return Version::getInfo();
    }

    /**
     * 部署前自检：可用运行时、事件循环驱动、共享表后端、并行与集群能力。
     *
     * 只做静态探测，不会建立任何连接。
     *
     * @return array<string, mixed>
     */
    public static function diagnose(): array
    {
        return Runtime::diagnose()
            + ['table' => SharedTable::diagnose()]
            + ['parallel' => [
                'zts' => Parallel::isZts(),
                'available' => Parallel::isAvailable(),
                'backend' => Parallel::backend(),
            ]]
            + ['cluster' => [
                'backends' => Cluster::available(),
                'joined' => Cluster::self()?->id,
            ]];
    }

    // -------------------------------------------------------------- 运行时

    /**
     * 创建运行时实例。
     *
     * @param RuntimeType|string|null $type null 表示自动择优（native → swoole → workerman）
     */
    public static function runtime(RuntimeType|string|null $type = null): RuntimeInterface
    {
        return $type === null ? Runtime::auto() : Runtime::make($type);
    }

    /**
     * 一步创建并监听，返回运行时供链式注册事件。
     *
     * @param string $address 形如 http://0.0.0.0:8080、tcp://0.0.0.0:9501、unix:///tmp/app.sock
     * @param array<string, mixed> $options workers / name / reusePort / ssl / maxRequest / backlog
     * @param RuntimeType|string|null $runtime 指定运行时；null 为自动择优
     */
    public static function serve(
        string $address,
        array $options = [],
        RuntimeType|string|null $runtime = null,
    ): RuntimeInterface {
        return self::runtime($runtime)->listen($address, $options);
    }

    /**
     * 获取事件循环。
     *
     * @param string|null $driver event / ev / select；null 表示自动择优
     */
    public static function loop(?string $driver = null): LoopInterface
    {
        return $driver === null ? LoopFactory::global() : LoopFactory::create($driver);
    }

    // ---------------------------------------------------------- 进程与数据

    /** 全局进程管理器 */
    public static function processManager(): GlobalProcessManager
    {
        return GlobalProcessManager::getInstance();
    }

    /** 协议管理器 */
    public static function protocolManager(): ProtocolManager
    {
        return ProtocolManager::getInstance();
    }

    /**
     * 同主机多进程共享表（零安装择优：apcu → sysvshm）。
     *
     * @param int $key  共享段标识，多进程需一致
     * @param int $size 容量字节数
     */
    public static function table(int $key = 0x4B4F4445, int $size = 4 * 1024 * 1024): GlobalData\TableInterface
    {
        return SharedTable::auto($key, $size);
    }

    // ------------------------------------------------------------ 集群协同

    /**
     * 获取集群协调存储；未初始化时按 redis → globaldata → file 自动择优。
     *
     * 需要指定后端时用 {@see Cluster::make()}：
     *
     * ```php
     * Cluster::make('redis', ['host' => '10.0.0.5']);
     * ```
     */
    public static function cluster(): Cluster\Store\StoreInterface
    {
        return Cluster::store();
    }

    /**
     * 把本节点加入集群，之后需周期调用 {@see Cluster::heartbeat()} 续约。
     *
     * ```php
     * Kode::join(['service' => 'api', 'port' => 9501]);
     * ```
     *
     * @param Cluster\Node|array<string, mixed> $node 缺省 id 取「主机名-PID」，缺省 host 取本机出口 IP
     */
    public static function join(Cluster\Node|array $node): Cluster\Node
    {
        return Cluster::join($node);
    }

    /**
     * 分布式锁——跨机互斥。
     *
     * ```php
     * Kode::lock('settle:1001')->withLock(fn () => $this->settle(1001), wait: 5.0);
     * ```
     */
    public static function lock(string $key, float $ttl = 30.0): Cluster\Lock\DistributedLock
    {
        return Cluster::lock($key, $ttl);
    }

    /**
     * Leader 选举——让定时任务在全集群只跑一次。
     *
     * ```php
     * $election = Kode::election('cron');
     * Kode::every(5.0, fn () => $election->tick() && $this->runCron());
     * ```
     */
    public static function election(string $name = 'default', float $ttl = 15.0): Cluster\Election\LeaderElection
    {
        return Cluster::election($name, null, $ttl);
    }

    /**
     * 负载均衡器：round-robin / weighted / random / least-conn / hash。
     *
     * @param list<Cluster\Node> $nodes 省略时取 $service 下的健康节点
     */
    public static function balancer(
        string $strategy = 'round-robin',
        array $nodes = [],
        ?string $service = null,
    ): Cluster\Balancer\BalancerInterface {
        return Cluster::balancer($strategy, $nodes, $service);
    }

    /** 分布式 ID 生成器（Snowflake），机器 ID 自动从集群领取。 */
    public static function snowflake(?int $workerId = null): Cluster\Snowflake
    {
        return Cluster::snowflake($workerId);
    }

    /** 分布式限流器——限的是集群总量。 */
    public static function limiter(): Cluster\RateLimiter
    {
        return Cluster::limiter();
    }

    /**
     * 上报心跳，延续本节点在注册表中的存活；被摘除时自动重新注册。
     *
     * @return bool 本次是否成功续约（false 表示已重新注册）
     */
    public static function heartbeat(): bool
    {
        return Cluster::heartbeat();
    }

    /** 本节点优雅下线：注销、让出 Leader、归还机器 ID。 */
    public static function leave(): bool
    {
        return Cluster::leave();
    }

    /** 本节点信息；尚未 join() 时返回 null。 */
    public static function self(): ?Cluster\Node
    {
        return Cluster::self();
    }

    /**
     * 列出集群节点。
     *
     * @return list<Cluster\Node>
     */
    public static function nodes(?string $service = null, bool $healthyOnly = true): array
    {
        return Cluster::nodes($service, $healthyOnly);
    }

    /**
     * 列出除本节点外的其它节点（广播时用得上）。
     *
     * @return list<Cluster\Node>
     */
    public static function peers(?string $service = null): array
    {
        return Cluster::peers($service);
    }

    /** 服务注册表（服务发现）。 */
    public static function registry(): Cluster\Registry\RegistryInterface
    {
        return Cluster::registry();
    }

    /** 续租 Snowflake 机器 ID；返回 false 表示租约丢失并会自动重分配。 */
    public static function renewSnowflake(?string $namespace = null): bool
    {
        return Cluster::renewSnowflake($namespace);
    }

    /** 创建集群 RPC 客户端（节点间方法调用 / 广播）。 */
    public static function rpc(float $timeout = 3.0, ?string $token = null): Cluster\Rpc\RpcClient
    {
        return Cluster::rpc($timeout, $token);
    }

    /** 创建集群 RPC 服务端（让本节点可被其它节点调用）。 */
    public static function rpcServer(?string $token = null): Cluster\Rpc\RpcServer
    {
        return Cluster::server($token);
    }

    /**
     * 向集群其它节点广播一次 RPC 调用。
     *
     * @param array<string, mixed> $params
     * @return array<string, array{ok: bool, result?: mixed, error?: string}>
     */
    public static function broadcast(string $method, array $params = [], ?string $service = null): array
    {
        return Cluster::broadcast($method, $params, $service);
    }

    // ----------------------------------------------------- 定时器与编排原语

    /**
     * 注册一次性定时器：delay 秒后执行一次，返回 ID（可用 clearTimer 取消）。
     *
     * @param array<int, mixed> $args 透传给回调的位置参数
     */
    public static function after(float $delay, callable $callback, array $args = []): int
    {
        return Timer::once($delay, $callback, $args);
    }

    /**
     * 注册周期性定时器：每 delay 秒执行一次，直到被 clearTimer 取消。
     *
     * @param array<int, mixed> $args 透传给回调的位置参数
     */
    public static function every(float $delay, callable $callback, array $args = []): int
    {
        return Timer::forever($delay, $callback, $args);
    }

    /**
     * 注册 cron 表达式定时器：如 '0 0 * * *' 每天零点触发一次。
     *
     * @param array<int, mixed> $args 透传给回调的位置参数
     */
    public static function cron(string $expression, callable $callback, array $args = []): int
    {
        return Timer::cron($expression, $callback, $args);
    }

    /**
     * 推进所有定时器与 cron 任务（自定义主循环 / 批处理进程中周期调用）。
     */
    public static function tickTimers(): void
    {
        Timer::tick();
    }

    /**
     * 取消指定的定时器或 cron 任务。
     */
    public static function clearTimer(int $timerId): bool
    {
        return Timer::del($timerId);
    }

    /**
     * 当前活跃的定时器 + cron 任务总数。
     */
    public static function timerCount(): int
    {
        return Timer::count();
    }

    /**
     * 进程级信号处理器单例。
     *
     * 运行时（Swoole / Workerman）自行管理 SIGTERM / SIGINT 等进程信号；
     * 应用层可用其余信号（如 SIGHUP 重载配置、SIGUSR1 自定义）通过本处理器注册。
     */
    public static function signal(): SignalHandler
    {
        return SignalHandler::getInstance();
    }

    /**
     * 进程侧队列管理器单例（kode/queue 适配层：处理器注册 + 单条/批量消费）。
     */
    public static function queue(): QueueManager
    {
        return QueueManager::getInstance();
    }

    /**
     * 进程运行状态监控器（写入 status / pid 文件，便于运维排查）。
     *
     * @param string|null $statusFile 状态文件路径，null 用默认 /tmp/kode_process_status.json
     * @param string|null $pidFile    PID 文件路径，null 用默认 /tmp/kode_process.pid
     */
    public static function monitor(?string $statusFile = null, ?string $pidFile = null): StatusMonitor
    {
        return new StatusMonitor(
            $statusFile ?? '/tmp/kode_process_status.json',
            $pidFile ?? '/tmp/kode_process.pid'
        );
    }

    /**
     * 进程内事件发射器（发布/订阅）：Kode::emitter()->on('event', $cb) / ->emit('event', ...)。
     */
    public static function emitter(): EventEmitter
    {
        return Async::getEmitter();
    }

    // ------------------------------------------------------------ 并发原语

    /**
     * 启动一个协程（委托 kode/fibers，本包不重复造协程调度器）。
     */
    public static function go(callable $task, ?float $timeout = null): mixed
    {
        return Fibers::go($task, $timeout);
    }

    /**
     * 批量并发处理（委托 kode/fibers）。
     *
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    public static function batch(array $items, callable $handler, ?int $concurrency = null): array
    {
        return Fibers::batch($items, $handler, $concurrency);
    }

    /**
     * 在独立 OS 线程中执行 CPU 密集任务（需 ZTS + ext-parallel）。
     *
     * 与 {@see self::go()} 互补：协程负责 I/O 并发，并行负责 CPU 并发。
     *
     * @throws ParallelException 当前环境不支持真正的多线程
     */
    public static function parallel(callable $task, mixed ...$args): FutureInterface
    {
        return Parallel::run($task, ...$args);
    }

    /**
     * 等待并行任务结果；在协程内调用不阻塞其它协程。
     *
     * @throws \Throwable 任务执行失败时透传原异常
     */
    public static function awaitParallel(FutureInterface $future): mixed
    {
        return Parallel::await($future);
    }

    /** 当前是否支持真正的多线程并行（ZTS + ext-parallel） */
    public static function supportsParallel(): bool
    {
        return Parallel::isAvailable();
    }

    /** 当前并行后端：'ext-parallel' | 'kode-parallel' | 'none' */
    public static function parallelBackend(): string
    {
        return Parallel::backend();
    }

    // ---------------------------------------------------------------- 响应

    public static function response(mixed $data = null, string $message = 'success'): Response
    {
        return Response::ok($data, $message);
    }

    public static function error(string $message = 'error', int $code = Response::CODE_ERROR): Response
    {
        return Response::error($message, $code);
    }
}
