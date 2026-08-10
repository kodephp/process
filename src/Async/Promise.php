<?php

declare(strict_types=1);

namespace Kode\Process\Async;

final class Promise
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    /** 累计构造实例数，仅用于压测与诊断（单次整数自增，热路径开销可忽略）。 */
    public static int $instances = 0;

    private string $state = self::PENDING;
    private mixed $value = null;
    private mixed $reason = null;
    private array $onFulfilled = [];
    private array $onRejected = [];

    public function __construct(callable $executor)
    {
        self::$instances++;

        try {
            $executor(
                fn($value) => $this->doResolve($value),
                fn($reason) => $this->doReject($reason)
            );
        } catch (\Throwable $e) {
            $this->doReject($e);
        }
    }

    public static function resolve(mixed $value = null): self
    {
        return new self(fn($resolve) => $resolve($value));
    }

    public static function reject(mixed $reason = null): self
    {
        return new self(fn($_, $reject) => $reject($reason));
    }

    public static function all(array $promises): self
    {
        return Async::all($promises);
    }

    public static function race(array $promises): self
    {
        return Async::race($promises);
    }

    public static function any(array $promises): self
    {
        return self::doAny($promises);
    }

    public static function allSettled(array $promises): self
    {
        return self::doAllSettled($promises);
    }

    private static function doAny(array $promises): self
    {
        return new self(function ($resolve, $reject) use ($promises) {
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

    private static function doAllSettled(array $promises): self
    {
        return new self(function ($resolve) use ($promises) {
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

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $promise = new self(function ($resolve, $reject) use ($onFulfilled, $onRejected) {
            $fulfilledHandler = function ($value) use ($onFulfilled, $resolve, $reject) {
                if ($onFulfilled === null) {
                    $resolve($value);
                    return;
                }

                try {
                    $result = $onFulfilled($value);
                    $this->resolvePromise($result, $resolve, $reject);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            };

            $rejectedHandler = function ($reason) use ($onRejected, $resolve, $reject) {
                if ($onRejected === null) {
                    $reject($reason);
                    return;
                }

                try {
                    $result = $onRejected($reason);
                    $this->resolvePromise($result, $resolve, $reject);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            };

            if ($this->state === self::FULFILLED) {
                Async::queueMicrotask(fn() => $fulfilledHandler($this->value));
            } elseif ($this->state === self::REJECTED) {
                Async::queueMicrotask(fn() => $rejectedHandler($this->reason));
            } else {
                $this->onFulfilled[] = $fulfilledHandler;
                $this->onRejected[] = $rejectedHandler;
            }
        });

        return $promise;
    }

    /**
     * 仅登记回调、不派生新 Promise。
     *
     * `doResolve`/`resolvePromise` 在链式传导时曾调用 `$value->then(...)`，仅为把
     * 内部转发回调挂到目标 Promise 上，却会额外构造一个立即丢弃的 Promise（连同其
     * executor 闭包与两个 handler 闭包）。长链、嵌套、await、all/allSettled 场景下
     * 这些一次性对象占可观的分配与 GC 压力。`subscribe` 只做 `then()` 中 PENDING 分支
     * 的登记动作，调用方拿到的是目标 Promise 自身的状态机，零派生。
     */
    private function subscribe(callable $onFulfilled, callable $onRejected): void
    {
        if ($this->state === self::FULFILLED) {
            Async::queueMicrotask(fn() => $onFulfilled($this->value));
        } elseif ($this->state === self::REJECTED) {
            Async::queueMicrotask(fn() => $onRejected($this->reason));
        } else {
            $this->onFulfilled[] = $onFulfilled;
            $this->onRejected[] = $onRejected;
        }
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFinally): self
    {
        return $this->then(
            function ($value) use ($onFinally) {
                $onFinally();
                return $value;
            },
            function ($reason) use ($onFinally) {
                $onFinally();

                // reason 允许是任意值。对非 Throwable 直接 throw 会触发
                // "Can only throw objects"，该 Error 被 then() 捕获后取代原始拒因，
                // 调用方拿到的将是无关的语言层错误。按 await() 的既有约定包装。
                throw $reason instanceof \Throwable
                    ? $reason
                    : new \RuntimeException((string) $reason);
            }
        );
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getReason(): mixed
    {
        return $this->reason;
    }

    public function await(): mixed
    {
        while ($this->state === self::PENDING) {
            if (\Fiber::getCurrent() !== null) {
                \Fiber::suspend();
                continue;
            }

            // 非 Fiber 上下文下没有外层事件循环替我们推进：then() 的回调是经
            // Async::queueMicrotask 入队的，只 usleep 空转会让微任务永远没人执行，
            // 于是 Promise::resolve(1)->then(...)->await() 直接死锁。这里由 await
            // 自身充当事件循环，排空微任务/定时器/延迟任务后再让出 CPU。
            Async::tick();

            if ($this->state === self::PENDING) {
                usleep(1000);
            }
        }

        if ($this->state === self::REJECTED) {
            throw $this->reason instanceof \Throwable
                ? $this->reason
                : new \RuntimeException((string) $this->reason);
        }

        return $this->value;
    }

    private function doResolve(mixed $value): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        if ($value instanceof self) {
            $value->subscribe(
                fn($v) => $this->doResolve($v),
                fn($r) => $this->doReject($r)
            );
            return;
        }

        $this->state = self::FULFILLED;
        $this->value = $value;

        foreach ($this->onFulfilled as $callback) {
            Async::queueMicrotask(fn() => $callback($value));
        }

        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    private function doReject(mixed $reason): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        $this->state = self::REJECTED;
        $this->reason = $reason;

        foreach ($this->onRejected as $callback) {
            Async::queueMicrotask(fn() => $callback($reason));
        }

        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    private function resolvePromise(mixed $result, callable $resolve, callable $reject): void
    {
        if ($result instanceof self) {
            $result->subscribe($resolve, $reject);
        } else {
            $resolve($result);
        }
    }
}
