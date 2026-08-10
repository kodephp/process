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

    /**
     * 表达式 → 位掩码缓存上限。单进程内 distinct 的 cron 表达式数量有界且很小，
     * 设上限防止动态生成表达式造成的无界增长。
     *
     * @var array<string, array{minute:int,hour:int,mday:int,month:int,wday:int}>
     */
    private static array $cronMaskCache = [];

    private const int CRON_MASK_CACHE_LIMIT = 256;

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

    /**
     * 注册 cron 表达式定时器（进程内）。
     *
     * ⚠️ 多进程语义：cron 任务是**按进程**隔离的，master-worker 下每个 worker 各自触发同一表达式，
     * 会导致每个调度时刻**重复执行 N 次**、且 worker 崩溃即丢失。需要集群内「至多执行一次」时，
     * 改用 {@see \Kode\Process\Crontab\ClusterCron}（每任务分布式锁 / Leader 选举），或把执行交给
     * `Kode::queue()`（Redis 队列）。单进程 / 单 worker 场景无此问题，可放心使用本方法。
     */
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
        self::$cronMaskCache = [];
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

        $masks = self::getCronMasks($expression, $parts);

        $now = time();

        $limit = min(self::CRON_MAX_ITER, 525600);
        for ($i = 1; $i <= $limit; $i++) {
            $candidate = $now + ($i * 60);
            $date = getdate($candidate);

            // 五段全部命中才算匹配（标准 cron 语义）。位掩码版本把旧实现每轮 4 次
            // matchCronPart 字符串解析（内含 explode/str_contains/递归）替换为 5 次位运算，
            // 且解析结果按表达式缓存，扫描本身不再付出解析开销。
            if (((($masks['minute'] >> $date['minutes']) & 1) === 0) ||
                ((($masks['hour']   >> $date['hours'])   & 1) === 0) ||
                ((($masks['mday']   >> $date['mday'])    & 1) === 0) ||
                ((($masks['month']  >> $date['mon'])     & 1) === 0) ||
                ((($masks['wday']   >> $date['wday'])    & 1) === 0)
            ) {
                continue;
            }

            return (float) $candidate;
        }

        // 在扫描上限内未找到匹配（含永不匹配的表达式）：退化为 hourly 重算，
        // 避免对不可能命中的表达式反复做数十万次 getdate 造成秒级停顿。
        return microtime(true) + 3600;
    }

    /**
     * 把 cron 表达式的 5 个字段解析为位掩码并缓存。
     *
     * 旧实现每轮扫描都对 5 段做字符串解析（{@see matchCronPart()} 内含
     * explode / str_contains / 递归），单次 parseCronNext 在最坏情况下高达数万次
     * 解析。这里在每条表达式首次计算时用 matchCronPart 枚举各字段定义域一次性求出
     * 并集掩码，之后每个候选时间只需 5 次位运算。
     *
     * 同时修正了旧实现对字段顺序的错位：旧代码以 `$parts[4]/[3]/[2]/[1]` 依次匹配
     * wday / mday / hours / minutes，**整体偏移了一位**——分钟字段（`$parts[0]`）被
     * 完全忽略、而月份字段（`$parts[3]`）被当作日使用，导致 `15 10 5 * *` 之类表达式
     * 实际在「每天 05:10」触发而非「每月 5 号 10:15」。现按标准 cron 语义正确映射到
     * (minute, hour, mday, month, wday) 五个字段，并对全部五段做 AND 匹配。
     *
     * 掩码由 matchCronPart（行为权威、单测覆盖）构建，故优化本身不改变任何单字段的
     * 匹配语义，仅修正字段位置的错位。
     *
     * @param list<string> $parts 已按空白切分的 5 段表达式
     * @return array{minute:int,hour:int,mday:int,month:int,wday:int}
     */
    private static function getCronMasks(string $expression, array $parts): array
    {
        if (isset(self::$cronMaskCache[$expression])) {
            return self::$cronMaskCache[$expression];
        }

        // 标准 cron 字段顺序：分 时 日 月 周（5 段分别对应 $parts[0..4]）
        $domain = [
            'minute' => [0, 59],
            'hour'   => [0, 23],
            'mday'   => [1, 31],
            'month'  => [1, 12],
            'wday'   => [0, 6],
        ];
        $fieldOf = [
            'minute' => $parts[0],
            'hour'   => $parts[1],
            'mday'   => $parts[2],
            'month'  => $parts[3],
            'wday'   => $parts[4],
        ];

        $masks = [];
        foreach ($domain as $key => [$lo, $hi]) {
            $mask = 0;
            for ($v = $lo; $v <= $hi; $v++) {
                if (self::matchCronPart($fieldOf[$key], $v)) {
                    $mask |= (1 << $v);
                }
            }
            $masks[$key] = $mask;
        }

        if (count(self::$cronMaskCache) < self::CRON_MASK_CACHE_LIMIT) {
            self::$cronMaskCache[$expression] = $masks;
        }

        return $masks;
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
