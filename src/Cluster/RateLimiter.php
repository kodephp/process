<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

use Kode\Process\Cluster\Store\StoreInterface;
use Kode\Process\Exceptions\ClusterException;

/**
 * 分布式限流器。
 *
 * 计数落在共享存储上，因此限的是**整个集群**的总量，而不是每台机器各限一份
 * ——后者会让实际放行量变成「配置值 × 机器数」。
 *
 * 提供两种算法，按需选择：
 *
 * | 方法            | 算法       | 特点                                              |
 * |-----------------|------------|---------------------------------------------------|
 * | {@see attempt()} | 固定窗口   | 一次自增搞定，开销最低；窗口边界可能出现两倍瞬时量 |
 * | {@see consume()} | 令牌桶     | 平滑限速且允许突发，能精确控制长期速率            |
 *
 * ```php
 * $limiter = Cluster::limiter();
 *
 * // 每个用户每分钟最多 100 次
 * if (!$limiter->attempt("api:{$userId}", limit: 100, window: 60.0)) {
 *     return Kode::error('请求过于频繁', 429);
 * }
 *
 * // 平滑限速：容量 20、每秒补 5 个令牌
 * if (!$limiter->consume('sms:send', capacity: 20, refillPerSecond: 5.0)) {
 *     return Kode::error('短信发送超速');
 * }
 * ```
 *
 * @since 5.0.0
 */
final class RateLimiter
{
    /** 令牌桶 CAS 竞争时的最大重试次数。 */
    private const MAX_CAS_RETRY = 8;

    public function __construct(private readonly StoreInterface $store)
    {
    }

    private function counterKey(string $key, float $window): string
    {
        // 把时间切成固定窗口，窗口号进键名——窗口自然滚动，无需清理旧计数
        $slot = (int) floor(microtime(true) / max(0.001, $window));

        return 'rate/' . $key . '/' . $slot;
    }

    /**
     * 固定窗口计数限流。
     *
     * @param  string $key    限流维度（用户 ID、IP、接口名…）
     * @param  int    $limit  窗口内允许的最大次数
     * @param  float  $window 窗口长度（秒）
     * @param  int    $cost   本次消耗的配额，默认 1
     * @return bool   true=放行，false=超限
     */
    public function attempt(string $key, int $limit, float $window = 60.0, int $cost = 1): bool
    {
        if ($limit <= 0) {
            return false;
        }

        $current = $this->store->increment(
            $this->counterKey($key, $window),
            $cost,
            // TTL 给两倍窗口，确保跨窗口读取剩余量时旧计数还在
            (int) round($window * 2 * 1000)
        );

        return $current <= $limit;
    }

    /**
     * 当前窗口剩余可用次数。
     */
    public function remaining(string $key, int $limit, float $window = 60.0): int
    {
        $used = $this->store->get($this->counterKey($key, $window));

        return max(0, $limit - (is_int($used) ? $used : 0));
    }

    /**
     * 当前窗口还剩多少秒结束。
     */
    public function resetsIn(float $window = 60.0): float
    {
        $window = max(0.001, $window);

        return $window - fmod(microtime(true), $window);
    }

    /**
     * 令牌桶限流：平滑限速，同时允许一定突发。
     *
     * 桶初始装满 `capacity` 个令牌，之后按 `refillPerSecond` 匀速补充（不超过容量）。
     * 每次请求取走 `tokens` 个，取不到就拒绝。
     *
     * 长期平均速率被钳在 `refillPerSecond`，短时最多放行 `capacity` 个——
     * 这正是「允许突发但不允许持续超速」的语义。
     *
     * @param  int   $capacity        桶容量（最大突发量）
     * @param  float $refillPerSecond 每秒补充的令牌数（长期平均速率）
     * @param  int   $tokens          本次取用的令牌数
     * @return bool  true=放行
     */
    public function consume(string $key, int $capacity, float $refillPerSecond, int $tokens = 1): bool
    {
        if ($capacity <= 0 || $refillPerSecond <= 0.0 || $tokens <= 0) {
            return false;
        }

        $storeKey = 'bucket/' . $key;

        // 桶空后完全补满所需的时间，作为 TTL——闲置更久的桶直接过期，下次视同满桶
        $ttlMs = (int) round(($capacity / $refillPerSecond) * 2 * 1000) + 1000;

        for ($retry = 0; $retry < self::MAX_CAS_RETRY; $retry++) {
            $now     = microtime(true);
            $current = $this->store->get($storeKey);

            if (!is_array($current) || !isset($current['t'], $current['ts'])) {
                // 桶不存在：以满桶为初值创建
                $available = (float) $capacity;
                $next      = ['t' => $available - $tokens, 'ts' => $now];

                if ($available < $tokens) {
                    return false;
                }
                if ($this->store->setIfAbsent($storeKey, $next, $ttlMs)) {
                    return true;
                }

                // 并发下被别人抢先创建了，重来一轮读改写
                continue;
            }

            // 按流逝时间补充令牌，上限为容量
            $elapsed   = max(0.0, $now - (float) $current['ts']);
            $available = min((float) $capacity, (float) $current['t'] + $elapsed * $refillPerSecond);

            if ($available < $tokens) {
                return false;
            }

            $next = ['t' => $available - $tokens, 'ts' => $now];

            if ($this->store->compareAndSet($storeKey, $current, $next, $ttlMs)) {
                return true;
            }
        }

        // 竞争过于激烈：拒绝而非放行，宁可少放也不超卖
        return false;
    }

    /**
     * 桶中当前可用令牌数（只读，不消耗）。
     */
    public function tokens(string $key, int $capacity, float $refillPerSecond): float
    {
        $current = $this->store->get('bucket/' . $key);

        if (!is_array($current) || !isset($current['t'], $current['ts'])) {
            return (float) $capacity;
        }

        $elapsed = max(0.0, microtime(true) - (float) $current['ts']);

        return min((float) $capacity, (float) $current['t'] + $elapsed * $refillPerSecond);
    }

    /**
     * 限流保护下执行回调。
     *
     * @template T
     * @param  callable(): T $fn
     * @param  callable():mixed|null $onLimited 超限回调；为 null 时抛异常
     * @return T|mixed
     * @throws ClusterException 超限且未提供 $onLimited
     */
    public function throttle(
        string $key,
        int $limit,
        float $window,
        callable $fn,
        ?callable $onLimited = null,
    ): mixed {
        if ($this->attempt($key, $limit, $window)) {
            return $fn();
        }

        if ($onLimited !== null) {
            return $onLimited();
        }

        throw new ClusterException(
            sprintf('触发限流：%s 在 %.1f 秒窗口内已达上限 %d 次', $key, $window, $limit)
        );
    }

    /** 清空某个维度的限流计数与令牌桶。 */
    public function reset(string $key, float $window = 60.0): bool
    {
        $this->store->delete($this->counterKey($key, $window));

        return $this->store->delete('bucket/' . $key);
    }
}
