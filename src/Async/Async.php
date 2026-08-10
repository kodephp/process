<?php

declare(strict_types=1);

namespace Kode\Process\Async;

final class Async
{
    private static ?EventEmitter $globalEmitter = null;
    private static array $deferred = [];
    private static array $microtasks = [];
    private static array $timers = [];
    private static array $intervals = [];
    private static int $timerId = 0;
    private static bool $running = false;
    /** 最近一次到期的绝对时刻（含 timers 与 intervals）；null 表示需要惰性重算。 */
    private static ?float $nextTimerDue = null;

    public static function getEmitter(): EventEmitter
    {
        if (self::$globalEmitter === null) {
            self::$globalEmitter = new EventEmitter();
        }

        return self::$globalEmitter;
    }

    public static function on(string $event, callable $listener): void
    {
        self::getEmitter()->on($event, $listener);
    }

    public static function once(string $event, callable $listener): void
    {
        self::getEmitter()->once($event, $listener);
    }

    public static function off(string $event, ?callable $listener = null): void
    {
        self::getEmitter()->off($event, $listener);
    }

    public static function emit(string $event, mixed ...$args): void
    {
        self::getEmitter()->emit($event, ...$args);
    }

    public static function defer(callable $callback): void
    {
        self::$deferred[] = $callback;
    }

    public static function queueMicrotask(callable $callback): void
    {
        self::$microtasks[] = $callback;
    }

    public static function runDeferred(): void
    {
        while (!empty(self::$deferred)) {
            $callbacks = self::$deferred;
            self::$deferred = [];

            foreach ($callbacks as $callback) {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    self::emit('error', $e);
                }
            }
        }
    }

    public static function runMicrotasks(): void
    {
        while (!empty(self::$microtasks)) {
            $callbacks = self::$microtasks;
            self::$microtasks = [];

            foreach ($callbacks as $callback) {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    self::emit('error', $e);
                }
            }
        }
    }

    public static function run(): void
    {
        self::$running = true;

        while (self::$running) {
            self::tick();

            if (empty(self::$deferred) && empty(self::$microtasks)) {
                $sleepMs = 1000;

                // 自适应休眠：若存在最近的定时器到期点，把空转压缩到「刚好够到它」，
                // 而不是恒定 1ms 盲等——既降低空闲 CPU，又把亚秒级定时器的延迟
                // 从 1ms 粒度降到到期点精度。
                if (self::$nextTimerDue !== null) {
                    $remainingUs = (self::$nextTimerDue - microtime(true)) * 1e6;
                    if ($remainingUs > 0 && $remainingUs < $sleepMs) {
                        $sleepMs = (int) $remainingUs;
                    }
                }

                usleep($sleepMs);
            }
        }
    }

    public static function tick(): void
    {
        self::runMicrotasks();
        // 定时器必须由事件循环驱动：此前 tick() 从不调用 processTimers()，
        // 导致 setTimeout/setInterval/setImmediate/delay 在 Async::run() 下永不触发。
        self::processTimers();
        self::runDeferred();
    }

    public static function stop(): void
    {
        self::$running = false;
    }

    public static function setTimeout(callable $callback, float $delay): int
    {
        $id = ++self::$timerId;
        $startTime = microtime(true) + $delay;

        self::$timers[$id] = [
            'callback' => $callback,
            'start_time' => $startTime,
            'delay' => $delay,
        ];

        self::$nextTimerDue = self::$nextTimerDue === null
            ? $startTime
            : min(self::$nextTimerDue, $startTime);

        return $id;
    }

    public static function setInterval(callable $callback, float $interval): int
    {
        $id = ++self::$timerId;
        $nextTime = microtime(true) + $interval;

        self::$intervals[$id] = [
            'callback' => $callback,
            'next_time' => $nextTime,
            'interval' => $interval,
        ];

        self::$nextTimerDue = self::$nextTimerDue === null
            ? $nextTime
            : min(self::$nextTimerDue, $nextTime);

        return $id;
    }

    public static function clearTimeout(int $id): void
    {
        unset(self::$timers[$id]);
        self::$nextTimerDue = null;
    }

    public static function clearInterval(int $id): void
    {
        unset(self::$intervals[$id]);
        self::$nextTimerDue = null;
    }

    public static function setImmediate(callable $callback): int
    {
        return self::setTimeout($callback, 0);
    }

    public static function clearImmediate(int $id): void
    {
        self::clearTimeout($id);
    }

    public static function nextTick(callable $callback): void
    {
        self::queueMicrotask($callback);
    }

    public static function processTimers(): void
    {
        $now = microtime(true);

        // 无定时器到期时直接返回：此前每 tick 都全量扫描 timers+intervals，
        // 空闲事件循环因此恒定付出 O(N) 比较成本。缓存最近到期点后改为 O(1) 判定。
        if (self::$nextTimerDue !== null && $now < self::$nextTimerDue) {
            return;
        }

        foreach (self::$timers as $id => $timer) {
            if ($now >= $timer['start_time']) {
                unset(self::$timers[$id]);
                self::defer($timer['callback']);
            }
        }

        foreach (self::$intervals as $id => $interval) {
            if ($now >= $interval['next_time']) {
                self::$intervals[$id]['next_time'] = $now + $interval['interval'];
                self::defer($interval['callback']);
            }
        }

        // 触发后重新计算最近到期点；若都已清空则置 null（下一轮 add 会恢复）。
        self::$nextTimerDue = self::computeNextDue();
    }

    /**
     * 计算 timers 与 intervals 中最早的到期绝对时刻；两者皆空返回 null。
     * 仅在所有定时器都已处理完毕后调用，故本身不参与每 tick 的热路径。
     */
    private static function computeNextDue(): ?float
    {
        $due = null;

        foreach (self::$timers as $timer) {
            $due = $due === null ? $timer['start_time'] : min($due, $timer['start_time']);
        }

        foreach (self::$intervals as $interval) {
            $due = $due === null ? $interval['next_time'] : min($due, $interval['next_time']);
        }

        return $due;
    }

    public static function promisify(callable $callback): callable
    {
        return function (...$args) use ($callback) {
            return new Promise(function ($resolve, $reject) use ($callback, $args) {
                $args[] = function ($error, $result = null) use ($resolve, $reject) {
                    if ($error !== null) {
                        $reject($error);
                    } else {
                        $resolve($result);
                    }
                };

                try {
                    $callback(...$args);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        };
    }

    public static function all(array $promises): Promise
    {
        return new Promise(function ($resolve, $reject) use ($promises) {
            $results = [];
            $completed = 0;
            $total = count($promises);

            if ($total === 0) {
                $resolve([]);
                return;
            }

            foreach ($promises as $key => $promise) {
                $promise->then(
                    function ($value) use ($key, &$results, &$completed, $total, $resolve) {
                        $results[$key] = $value;
                        $completed++;

                        if ($completed === $total) {
                            $resolve($results);
                        }
                    },
                    $reject
                );
            }
        });
    }

    public static function race(array $promises): Promise
    {
        return new Promise(function ($resolve, $reject) use ($promises) {
            foreach ($promises as $promise) {
                $promise->then($resolve, $reject);
            }
        });
    }

    public static function any(array $promises): Promise
    {
        return new Promise(function ($resolve, $reject) use ($promises) {
            $errors = [];
            $rejected = 0;
            $total = count($promises);

            if ($total === 0) {
                $reject(new \RuntimeException('No promises provided'));
                return;
            }

            foreach ($promises as $key => $promise) {
                $promise->then(
                    $resolve,
                    function ($error) use ($key, &$errors, &$rejected, $total, $reject) {
                        $errors[$key] = $error;
                        $rejected++;

                        if ($rejected === $total) {
                            $reject(new \RuntimeException('All promises were rejected'));
                        }
                    }
                );
            }
        });
    }

    public static function allSettled(array $promises): Promise
    {
        return new Promise(function ($resolve) use ($promises) {
            $results = [];
            $completed = 0;
            $total = count($promises);

            if ($total === 0) {
                $resolve([]);
                return;
            }

            foreach ($promises as $key => $promise) {
                $promise->then(
                    function ($value) use ($key, &$results, &$completed, $total, $resolve) {
                        $results[$key] = ['status' => 'fulfilled', 'value' => $value];
                        $completed++;

                        if ($completed === $total) {
                            $resolve($results);
                        }
                    },
                    function ($reason) use ($key, &$results, &$completed, $total, $resolve) {
                        $results[$key] = ['status' => 'rejected', 'reason' => $reason];
                        $completed++;

                        if ($completed === $total) {
                            $resolve($results);
                        }
                    }
                );
            }
        });
    }

    public static function sleep(float $seconds): void
    {
        if (\Fiber::getCurrent() !== null) {
            $startTime = microtime(true);

            while ((microtime(true) - $startTime) < $seconds) {
                \Fiber::suspend();
            }
        } else {
            usleep((int) ($seconds * 1000000));
        }
    }

    public static function wait(callable $condition, float $timeout = 0, float $interval = 0.01): bool
    {
        $startTime = microtime(true);

        while (!$condition()) {
            if ($timeout > 0 && (microtime(true) - $startTime) >= $timeout) {
                return false;
            }

            self::sleep($interval);
        }

        return true;
    }

    public static function retry(callable $callback, int $maxAttempts = 3, float $delay = 0.1): Promise
    {
        return new Promise(function ($resolve, $reject) use ($callback, $maxAttempts, $delay) {
            $attempts = 0;

            $try = function () use ($callback, $maxAttempts, $delay, &$attempts, $resolve, $reject, &$try) {
                $attempts++;

                try {
                    $result = $callback();

                    if ($result instanceof Promise) {
                        $result->then($resolve, function ($e) use ($maxAttempts, $delay, &$attempts, $reject, $try) {
                            if ($attempts >= $maxAttempts) {
                                $reject($e);
                            } else {
                                self::defer(function () use ($delay, $try) {
                                    self::sleep($delay);
                                    $try();
                                });
                            }
                        });
                    } else {
                        $resolve($result);
                    }
                } catch (\Throwable $e) {
                    if ($attempts >= $maxAttempts) {
                        $reject($e);
                    } else {
                        self::defer(function () use ($delay, $try) {
                            self::sleep($delay);
                            $try();
                        });
                    }
                }
            };

            $try();
        });
    }

    public static function timeout(Promise $promise, float $seconds): Promise
    {
        return self::race([
            $promise,
            new Promise(function ($_, $reject) use ($seconds) {
                self::defer(function () use ($reject, $seconds) {
                    self::sleep($seconds);
                    $reject(new \RuntimeException("Promise timed out after {$seconds} seconds"));
                });
            }),
        ]);
    }

    public static function delay(float $seconds): Promise
    {
        return new Promise(function ($resolve) use ($seconds) {
            self::setTimeout(function () use ($resolve) {
                $resolve(null);
            }, $seconds);
        });
    }

    public static function each(array $items, callable $callback, int $concurrency = 10): Promise
    {
        return new Promise(function ($resolve, $reject) use ($items, $callback, $concurrency) {
            $results = [];
            $index = 0;
            $total = count($items);
            $pending = 0;
            $keys = array_keys($items);

            if ($total === 0) {
                $resolve([]);
                return;
            }

            $process = function () use (&$index, &$pending, &$results, $items, $keys, $callback, $total, $resolve, $reject, $concurrency, &$process) {
                $limit = max(1, $concurrency);
                while ($index < $total && $pending < $limit) {
                    $key = $keys[$index];
                    $item = $items[$key];
                    $currentIndex = $index;
                    $index++;
                    $pending++;

                    $result = $callback($item, $key);

                    if ($result instanceof Promise) {
                        $result->then(
                            function ($value) use ($key, &$results, &$pending, $total, $resolve, &$process) {
                                $results[$key] = $value;
                                $pending--;
                                $process();
                            },
                            $reject
                        );
                    } else {
                        $results[$key] = $result;
                        $pending--;
                    }
                }

                if ($index >= $total && $pending === 0) {
                    $resolve($results);
                }
            };

            $process();
        });
    }

    public static function map(array $items, callable $callback, int $concurrency = 10): Promise
    {
        return self::each($items, $callback, $concurrency);
    }

    public static function filter(array $items, callable $callback, int $concurrency = 10): Promise
    {
        return new Promise(function ($resolve) use ($items, $callback, $concurrency) {
            self::each($items, function ($item, $key) use ($callback) {
                $result = $callback($item, $key);

                if ($result instanceof Promise) {
                    return $result->then(fn($v) => ['keep' => $v, 'item' => $item, 'key' => $key]);
                }

                return ['keep' => $result, 'item' => $item, 'key' => $key];
            }, $concurrency)->then(function ($results) use ($resolve) {
                $filtered = [];

                foreach ($results as $key => $result) {
                    if ($result['keep']) {
                        $filtered[$result['key']] = $result['item'];
                    }
                }

                $resolve($filtered);
            });
        });
    }

    public static function reduce(array $items, callable $callback, mixed $initial = null): Promise
    {
        return new Promise(function ($resolve) use ($items, $callback, $initial) {
            $accumulator = $initial;
            $keys = array_keys($items);
            $index = 0;
            $total = count($items);

            $process = function () use (&$index, &$accumulator, $items, $keys, $callback, $total, $resolve, &$process) {
                if ($index >= $total) {
                    $resolve($accumulator);
                    return;
                }

                $key = $keys[$index];
                $item = $items[$key];
                $index++;

                $result = $callback($accumulator, $item, $key);

                if ($result instanceof Promise) {
                    $result->then(function ($value) use (&$accumulator, &$process) {
                        $accumulator = $value;
                        $process();
                    });
                } else {
                    $accumulator = $result;
                    $process();
                }
            };

            $process();
        });
    }

    public static function getStatus(): array
    {
        return [
            'deferred_count' => count(self::$deferred),
            'microtask_count' => count(self::$microtasks),
            'timer_count' => count(self::$timers),
            'interval_count' => count(self::$intervals),
            'running' => self::$running,
        ];
    }

    public static function reset(): void
    {
        self::$globalEmitter = null;
        self::$deferred = [];
        self::$microtasks = [];
        self::$timers = [];
        self::$intervals = [];
        self::$nextTimerDue = null;
        self::$running = false;
    }
}
