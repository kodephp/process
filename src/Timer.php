<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Async\EventEmitter;

/**
 * 进程内定时器管理器（手动驱动）。
 *
 * 与 {@see Reactor\LoopInterface::addTimer()} 的区别：
 *  - Reactor 定时器由事件循环自动触发，适合已经跑在事件循环里的服务
 *  - 本类需要外部周期调用 {@see Timer::tick()}，适合自定义主循环、批处理进程，
 *    以及需要 pause / resume 与 cron 表达式的编排场景
 *
 * 自 4.0.0 起由 `Kode\Process\Compat\Timer` 提升为顶层 `Kode\Process\Timer`。
 */
final class Timer
{
    public const TIMER_PERSISTENT = -1;

    /**
     * 最小触发间隔。持久/重复定时器若 delay<=0 会在每个 tick 必触发，
     * 形成 100% CPU 忙轮询；重排时 clamp 到此下限即可避免。
     */
    public const MIN_DELAY = 0.001;

    /**
     * parseCronNext 单次扫描上限（约 32 天分钟数）。超出则退化为 hourly 重算，
     * 避免对永不匹配的表达式（如 2 月 30 日）做数十万次 getdate 造成秒级停顿。
     */
    public const CRON_MAX_ITER = 46080;

    private static array $timers = [];
    private static array $cronJobs = [];
    /**
     * 定时器与 cron 任务共用同一自增 ID 序列。
     *
     * 二者原先各有一条独立序列（timerId / cronId），但 del/pause/resume/getTimer
     * 都是「先查 timers 再查 cronJobs」，编号一旦数值相撞（如 timer#3 与 cron#3
     * 共存）就会删/暂停错对象。共用单一序列可从根本上杜绝 ID 冲突。
     */
    private static int $nextId = 0;
    private static bool $initialized = false;
    private static float $lastTick = 0.0;
    private static array $stats = [
        'total_created' => 0,
        'total_executed' => 0,
        'total_removed' => 0,
    ];
    private static ?EventEmitter $emitter = null;
    private static bool $errorListenerRegistered = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;
        self::$emitter = new EventEmitter();
    }

    public static function create(float $delay, callable $callback, array $args = [], int $count = self::TIMER_PERSISTENT): int
    {
        self::init();

        $timerId = ++self::$nextId;

        self::$timers[$timerId] = [
            'delay' => $delay,
            'callback' => $callback,
            'args' => $args,
            'count' => $count,
            'remaining' => $count,
            'run_at' => microtime(true) + $delay,
            'created_at' => microtime(true),
            'executed' => 0,
            'paused' => false,
        ];

        self::$stats['total_created']++;

        return $timerId;
    }

    public static function add(float $delay, callable $callback, array $args = [], bool $persistent = true): int
    {
        return self::create(
            $delay,
            $callback,
            $args,
            $persistent ? self::TIMER_PERSISTENT : 1
        );
    }

    public static function once(float $delay, callable $callback, array $args = []): int
    {
        return self::create($delay, $callback, $args, 1);
    }

    public static function repeat(float $delay, callable $callback, int $count, array $args = []): int
    {
        return self::create($delay, $callback, $args, $count);
    }

    public static function forever(float $delay, callable $callback, array $args = []): int
    {
        return self::create($delay, $callback, $args, self::TIMER_PERSISTENT);
    }

    public static function immediate(callable $callback, array $args = []): int
    {
        return self::create(0, $callback, $args, 1);
    }

    public static function delay(float $delay, callable $callback, array $args = []): int
    {
        return self::once($delay, $callback, $args);
    }

    public static function cron(string $expression, callable $callback, array $args = []): int
    {
        self::init();

        $cronId = ++self::$nextId;

        self::$cronJobs[$cronId] = [
            'expression' => $expression,
            'callback' => $callback,
            'args' => $args,
            'next_run' => self::parseCronNext($expression),
            'created_at' => microtime(true),
            'executed' => 0,
            'paused' => false,
        ];

        self::$stats['total_created']++;

        return $cronId;
    }

    public static function del(int $timerId): bool
    {
        if (isset(self::$timers[$timerId])) {
            unset(self::$timers[$timerId]);
            self::$stats['total_removed']++;
            return true;
        }

        if (isset(self::$cronJobs[$timerId])) {
            unset(self::$cronJobs[$timerId]);
            self::$stats['total_removed']++;
            return true;
        }

        return false;
    }

    public static function delAll(): void
    {
        $count = count(self::$timers) + count(self::$cronJobs);
        self::$stats['total_removed'] += $count;
        self::$timers = [];
        self::$cronJobs = [];
    }

    public static function pause(int $timerId): bool
    {
        if (isset(self::$timers[$timerId])) {
            self::$timers[$timerId]['paused'] = true;
            return true;
        }

        if (isset(self::$cronJobs[$timerId])) {
            self::$cronJobs[$timerId]['paused'] = true;
            return true;
        }

        return false;
    }

    public static function resume(int $timerId): bool
    {
        if (isset(self::$timers[$timerId])) {
            self::$timers[$timerId]['paused'] = false;
            return true;
        }

        if (isset(self::$cronJobs[$timerId])) {
            self::$cronJobs[$timerId]['paused'] = false;
            self::$cronJobs[$timerId]['next_run'] = self::parseCronNext(
                self::$cronJobs[$timerId]['expression']
            );
            return true;
        }

        return false;
    }

    public static function tick(): void
    {
        $now = microtime(true);
        self::$lastTick = $now;

        foreach (self::$timers as $id => $timer) {
            if ($timer['paused']) {
                continue;
            }

            if ($now >= $timer['run_at']) {
                try {
                    ($timer['callback'])(...$timer['args']);
                } catch (\Throwable $e) {
                    self::$emitter?->emit('timer.error', $id, $e);
                    if (!self::$errorListenerRegistered) {
                        trigger_error(
                            "Timer #{$id} callback threw: " . $e->getMessage(),
                            E_USER_WARNING
                        );
                    }
                }

                // 回调可能在执行期间 del/delAll 本定时器，必须复检避免把已删条目
                // 复活成残缺数组（自增/重排会 auto-vivify 出只剩部分键的数组，
                // 下一 tick 访问 paused/count 等键即崩溃）。复检必须早于任何写入。
                if (!isset(self::$timers[$id])) {
                    continue;
                }

                self::$stats['total_executed']++;
                self::$timers[$id]['executed']++;

                if ($timer['count'] === self::TIMER_PERSISTENT) {
                    self::$timers[$id]['run_at'] = $now + max($timer['delay'], self::MIN_DELAY);
                } elseif ($timer['remaining'] > 1) {
                    self::$timers[$id]['remaining']--;
                    self::$timers[$id]['run_at'] = $now + max($timer['delay'], self::MIN_DELAY);
                } else {
                    unset(self::$timers[$id]);
                    self::$stats['total_removed']++;
                }
            }
        }

        foreach (self::$cronJobs as $id => $cron) {
            if ($cron['paused']) {
                continue;
            }

            if ($now >= $cron['next_run']) {
                try {
                    ($cron['callback'])(...$cron['args']);
                } catch (\Throwable $e) {
                    self::$emitter?->emit('timer.error', $id, $e);
                }

                // 回调可能在执行期间 del/delAll 本 cron 任务：复检避免把已删条目复活成
                // 残缺数组（缺 expression/callback 键，下一 tick 访问即崩溃）。复检必须
                // 早于任何写入（含 executed 计数），与上方 timers 路径完全一致。
                if (!isset(self::$cronJobs[$id])) {
                    continue;
                }

                self::$stats['total_executed']++;
                self::$cronJobs[$id]['executed']++;

                self::$cronJobs[$id]['next_run'] = self::parseCronNext($cron['expression']);
            }
        }
    }

    public static function count(): int
    {
        return count(self::$timers) + count(self::$cronJobs);
    }

    public static function countTimers(): int
    {
        return count(self::$timers);
    }

    public static function countCronJobs(): int
    {
        return count(self::$cronJobs);
    }

    public static function setTimeout(float $delay, callable $callback): int
    {
        return self::once($delay, $callback);
    }

    public static function setInterval(float $delay, callable $callback): int
    {
        return self::forever($delay, $callback);
    }

    public static function clearTimeout(int $timerId): bool
    {
        return self::del($timerId);
    }

    public static function clearInterval(int $timerId): bool
    {
        return self::del($timerId);
    }

    public static function getTimer(int $timerId): ?array
    {
        return self::$timers[$timerId] ?? self::$cronJobs[$timerId] ?? null;
    }

    public static function getTimers(): array
    {
        return self::$timers;
    }

    public static function getCronJobs(): array
    {
        return self::$cronJobs;
    }

    public static function getStats(): array
    {
        return [
            ...self::$stats,
            'active_timers' => count(self::$timers),
            'active_cron_jobs' => count(self::$cronJobs),
            'last_tick' => self::$lastTick,
        ];
    }

    public static function getStatus(): array
    {
        return [
            'initialized' => self::$initialized,
            'timers' => array_map(function ($timer) {
                return [
                    'delay' => $timer['delay'],
                    'remaining' => $timer['remaining'],
                    'executed' => $timer['executed'],
                    'run_at' => $timer['run_at'],
                    'paused' => $timer['paused'],
                ];
            }, self::$timers),
            'cron_jobs' => array_map(function ($cron) {
                return [
                    'expression' => $cron['expression'],
                    'next_run' => $cron['next_run'],
                    'executed' => $cron['executed'],
                    'paused' => $cron['paused'],
                ];
            }, self::$cronJobs),
            'stats' => self::$stats,
        ];
    }

    public static function onError(callable $callback): void
    {
        self::init();
        self::$emitter?->on('timer.error', $callback);
        self::$errorListenerRegistered = true;
    }

    public static function reset(): void
    {
        self::$timers = [];
        self::$cronJobs = [];
        self::$nextId = 0;
        self::$initialized = false;
        self::$lastTick = 0.0;
        self::$emitter = null;
        self::$errorListenerRegistered = false;
        self::$stats = [
            'total_created' => 0,
            'total_executed' => 0,
            'total_removed' => 0,
        ];
    }

    private static function parseCronNext(string $expression): float
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            return microtime(true) + 60;
        }

        $now = time();

        $limit = min(self::CRON_MAX_ITER, 525600);
        for ($i = 1; $i <= $limit; $i++) {
            $candidate = $now + ($i * 60);
            $date = getdate($candidate);

            if (self::matchCronPart($parts[4], $date['wday']) &&
                self::matchCronPart($parts[3], $date['mday']) &&
                self::matchCronPart($parts[2], $date['hours']) &&
                self::matchCronPart($parts[1], $date['minutes'])
            ) {
                return (float) $candidate;
            }
        }

        // 在扫描上限内未找到匹配（含永不匹配的表达式）：退化为 hourly 重算，
        // 避免对不可能命中的表达式反复做数十万次 getdate 造成秒级停顿。
        return microtime(true) + 3600;
    }

    /**
     * 匹配单个 cron 字段。
     *
     * 支持 `*`、`a-b` 区间、`a/b` 步长、`a,b,c` 枚举，以及它们的组合（如 `1,2-5`）。
     * 逗号枚举优先拆分，每个子项再递归匹配，从而正确支持 `1,2-5` = {1,2,3,4,5}
     * 与 `1-30/5` 这种「区间 + 步长」组合。
     */
    private static function matchCronPart(string $part, int $value): bool
    {
        if ($part === '*') {
            return true;
        }

        if (str_contains($part, ',')) {
            foreach (explode(',', $part) as $sub) {
                if (self::matchCronPart($sub, $value)) {
                    return true;
                }
            }

            return false;
        }

        if (str_contains($part, '/')) {
            [$range, $step] = explode('/', $part, 2);
            $step = (int) $step;

            if ($step <= 0) {
                return false;
            }

            if ($range === '*') {
                return $value % $step === 0;
            }

            if (str_contains($range, '-')) {
                [$min, $max] = explode('-', $range, 2);
                $min = (int) $min;
                $max = (int) $max;

                return $value >= $min && $value <= $max && ($value - $min) % $step === 0;
            }

            return $value % $step === 0;
        }

        if (str_contains($part, '-')) {
            [$min, $max] = explode('-', $part, 2);
            return $value >= (int) $min && $value <= (int) $max;
        }

        return (int) $part === $value;
    }
}
