<?php

declare(strict_types=1);

namespace Kode\Process\Queue;

use Generator;
use Kode\Process\Response;
use Kode\Queue\Contract\QueueInterface;
use Kode\Queue\Enum\Capability;
use Kode\Queue\Enum\DriverType;
use Kode\Queue\Message\ReservedJob;
use Kode\Queue\QueueManager as Backend;
use Kode\Queue\Support\QueueStats;

/**
 * 队列管理器（kode/queue ^2.1 适配层）
 *
 * 在 kode/queue 之上提供「处理器注册 + 单条/批量消费」的进程侧封装：
 * 底层保持 kode/queue 的不可变消息对象、可见性超时与至少一次投递语义，
 * 本类只负责把 {@see ReservedJob} 路由到已注册的处理器并完成 ack / fail。
 *
 * kode/queue 2.x 相对 1.x 的破坏性变更（本类已吸收）：
 * - `Kode\Queue\Factory` 移除，改为 {@see Backend::make()} / {@see Backend::auto()}
 * - `Kode\Queue\QueueInterface` 迁移到 `Kode\Queue\Contract\QueueInterface`
 * - `pop()` 返回 {@see ReservedJob} 对象而非数组
 * - `delete($id)` 语义改为 `ack(ReservedJob)`；`release()` 首参改为 ReservedJob
 * - `stats()` 返回 {@see QueueStats} 值对象而非数组
 */
final class QueueManager
{
    private Backend $backend;

    private QueueInterface $queue;

    private string $defaultQueue;

    /** @var array<string, callable> */
    private array $handlers = [];

    private static ?self $instance = null;

    /**
     * @param array<string, mixed> $config kode/queue 配置数组；为空则零配置自动选驱动
     */
    private function __construct(array $config = [])
    {
        $this->backend = $config === [] ? Backend::auto() : Backend::make($config);
        $this->queue = $this->backend->default();
        $this->defaultQueue = (string) ($config['queue'] ?? $this->queue->getDefaultQueue());
    }

    /**
     * 获取单例实例（首次调用零配置自动选驱动：Redis → Database → Memory）
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * 使用配置初始化队列
     *
     * @param array<string, mixed> $config
     */
    public static function init(array $config = []): self
    {
        return self::$instance = new self($config);
    }

    /**
     * 释放单例（主要供测试与 fork 后子进程重建连接使用）
     */
    public static function reset(): void
    {
        self::$instance?->close();
        self::$instance = null;
    }

    /**
     * 使用内存驱动（开发 / 测试 / 压测，无需外部服务）
     */
    public static function useMemory(): self
    {
        return self::withDriver(DriverType::Memory);
    }

    /**
     * 使用同步驱动（投递即执行，便于本地调试）
     */
    public static function useSync(): self
    {
        return self::withDriver(DriverType::Sync);
    }

    /**
     * 使用 Redis 驱动（生产推荐）
     */
    public static function useRedis(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        int $database = 0,
        array $options = [],
    ): self {
        return self::withDriver(DriverType::Redis, array_merge([
            'host' => $host,
            'port' => $port,
            'password' => $password,
            'database' => $database,
        ], $options));
    }

    /**
     * 使用数据库驱动
     *
     * @param array<string, mixed> $options 至少包含 dsn / username / password / table
     */
    public static function useDatabase(array $options = []): self
    {
        return self::withDriver(DriverType::Database, $options);
    }

    /**
     * 按驱动类型初始化
     *
     * @param array<string, mixed> $options
     */
    public static function withDriver(DriverType $driver, array $options = []): self
    {
        $connection = array_filter(
            array_merge(['driver' => $driver->value], $options),
            static fn (mixed $v): bool => $v !== null,
        );

        return self::init([
            'default' => $driver->value,
            'connections' => [$driver->value => $connection],
        ]);
    }

    /**
     * 当前驱动是否可用（缺扩展 / 缺客户端时为 false）
     */
    public static function driverAvailable(DriverType $driver): bool
    {
        return $driver->isAvailable();
    }

    // ------------------------------------------------------------------
    // 投递
    // ------------------------------------------------------------------

    /**
     * 推送任务到队列
     *
     * @param array<string, mixed> $data
     * @return string 任务 ID
     */
    public function dispatch(string|object $job, array $data = [], ?string $queue = null): string
    {
        return $this->queue->push($job, $data, $queue ?? $this->defaultQueue);
    }

    /**
     * 延迟推送任务
     *
     * @param array<string, mixed> $data
     */
    public function dispatchDelayed(string|object $job, array $data, int $delay, ?string $queue = null): string
    {
        return $this->queue->later($delay, $job, $data, $queue ?? $this->defaultQueue);
    }

    /**
     * 批量推送任务
     *
     * @param iterable<mixed>      $jobs
     * @param array<string, mixed> $data 公共数据
     * @return list<string>
     */
    public function dispatchBulk(iterable $jobs, array $data = [], ?string $queue = null): array
    {
        return $this->queue->bulk($jobs, $data, $queue ?? $this->defaultQueue);
    }

    /**
     * 批量延迟推送
     *
     * @param iterable<mixed>      $jobs
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public function dispatchBulkLater(iterable $jobs, int $delay, array $data = [], ?string $queue = null): array
    {
        return $this->queue->bulkLater($delay, $jobs, $data, $queue ?? $this->defaultQueue);
    }

    // ------------------------------------------------------------------
    // 处理器
    // ------------------------------------------------------------------

    /**
     * 注册任务处理器
     */
    public function register(string $job, callable $handler): self
    {
        $this->handlers[$job] = $handler;

        return $this;
    }

    /**
     * 批量注册处理器
     *
     * @param array<string, callable> $handlers
     */
    public function registerMany(array $handlers): self
    {
        foreach ($handlers as $job => $handler) {
            $this->handlers[$job] = $handler;
        }

        return $this;
    }

    /**
     * 移除处理器
     */
    public function unregister(string $job): self
    {
        unset($this->handlers[$job]);

        return $this;
    }

    /** @return array<string, callable> */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function hasHandler(string $job): bool
    {
        return isset($this->handlers[$job]);
    }

    // ------------------------------------------------------------------
    // 消费
    // ------------------------------------------------------------------

    /**
     * 处理一个任务
     *
     * @param float $timeout 阻塞等待秒数，0 为非阻塞
     * @return Response|null 队列为空时返回 null
     */
    public function process(?string $queue = null, float $timeout = 0.0): ?Response
    {
        $reserved = $this->queue->pop($queue ?? $this->defaultQueue, $timeout);

        if ($reserved === null) {
            return null;
        }

        return $this->handle($reserved);
    }

    /**
     * 执行一个已预留任务，并按结果 ack / fail
     */
    public function handle(ReservedJob $reserved): Response
    {
        $name = $reserved->job->name;

        if (!isset($this->handlers[$name])) {
            // 没有处理器：直接判失败，交由死信存储，避免无限重投
            $this->queue->fail($reserved, new \RuntimeException("任务处理器不存在: {$name}"));

            return Response::notFound("任务处理器不存在: {$name}");
        }

        try {
            $result = ($this->handlers[$name])($reserved->job->payload, $reserved);
            $this->queue->ack($reserved);

            return Response::ok($result);
        } catch (\Throwable $e) {
            if ($reserved->job->canRetry()) {
                $this->queue->release($reserved, $reserved->job->nextRetryDelay());
            } else {
                $this->queue->fail($reserved, $e);
            }

            return Response::error($e->getMessage());
        }
    }

    /**
     * 批量处理任务，返回实际处理条数
     */
    public function processBatch(?string $queue = null, int $limit = 100, float $timeout = 0.0): int
    {
        $processed = 0;

        for ($i = 0; $i < $limit; $i++) {
            if ($this->process($queue, $timeout) === null) {
                break;
            }
            $processed++;
        }

        return $processed;
    }

    /**
     * 以生成器方式持续消费（每条自动路由到处理器）
     *
     * @return Generator<int, Response>
     */
    public function consume(?string $queue = null, ?int $limit = null, float $timeout = 1.0): Generator
    {
        foreach ($this->queue->consume($queue ?? $this->defaultQueue, $limit, $timeout) as $reserved) {
            yield $this->handle($reserved);
        }
    }

    // ------------------------------------------------------------------
    // 观测与运维
    // ------------------------------------------------------------------

    public function size(?string $queue = null): int
    {
        return $this->queue->size($queue ?? $this->defaultQueue);
    }

    /**
     * 获取队列统计（数组形态，便于日志 / JSON 输出）
     *
     * @return array<string, mixed>
     */
    public function stats(?string $queue = null): array
    {
        return $this->snapshot($queue)->toArray();
    }

    /**
     * 获取队列统计值对象
     */
    public function snapshot(?string $queue = null): QueueStats
    {
        return $this->queue->stats($queue ?? $this->defaultQueue);
    }

    /**
     * 清空队列，返回清除条数
     */
    public function clear(?string $queue = null): int
    {
        return $this->queue->clear($queue ?? $this->defaultQueue);
    }

    /**
     * 当前驱动是否支持某能力（延迟 / 优先级 / 批量 / 阻塞拉取等）
     */
    public function supports(Capability $capability): bool
    {
        return $this->queue->supports($capability);
    }

    /**
     * 驱动自检信息
     *
     * @return array<string, mixed>
     */
    public function diagnose(): array
    {
        return $this->backend->diagnose();
    }

    public function getDefaultQueue(): string
    {
        return $this->defaultQueue;
    }

    public function setDefaultQueue(string $queue): self
    {
        $this->defaultQueue = $queue;

        return $this;
    }

    /**
     * 获取底层 kode/queue 门面
     */
    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    /**
     * 获取底层 kode/queue 管理器（多连接 / 中间件 / 事件）
     */
    public function getBackend(): Backend
    {
        return $this->backend;
    }

    public function close(): void
    {
        $this->backend->close();
    }
}
