<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Election;

use Kode\Process\Cluster\Store\StoreInterface;

/**
 * Leader 选举——让集群里「只应跑一次」的任务真的只跑一次。
 *
 * 典型用途：定时任务、数据补偿、缓存预热。这些活儿在 N 台机器上部署了同一份代码，
 * 但只能有一台执行，否则就会重复扣款、重复发券。
 *
 * 实现是「带 TTL 的抢占式租约」：谁先原子写入键谁当 Leader，Leader 周期续租；
 * Leader 崩溃后租约到期，其余节点立刻接管。
 *
 * ```php
 * $election = Cluster::election('cron', nodeId: 'node-1', ttl: 15.0);
 *
 * $election->onElected(fn () => Kode::monitor()->log('本节点当选 Leader'))
 *          ->onResigned(fn () => Kode::monitor()->log('本节点让出 Leader'));
 *
 * // 放进事件循环周期驱动（间隔建议 ttl / 3）
 * Kode::every(5.0, function () use ($election) {
 *     if ($election->tick()) {
 *         $this->runClusterWideCron();      // 全集群唯一执行点
 *     }
 * });
 * ```
 *
 * 一致性边界：这是基于单一存储的租约锁，不是 Raft。在存储层发生脑裂、
 * 或 Leader 卡顿超过 TTL 的极端情形下，可能短暂出现双 Leader。
 * 对正确性要求到金融级的场景，请在业务侧再加一层幂等键。
 *
 * @since 5.0.0
 */
final class LeaderElection
{
    /** 本节点的租约令牌。 */
    private readonly string $token;

    /** 本节点当前是否为 Leader。 */
    private bool $leader = false;

    /** @var list<callable(self): void> */
    private array $onElected = [];

    /** @var list<callable(self): void> */
    private array $onResigned = [];

    /** 累计当选次数，可用于观测选举抖动。 */
    private int $terms = 0;

    /**
     * @param string $name   选举名（同名节点竞争同一个 Leader 位）
     * @param string $nodeId 本节点标识，写入租约值便于排查当前 Leader 是谁
     * @param float  $ttl    租约时长（秒）。Leader 崩溃后最多 ttl 秒完成切换。
     */
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $name,
        private readonly string $nodeId,
        private readonly float $ttl = 15.0,
    ) {
        $this->token = $nodeId . ':' . getmypid() . ':' . bin2hex(random_bytes(6));
    }

    private function storeKey(): string
    {
        return 'election/' . $this->name;
    }

    private function ttlMs(): int
    {
        return (int) round($this->ttl * 1000);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    public function ttl(): float
    {
        return $this->ttl;
    }

    /** 本节点是否为 Leader（本地缓存视图，由 tick() 刷新）。 */
    public function isLeader(): bool
    {
        return $this->leader;
    }

    /** 累计当选次数。 */
    public function terms(): int
    {
        return $this->terms;
    }

    /** 建议的驱动间隔——租约的三分之一，留足两次重试余量。 */
    public function suggestedInterval(): float
    {
        return max(0.5, $this->ttl / 3);
    }

    /**
     * 当前 Leader 的节点 ID；无人当选返回 null。
     *
     * 直接读存储，反映集群真实状态（非本地缓存）。
     */
    public function leaderId(): ?string
    {
        $token = $this->store->get($this->storeKey());
        if (!is_string($token)) {
            return null;
        }

        // 令牌格式 nodeId:pid:rand，取首段
        $pos = strpos($token, ':');

        return $pos === false ? $token : substr($token, 0, $pos);
    }

    /**
     * 驱动一次选举：Leader 则续租，非 Leader 则尝试抢占。
     *
     * 应当周期调用（见 {@see suggestedInterval()}）。状态变化时触发回调。
     *
     * @return bool 本次调用后本节点是否为 Leader
     */
    public function tick(): bool
    {
        $was = $this->leader;
        $now = $this->leader ? $this->renew() : $this->campaign();

        if ($now && !$was) {
            $this->terms++;
            $this->leader = true;
            $this->fire($this->onElected);
        } elseif (!$now && $was) {
            $this->leader = false;
            $this->fire($this->onResigned);
        } else {
            $this->leader = $now;
        }

        return $this->leader;
    }

    /**
     * 尝试抢占 Leader 位。
     *
     * 刻意保持私有：它只动存储、不动状态机与回调，单独调用会造出
     * 「抢到了但 isLeader() 仍为 false」的错位。对外只暴露 {@see tick()}。
     */
    private function campaign(): bool
    {
        return $this->store->setIfAbsent($this->storeKey(), $this->token, $this->ttlMs());
    }

    /**
     * 续租。只有令牌匹配才续得上；租约已被他人抢走时返回 false。
     */
    private function renew(): bool
    {
        return $this->store->compareAndSet($this->storeKey(), $this->token, $this->token, $this->ttlMs());
    }

    /**
     * 主动让出 Leader（优雅下线时调用，让接管在毫秒级完成而不是等一个 TTL）。
     */
    public function resign(): bool
    {
        $released = $this->store->compareAndDelete($this->storeKey(), $this->token);

        if ($this->leader) {
            $this->leader = false;
            $this->fire($this->onResigned);
        }

        return $released;
    }

    /**
     * 仅当本节点是 Leader 时执行回调。
     *
     * @template T
     * @param  callable(): T $fn
     * @return T|null
     */
    public function ifLeader(callable $fn): mixed
    {
        return $this->tick() ? $fn() : null;
    }

    /** @param callable(self): void $callback */
    public function onElected(callable $callback): self
    {
        $this->onElected[] = $callback;

        return $this;
    }

    /** @param callable(self): void $callback */
    public function onResigned(callable $callback): self
    {
        $this->onResigned[] = $callback;

        return $this;
    }

    /** @param list<callable(self): void> $callbacks */
    private function fire(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($this);
        }
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'name'      => $this->name,
            'node_id'   => $this->nodeId,
            'is_leader' => $this->leader,
            'leader_id' => $this->leaderId(),
            'terms'     => $this->terms,
            'ttl'       => $this->ttl,
        ];
    }
}
