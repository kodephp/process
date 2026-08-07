<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Cluster\Balancer\BalancerInterface;
use Kode\Process\Cluster\Balancer\ConsistentHashBalancer;
use Kode\Process\Cluster\Balancer\LeastConnectionsBalancer;
use Kode\Process\Cluster\Balancer\RandomBalancer;
use Kode\Process\Cluster\Balancer\RoundRobinBalancer;
use Kode\Process\Cluster\Balancer\WeightedRoundRobinBalancer;
use Kode\Process\Cluster\Election\LeaderElection;
use Kode\Process\Cluster\Lock\DistributedLock;
use Kode\Process\Cluster\Node;
use Kode\Process\Cluster\RateLimiter;
use Kode\Process\Cluster\Registry\RegistryInterface;
use Kode\Process\Cluster\Registry\StoreRegistry;
use Kode\Process\Cluster\Rpc\RpcClient;
use Kode\Process\Cluster\Rpc\RpcServer;
use Kode\Process\Cluster\Snowflake;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Cluster\Store\GlobalDataStore;
use Kode\Process\Cluster\Store\RedisStore;
use Kode\Process\Cluster\Store\StoreInterface;
use Kode\Process\Exceptions\ClusterException;
use Throwable;

/**
 * 集群（分布式）门面——把单机的多进程服务变成多机集群。
 *
 * {@see Runtime} 解决「一台机器上跑满多核」，本门面解决「多台机器协同工作」：
 * 谁在线、谁当头、谁来干、干多少、往哪派。
 *
 * ## 六件套
 *
 * | 能力       | 入口                    | 解决什么问题                             |
 * |------------|-------------------------|------------------------------------------|
 * | 服务发现   | {@see registry()}       | 节点上下线自动感知，崩溃自动摘除         |
 * | 分布式锁   | {@see lock()}           | 跨机互斥，防止并发改坏同一份数据         |
 * | Leader 选举| {@see election()}       | 定时任务全集群只跑一次                   |
 * | 负载均衡   | {@see balancer()}       | 请求往哪台派，五种策略                   |
 * | 分布式 ID  | {@see snowflake()}      | 全局唯一、趋势递增的主键                 |
 * | 分布式限流 | {@see limiter()}        | 限的是集群总量，不是每台各限一份         |
 * | 节点间调用 | {@see rpc()} / {@see server()} | 节点互相调方法、全集群广播        |
 *
 * ## 三种存储后端
 *
 * 所有能力都建在 {@see StoreInterface} 之上，换后端不用改一行业务代码：
 *
 * ```php
 * Cluster::make('redis', ['host' => '10.0.0.5']);          // 生产多机（推荐）
 * Cluster::make('globaldata', ['address' => '10.0.0.5:2207']); // 零外部依赖
 * Cluster::make('file', ['path' => '/dev/shm/kode']);      // 单机多实例 / 开发
 * ```
 *
 * ## 一个完整节点长什么样
 *
 * ```php
 * use Kode\Process\{Kode, Cluster};
 *
 * Cluster::make('redis', ['host' => '10.0.0.5']);
 *
 * $server = Kode::serve('http://0.0.0.0:9501', ['workers' => 8]);
 *
 * $server->on('workerStart', function ($rt) {
 *     if ($rt->workerId() !== 0) {
 *         return;                                  // 只让 0 号 worker 参与集群协调
 *     }
 *
 *     Cluster::join(['id' => gethostname(), 'service' => 'api', 'port' => 9501]);
 *
 *     $election = Cluster::election('cron');
 *     Kode::every(5.0, function () use ($election) {
 *         Cluster::heartbeat();                    // 上报存活
 *         if ($election->tick()) {
 *             $this->runClusterWideCron();         // 全集群唯一执行点
 *         }
 *     });
 * });
 *
 * $server->on('message', function ($conn) {
 *     $conn->send('node ' . Cluster::self()?->id);
 * });
 *
 * $server->start();
 * ```
 *
 * @since 5.0.0
 */
final class Cluster
{
    /**
     * 已注册的存储后端，按 auto() 的择优顺序排列。
     *
     * @var array<string, class-string<StoreInterface>>
     */
    private const STORES = [
        'redis'      => RedisStore::class,
        'globaldata' => GlobalDataStore::class,
        'file'       => FileStore::class,
    ];

    /** 负载均衡策略别名。 @var array<string, class-string<BalancerInterface>> */
    private const BALANCERS = [
        'round-robin'     => RoundRobinBalancer::class,
        'rr'              => RoundRobinBalancer::class,
        'weighted'        => WeightedRoundRobinBalancer::class,
        'wrr'             => WeightedRoundRobinBalancer::class,
        'random'          => RandomBalancer::class,
        'least-conn'      => LeastConnectionsBalancer::class,
        'least'           => LeastConnectionsBalancer::class,
        'hash'            => ConsistentHashBalancer::class,
        'consistent-hash' => ConsistentHashBalancer::class,
    ];

    private static ?StoreInterface $store = null;

    private static ?RegistryInterface $registry = null;

    private static ?Node $self = null;

    private static ?Snowflake $snowflake = null;

    private static ?RateLimiter $limiter = null;

    /** @var array<string, LeaderElection> */
    private static array $elections = [];

    /** 注册表心跳有效期（秒）。 */
    private static float $ttl = 15.0;

    // ------------------------------------------------------------------
    // 存储后端
    // ------------------------------------------------------------------

    /**
     * 获取当前存储后端；未设置时自动择优。
     */
    public static function store(): StoreInterface
    {
        return self::$store ??= self::auto();
    }

    /**
     * 显式指定存储后端实例。
     */
    public static function useStore(StoreInterface $store): StoreInterface
    {
        self::resetDerived();

        return self::$store = $store;
    }

    /**
     * 创建并启用指定后端。
     *
     * @param array<string, mixed> $options
     * @throws ClusterException 后端未知或不可用
     */
    public static function make(string $backend, array $options = []): StoreInterface
    {
        $class = self::STORES[$backend] ?? null;

        if ($class === null) {
            throw ClusterException::unknownBackend($backend, array_keys(self::STORES));
        }

        return self::useStore(new $class($options));
    }

    /**
     * 自动择优：redis → globaldata → file。
     *
     * 前两者需要真实连通才算可用，连不上就顺延；file 后端总是兜底成功，
     * 因此本方法在正常环境下不会失败。
     *
     * @param array<string, array<string, mixed>> $options 按后端名分组的构造参数
     */
    public static function auto(array $options = []): StoreInterface
    {
        $errors = [];

        foreach (self::STORES as $name => $class) {
            if (!$class::isAvailable()) {
                $errors[$name] = '环境不支持';
                continue;
            }

            try {
                return new $class($options[$name] ?? []);
            } catch (Throwable $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        throw new ClusterException(
            '没有可用的集群存储后端：' . json_encode($errors, JSON_UNESCAPED_UNICODE)
        );
    }

    /** 后端是否已注册。 */
    public static function supports(string $backend): bool
    {
        return isset(self::STORES[$backend]);
    }

    /**
     * 当前环境可用的后端名。
     *
     * 只做静态能力探测（扩展是否加载），不发起连接。
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $available = [];

        foreach (self::STORES as $name => $class) {
            if ($class::isAvailable()) {
                $available[] = $name;
            }
        }

        return $available;
    }

    // ------------------------------------------------------------------
    // 服务注册与发现
    // ------------------------------------------------------------------

    /**
     * 获取注册表。
     */
    public static function registry(?float $ttl = null): RegistryInterface
    {
        if ($ttl !== null && $ttl !== self::$ttl) {
            self::$ttl      = $ttl;
            self::$registry = null;
        }

        return self::$registry ??= new StoreRegistry(self::store(), self::$ttl);
    }

    public static function useRegistry(RegistryInterface $registry): RegistryInterface
    {
        return self::$registry = $registry;
    }

    /**
     * 把本节点加入集群。
     *
     * ```php
     * Cluster::join(['id' => gethostname(), 'service' => 'api', 'host' => '10.0.0.11', 'port' => 9501]);
     * ```
     *
     * 加入后必须周期调用 {@see heartbeat()} 续约，否则会在 ttl×2 后被自动摘除。
     *
     * @param Node|array<string, mixed> $node 缺省 host 时自动取本机 IP，缺省 id 时取 主机名-PID
     */
    public static function join(Node|array $node): Node
    {
        if (is_array($node)) {
            $node['id']   ??= gethostname() . '-' . getmypid();
            $node['host'] ??= self::localIp();
            $node          = Node::fromArray($node);
        }

        return self::$self = self::registry()->register($node);
    }

    /**
     * 上报本节点心跳。
     *
     * 节点已被摘除（长时间失联）时会自动重新注册，因此调用方无需处理这种边界。
     *
     * @return bool 是否成功续约（false 表示刚刚重新注册过）
     */
    public static function heartbeat(): bool
    {
        $self = self::$self;

        if ($self === null) {
            return false;
        }

        if (self::registry()->heartbeat($self->id, $self->service)) {
            return true;
        }

        // 被摘除了：重新注册，让节点自愈
        self::$self = self::registry()->register($self);

        return false;
    }

    /**
     * 本节点优雅下线：注销注册、让出 Leader、归还机器 ID。
     */
    public static function leave(): bool
    {
        foreach (self::$elections as $election) {
            $election->resign();
        }
        self::$elections = [];

        $self = self::$self;
        if ($self === null) {
            return false;
        }

        self::$self = null;

        return self::registry()->deregister($self->id, $self->service);
    }

    /** 本节点信息；尚未 join() 时返回 null。 */
    public static function self(): ?Node
    {
        return self::$self;
    }

    /**
     * 列出集群节点。
     *
     * @return list<Node>
     */
    public static function nodes(?string $service = null, bool $healthyOnly = true): array
    {
        return self::registry()->nodes($service, $healthyOnly);
    }

    /**
     * 列出除本节点外的其它节点（广播时用得上）。
     *
     * @return list<Node>
     */
    public static function peers(?string $service = null): array
    {
        $selfId = self::$self?->id;

        return array_values(array_filter(
            self::nodes($service),
            static fn (Node $n): bool => $n->id !== $selfId
        ));
    }

    // ------------------------------------------------------------------
    // 协调原语
    // ------------------------------------------------------------------

    /**
     * 创建一把分布式锁。
     *
     * ```php
     * Cluster::lock('settle:order:1001', ttl: 30.0)
     *     ->withLock(fn () => $this->settle(1001), wait: 5.0);
     * ```
     */
    public static function lock(string $key, float $ttl = 30.0, ?string $owner = null): DistributedLock
    {
        return new DistributedLock(self::store(), $key, $ttl, $owner ?? self::$self?->id);
    }

    /**
     * 获取（并缓存）一个 Leader 选举实例。
     *
     * 同名选举返回同一个实例，方便在不同代码位置拿到一致的状态与回调。
     */
    public static function election(string $name = 'default', ?string $nodeId = null, float $ttl = 15.0): LeaderElection
    {
        return self::$elections[$name] ??= new LeaderElection(
            self::store(),
            $name,
            $nodeId ?? self::$self?->id ?? (gethostname() . '-' . getmypid()),
            $ttl
        );
    }

    /**
     * 创建负载均衡器。
     *
     * 策略：`round-robin`（默认）、`weighted`、`random`、`least-conn`、`hash`。
     *
     * ```php
     * $lb = Cluster::balancer('hash', Cluster::nodes('cache'));
     * $node = $lb->select("user:{$userId}");
     * ```
     *
     * @param list<Node> $nodes 省略时自动取 $service 下的健康节点
     */
    public static function balancer(
        string $strategy = 'round-robin',
        array $nodes = [],
        ?string $service = null,
    ): BalancerInterface {
        $class = self::BALANCERS[$strategy] ?? null;

        if ($class === null) {
            throw new ClusterException(sprintf(
                '未知的负载均衡策略 %s，可选：%s',
                $strategy,
                implode(', ', array_unique(array_keys(self::BALANCERS)))
            ));
        }

        if ($nodes === [] && $service !== null) {
            $nodes = self::nodes($service);
        }

        return new $class($nodes);
    }

    /**
     * 获取分布式 ID 生成器。
     *
     * 机器 ID 省略时自动从协调存储领取一个集群内唯一值，并需周期续租：
     *
     * ```php
     * $snowflake = Cluster::snowflake();
     * Kode::every(60.0, fn () => Cluster::renewSnowflake());
     * ```
     */
    public static function snowflake(?int $workerId = null, string $namespace = 'default'): Snowflake
    {
        if (self::$snowflake !== null && $workerId === null) {
            return self::$snowflake;
        }

        $workerId ??= Snowflake::allocateWorkerId(self::store(), $namespace);

        return self::$snowflake = new Snowflake($workerId);
    }

    /** 续租 Snowflake 机器 ID；返回 false 表示租约丢失，会自动重新分配。 */
    public static function renewSnowflake(string $namespace = 'default'): bool
    {
        $snowflake = self::$snowflake;

        if ($snowflake === null) {
            return false;
        }

        if (Snowflake::renewWorkerId(self::store(), $namespace, $snowflake->workerId())) {
            return true;
        }

        self::$snowflake = null;
        self::snowflake(null, $namespace);

        return false;
    }

    /** 获取分布式限流器。 */
    public static function limiter(): RateLimiter
    {
        return self::$limiter ??= new RateLimiter(self::store());
    }

    /** 创建集群 RPC 客户端。 */
    public static function rpc(float $timeout = 3.0, ?string $token = null): RpcClient
    {
        return new RpcClient($timeout, $token);
    }

    /** 创建集群 RPC 服务端。 */
    public static function server(?string $token = null): RpcServer
    {
        return new RpcServer($token);
    }

    /**
     * 向集群其它节点广播一次 RPC 调用。
     *
     * @param  array<string, mixed> $params
     * @return array<string, array{ok: bool, result?: mixed, error?: string}>
     */
    public static function broadcast(string $method, array $params = [], ?string $service = null): array
    {
        return self::rpc()->broadcast(self::peers($service), $method, $params);
    }

    // ------------------------------------------------------------------
    // 诊断与复位
    // ------------------------------------------------------------------

    /**
     * 集群自检。
     *
     * @return array<string, mixed>
     */
    public static function diagnose(): array
    {
        $info = [
            'available_backends' => self::available(),
            'backend'            => null,
            'connected'          => false,
            'ttl'                => self::$ttl,
            'self'               => self::$self?->toArray(),
            'elections'          => array_map(
                static fn (LeaderElection $e): array => $e->stats(),
                self::$elections
            ),
            'snowflake_worker'   => self::$snowflake?->workerId(),
        ];

        try {
            $store              = self::store();
            $info['backend']    = $store->name();
            $info['connected']  = true;
            $info['services']   = self::registry()->services();
            $info['node_count'] = count(self::nodes());
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * 清空门面状态（主要供测试与 worker 进程重建使用）。
     */
    public static function reset(): void
    {
        self::$store = null;
        self::resetDerived();
    }

    /** 清掉依赖 store 的派生对象。 */
    private static function resetDerived(): void
    {
        self::$registry  = null;
        self::$self      = null;
        self::$snowflake = null;
        self::$limiter   = null;
        self::$elections = [];
    }

    /**
     * 探测本机对外 IP。
     *
     * 用「连一个不发包的 UDP 目标再读本地地址」的技巧拿到真正的出口网卡地址，
     * 比 gethostbyname(gethostname()) 可靠——后者在多网卡或容器里经常返回 127.0.0.1。
     */
    public static function localIp(): string
    {
        $socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 0.2);

        if ($socket !== false) {
            $name = @stream_socket_get_name($socket, false);
            @fclose($socket);

            if (is_string($name) && $name !== '') {
                $pos = strrpos($name, ':');
                $ip  = $pos === false ? $name : substr($name, 0, $pos);

                if ($ip !== '' && $ip !== '0.0.0.0') {
                    return trim($ip, '[]');
                }
            }
        }

        $host = gethostbyname((string) gethostname());

        return $host !== '' ? $host : '127.0.0.1';
    }
}
