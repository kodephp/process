<?php

declare(strict_types=1);

namespace Kode\Process\Compat;

use Kode\Process\Async\EventEmitter;

final class Timer
{
    public const TIMER_PERSISTENT = -1;

    private static array $timers = [];
    private static array $cronJobs = [];
    private static int $timerId = 0;
    private static int $cronId = 0;
    private static bool $initialized = false;
    private static float $lastTick = 0.0;
    private static array $stats = [
        'total_created' => 0,
        'total_executed' => 0,
        'total_removed' => 0,
    ];
    private static ?EventEmitter $emitter = null;

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

        $timerId = ++self::$timerId;

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

        $cronId = ++self::$cronId;

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
                    self::$stats['total_executed']++;
                    self::$timers[$id]['executed']++;
                } catch (\Throwable $e) {
                    self::$emitter?->emit('timer.error', [$id, $e]);
                }

                if ($timer['count'] === self::TIMER_PERSISTENT) {
                    self::$timers[$id]['run_at'] = $now + $timer['delay'];
                } elseif ($timer['remaining'] > 1) {
                    self::$timers[$id]['remaining']--;
                    self::$timers[$id]['run_at'] = $now + $timer['delay'];
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
                    self::$stats['total_executed']++;
                    self::$cronJobs[$id]['executed']++;
                } catch (\Throwable $e) {
                    self::$emitter?->emit('timer.error', [$id, $e]);
                }

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
    }

    public static function reset(): void
    {
        self::$timers = [];
        self::$cronJobs = [];
        self::$timerId = 0;
        self::$cronId = 0;
        self::$initialized = false;
        self::$lastTick = 0.0;
        self::$emitter = null;
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

        for ($i = 1; $i <= 525600; $i++) {
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

        return microtime(true) + 60;
    }

    private static function matchCronPart(string $part, int $value): bool
    {
        if ($part === '*') {
            return true;
        }

        if (strpos($part, '/') !== false) {
            [$range, $step] = explode('/', $part);
            $step = (int) $step;

            if ($range === '*') {
                return $value % $step === 0;
            }

            return false;
        }

        if (strpos($part, '-') !== false) {
            [$min, $max] = explode('-', $part);
            return $value >= (int) $min && $value <= (int) $max;
        }

        if (strpos($part, ',') !== false) {
            $values = array_map('intval', explode(',', $part));
            return in_array($value, $values, true);
        }

        return (int) $part === $value;
    }
}
