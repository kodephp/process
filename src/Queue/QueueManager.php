<?php

declare(strict_types=1);

namespace Kode\Process\Queue;

use Kode\Queue\Factory;
use Kode\Queue\QueueInterface;
use Kode\Process\Response;

/**
 * 队列管理器
 * 
 * 直接使用 kode/queue 包进行队列操作
 */
final class QueueManager
{
    private QueueInterface $queue;
    private string $defaultQueue;
    private array $handlers = [];
    private static ?self $instance = null;

    private function __construct(array $config = [])
    {
        $this->queue = Factory::create($config);
        $this->defaultQueue = $config['queue'] ?? 'default';
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 使用配置初始化队列
     */
    public static function init(array $config = []): self
    {
        self::$instance = new self($config);
        return self::$instance;
    }

    /**
     * 使用 Redis 驱动
     */
    public static function useRedis(array $config = []): self
    {
        return self::init(array_merge([
            'default' => 'redis',
            'connections' => [
                'redis' => array_merge([
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'database' => 0,
                ], $config)
            ]
        ], $config));
    }

    /**
     * 使用 Database 驱动
     */
    public static function useDatabase(array $config = []): self
    {
        return self::init(array_merge([
            'default' => 'database',
            'connections' => [
                'database' => $config
            ]
        ], $config));
    }

    /**
     * 使用同步驱动（开发测试）
     */
    public static function useSync(): self
    {
        return self::init(['default' => 'sync']);
    }

    /**
     * 推送任务到队列
     */
    public function dispatch(string $job, array $data = [], ?string $queue = null): string
    {
        $queue = $queue ?? $this->defaultQueue;
        return $this->queue->push($job, $data, $queue);
    }

    /**
     * 延迟推送任务
     */
    public function dispatchDelayed(string $job, array $data, int $delay, ?string $queue = null): string
    {
        $queue = $queue ?? $this->defaultQueue;
        return $this->queue->later($delay, $job, $data, $queue);
    }

    /**
     * 批量推送任务
     */
    public function dispatchBulk(array $jobs, array $data = [], ?string $queue = null): array
    {
        $queue = $queue ?? $this->defaultQueue;
        return $this->queue->bulk($jobs, $data, $queue);
    }

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
     */
    public function registerMany(array $handlers): self
    {
        foreach ($handlers as $job => $handler) {
            $this->handlers[$job] = $handler;
        }
        return $this;
    }

    /**
     * 处理一个任务
     */
    public function process(?string $queue = null): ?Response
    {
        $queue = $queue ?? $this->defaultQueue;
        $job = $this->queue->pop($queue);

        if ($job === null) {
            return null;
        }

        $jobData = is_array($job) ? $job : ['payload' => $job];
        $jobName = $jobData['job'] ?? 'unknown';
        $jobId = $jobData['id'] ?? uniqid();

        if (!isset($this->handlers[$jobName])) {
            $this->queue->delete($jobId, $queue);
            return Response::notFound("任务处理器不存在: {$jobName}");
        }

        try {
            $result = ($this->handlers[$jobName])($jobData['data'] ?? [], $jobData);
            $this->queue->delete($jobId, $queue);
            return Response::ok($result);
        } catch (\Throwable $e) {
            $this->queue->release(60, $jobId, $queue);
            return Response::error($e->getMessage());
        }
    }

    /**
     * 批量处理任务
     */
    public function processBatch(?string $queue = null, int $limit = 100): int
    {
        $processed = 0;
        for ($i = 0; $i < $limit; $i++) {
            if ($this->process($queue) === null) {
                break;
            }
            $processed++;
        }
        return $processed;
    }

    /**
     * 获取队列大小
     */
    public function size(?string $queue = null): int
    {
        return $this->queue->size($queue ?? $this->defaultQueue);
    }

    /**
     * 获取队列统计
     */
    public function stats(?string $queue = null): array
    {
        return $this->queue->stats($queue ?? $this->defaultQueue);
    }

    /**
     * 获取底层队列实例
     */
    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    /**
     * 获取已注册的处理器
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * 检查是否有处理器
     */
    public function hasHandler(string $job): bool
    {
        return isset($this->handlers[$job]);
    }
}
