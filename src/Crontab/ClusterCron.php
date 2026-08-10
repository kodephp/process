<?php

declare(strict_types=1);

namespace Kode\Process\Crontab;

use Kode\Process\Cluster;
use Kode\Process\Cluster\Election\LeaderElection;
use Kode\Process\Cluster\Lock\DistributedLock;
use Throwable;

/**
 * 多进程安全的 cron 守卫。
 *
 * ## 问题
 * 进程内 {@see Crontab} / {@see Kode::cron()} 是「每进程一份」的静态注册表。在 master-worker
 * 多进程（或水平扩展多机）下，每个 worker 都会独立注册并触发同一表达式 —— 于是**每个调度时刻
 * 被 N 个 worker 重复执行 N 次**，且某个 worker 崩溃即丢失其定时器。注意：**多进程本身并不能
 * 解决这个问题，反而把它放大了**；要让定时任务在集群内「至多执行一次」，必须引入协调权威。
 *
 * ## 解法（本类提供两种，均复用已内置的 Cluster 协调能力）
 *  1. **每任务分布式锁（{@see self::create()}）**：用 {@see Cluster::lock()} 给每个表达式加一把
 *     互斥锁 `kode:cron:<md5(expr)>`，只有抢到锁的 worker 才执行。无需指定单一调度进程，对
 *     Leader 切换 / 扩缩容天然 tolerant。存储后端自动择优（同机 file、跨机 redis），开箱即可
 *     协调**同机多进程**；跨主机需先 `Cluster::make('redis', ...)`。
 *  2. **Leader 选举（{@see self::tickOnLeader()}）**：仅由选举胜出的 Leader 进程推进整套 cron，
 *     保证强 exactly-once（推荐用于长任务 / 强一致场景）。
 *
 * ## 何时该用 Redis 队列而不是 cron
 * 若任务是**持久、可重试、崩溃不丢、需要背压/限速/失败重放**的（发邮件、对账、下发），不要依赖
 * 进程内 cron/timer —— 应改用 `Kode::queue()`（{@see Kode::queue()}，Redis 后端），由队列保证
 * 投递语义，cron 只负责「产生消息」。
 *
 * ## 失败语义
 * 协调存储不可用时（锁获取异常），守卫 fail-soft 退化为本地执行并告警一次，绝不静默丢弃任务
 * （代价是极端情况下可能出现重复，与无协调的同款行为一致）。
 */
final class ClusterCron
{
    private static bool $warned = false;

    /**
     * 注册一个「集群内至多执行一次」的 cron。
     *
     * @param array<int, mixed> $args        透传给回调的位置参数
     * @param float             $lockTtl     锁持有 TTL（秒）。应 >= 单轮任务预期耗时；
     *                                        超出后锁自动过期，另一 worker 方可接管（长任务建议
     *                                        改用 {@see self::tickOnLeader()} 以获得强 exactly-once）。
     *
     * @return Crontab 返回的实例可像普通 Crontab 一样 destroy()/pause()/resume()。
     */
    public static function create(
        string $expression,
        callable $callback,
        array $args = [],
        float $lockTtl = 30.0
    ): Crontab {
        $lockKey = 'kode:cron:' . \md5($expression);

        $wrapped = function () use ($callback, $args, $lockKey, $lockTtl): void {
            $lock = self::acquireLock($lockKey, $lockTtl);
            if ($lock === null) {
                // 协调存储不可用：退化为本地执行（接受潜在重复，fail-soft）
                ($callback)(...$args);
                return;
            }
            if (!$lock->tryAcquire()) {
                return; // 其他 worker 已抢到锁，本进程跳过
            }
            try {
                ($callback)(...$args);
            } finally {
                $lock->release();
            }
        };

        return Crontab::create($expression, $wrapped);
    }

    /**
     * 仅在当前进程是 Leader 时推进所有 cron；非 Leader 直接跳过。
     *
     * 适用于「整套定时任务只在一个节点跑」的强 exactly-once 场景。需周期性调用（通常放进
     * 主循环或与 Kode::tickTimers() 同处）。
     */
    public static function tickOnLeader(string $name = 'scheduler', float $electionTtl = 15.0): void
    {
        $elect = self::electionOrNull($name, $electionTtl);
        if ($elect === null) {
            Crontab::tickAll();
            return;
        }
        $elect->tick();
        if ($elect->isLeader()) {
            Crontab::tickAll();
        }
    }

    private static function acquireLock(string $key, float $ttl): ?DistributedLock
    {
        try {
            return Cluster::lock($key, $ttl);
        } catch (Throwable $e) {
            self::warnOnce(
                '[ClusterCron] 获取分布式锁失败，cron 退化为本地执行（多进程可能重复触发）：'
                . $e->getMessage()
            );
            return null;
        }
    }

    private static function electionOrNull(string $name, float $ttl): ?LeaderElection
    {
        try {
            return Cluster::election($name, ttl: $ttl);
        } catch (Throwable $e) {
            self::warnOnce(
                '[ClusterCron] 获取 Leader 选举实例失败，tickOnLeader 退化为本地 tickAll()：'
                . $e->getMessage()
            );
            return null;
        }
    }

    private static function warnOnce(string $message): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        \error_log($message);
    }
}
