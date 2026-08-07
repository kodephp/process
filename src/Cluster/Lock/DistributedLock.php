<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Lock;

use Kode\Process\Cluster\Store\StoreInterface;
use Kode\Process\Exceptions\ClusterException;
use Throwable;

/**
 * 分布式互斥锁。
 *
 * 基于存储层的原子 `setIfAbsent`（Redis 即 `SET NX PX`）实现，具备生产级锁必须的四个性质：
 *
 * 1. **互斥**——同一时刻只有一个持有者。
 * 2. **防死锁**——锁自带 TTL，持有者进程崩溃后自动释放。
 * 3. **防误删**——释放走 `compareAndDelete`，只有令牌匹配才删得掉；本节点锁超时后被
 *    他人重新获得时，本节点的 `release()` 不会误删别人的锁。
 * 4. **可续期**——长任务用 {@see refresh()} 续命（看门狗），避免业务没跑完锁先过期。
 *
 * ```php
 * $lock = Cluster::lock('order:1001', ttl: 30.0);
 *
 * // 写法一：托管执行，异常也保证释放
 * $result = $lock->withLock(fn () => $this->settle(1001), wait: 5.0);
 *
 * // 写法二：手动控制
 * if ($lock->acquire(wait: 5.0)) {
 *     try { $this->settle(1001); } finally { $lock->release(); }
 * }
 * ```
 *
 * 支持同一实例可重入：嵌套 `acquire()` 只增加计数，最外层 `release()` 才真正释放。
 *
 * @since 5.0.0
 */
final class DistributedLock
{
    /** 本实例的持有令牌，全局唯一。 */
    private readonly string $token;

    /** 重入计数，0 表示未持有。 */
    private int $depth = 0;

    /** 最近一次成功获取/续期的时刻。 */
    private float $acquiredAt = 0.0;

    /**
     * @param string     $key   锁名（会自动加 `lock/` 前缀落库）
     * @param float      $ttl   锁存活时间（秒）。必须大于临界区预期耗时，或配合 refresh() 续期。
     * @param string|null $owner 持有者标识，便于排查是谁拿着锁；默认取 主机名:PID
     */
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $key,
        private readonly float $ttl = 30.0,
        ?string $owner = null,
    ) {
        $owner ??= gethostname() . ':' . getmypid();

        // 令牌 = 可读的持有者 + 随机串，既能排查又能保证唯一
        $this->token = $owner . ':' . bin2hex(random_bytes(8));
    }

    private function storeKey(): string
    {
        return 'lock/' . $this->key;
    }

    private function ttlMs(): int
    {
        return (int) round($this->ttl * 1000);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function ttl(): float
    {
        return $this->ttl;
    }

    /** 本实例当前是否持有锁。 */
    public function isHeld(): bool
    {
        return $this->depth > 0;
    }

    /** 重入深度。 */
    public function depth(): int
    {
        return $this->depth;
    }

    /** 锁是否被任何人持有（含其它节点）。 */
    public function isLocked(): bool
    {
        return $this->store->exists($this->storeKey());
    }

    /**
     * 当前持有者标识（含其它节点）；无人持有返回 null。
     *
     * 返回的是令牌里可读的那一段（构造时传入的 $owner），去掉末尾随机串——
     * 排查「这把锁卡在谁手上」时直接可读。完整令牌见 {@see token()}。
     */
    public function owner(): ?string
    {
        $token = $this->store->get($this->storeKey());

        if (!is_string($token)) {
            return null;
        }

        // 令牌格式 {owner}:{rand}，owner 自身可能含冒号（默认是 主机名:PID），故从右侧切
        $pos = strrpos($token, ':');

        return $pos === false ? $token : substr($token, 0, $pos);
    }

    /** 距锁自动过期还剩多少秒（本实例视角，非精确剩余 TTL）。 */
    public function remaining(): float
    {
        if ($this->depth === 0) {
            return 0.0;
        }

        return max(0.0, $this->ttl - (microtime(true) - $this->acquiredAt));
    }

    /**
     * 尝试获取锁（不等待）。
     */
    public function tryAcquire(): bool
    {
        if ($this->depth > 0) {
            $this->depth++;
            return true;
        }

        if ($this->store->setIfAbsent($this->storeKey(), $this->token, $this->ttlMs())) {
            $this->depth      = 1;
            $this->acquiredAt = microtime(true);
            return true;
        }

        return false;
    }

    /**
     * 获取锁，最多等待 $wait 秒。
     *
     * @param float $wait          最长等待秒数，0 表示不等待
     * @param float $retryInterval 重试间隔秒数（会做指数退避，上限 0.5s）
     */
    public function acquire(float $wait = 0.0, float $retryInterval = 0.02): bool
    {
        if ($this->tryAcquire()) {
            return true;
        }

        if ($wait <= 0.0) {
            return false;
        }

        $deadline = microtime(true) + $wait;
        $sleep    = max(0.001, $retryInterval);

        while (microtime(true) < $deadline) {
            // 睡到超时点为止，避免最后一轮超睡
            $left = $deadline - microtime(true);
            usleep((int) (min($sleep, $left) * 1_000_000));

            if ($this->tryAcquire()) {
                return true;
            }

            // 指数退避，削减高竞争下的存储层压力
            $sleep = min($sleep * 2, 0.5);
        }

        return false;
    }

    /**
     * 获取锁，失败抛异常。
     *
     * @throws ClusterException
     */
    public function acquireOrFail(float $wait = 0.0): void
    {
        if (!$this->acquire($wait)) {
            throw ClusterException::lockFailed($this->key, $wait);
        }
    }

    /**
     * 续期（看门狗）。
     *
     * 只有令牌仍匹配才续得上——锁已被他人接管时返回 false，此时应立即中止临界区。
     */
    public function refresh(?float $ttl = null): bool
    {
        if ($this->depth === 0) {
            return false;
        }

        $ttlMs = (int) round(($ttl ?? $this->ttl) * 1000);

        // compareAndSet 保证「只续自己的锁」
        $ok = $this->store->compareAndSet($this->storeKey(), $this->token, $this->token, $ttlMs);

        if ($ok) {
            $this->acquiredAt = microtime(true);
        } else {
            // 锁已易主，本实例不再持有
            $this->depth = 0;
        }

        return $ok;
    }

    /**
     * 释放锁。
     *
     * 可重入：只有最外层释放才真正删键。
     */
    public function release(): bool
    {
        if ($this->depth === 0) {
            return false;
        }

        if (--$this->depth > 0) {
            return true;
        }

        $this->acquiredAt = 0.0;

        return $this->store->compareAndDelete($this->storeKey(), $this->token);
    }

    /**
     * 在锁保护下执行回调，无论正常返回还是抛异常都保证释放。
     *
     * @template T
     * @param  callable(self): T $fn
     * @return T
     * @throws ClusterException 未能在 $wait 内获取到锁
     */
    public function withLock(callable $fn, float $wait = 0.0): mixed
    {
        $this->acquireOrFail($wait);

        try {
            return $fn($this);
        } finally {
            $this->release();
        }
    }

    /**
     * 尝试在锁保护下执行；拿不到锁则返回 $default，不抛异常。
     *
     * 适合「同一任务集群内只需跑一次，抢不到就跳过」的场景。
     *
     * @template T
     * @param  callable(self): T $fn
     * @return T|null
     */
    public function tryWithLock(callable $fn, mixed $default = null): mixed
    {
        if (!$this->tryAcquire()) {
            return $default;
        }

        try {
            return $fn($this);
        } finally {
            $this->release();
        }
    }

    /**
     * 强制解锁（无视持有者）。
     *
     * 仅限运维兜底——正常业务流程不应调用，它会打破互斥保证。
     */
    public function forceRelease(): bool
    {
        $this->depth = 0;

        return $this->store->delete($this->storeKey());
    }

    /** 析构兜底：进程退出前尽力释放，避免让别人白等一个 TTL。 */
    public function __destruct()
    {
        try {
            while ($this->depth > 0) {
                $this->release();
            }
        } catch (Throwable) {
            // 析构期间存储层可能已不可用，交给 TTL 自动过期
        }
    }
}
