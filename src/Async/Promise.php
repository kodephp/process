<?php

declare(strict_types=1);

namespace Kode\Process\Async;

final class Promise
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    private string $state = self::PENDING;
    private mixed $value = null;
    private mixed $reason = null;
    private array $onFulfilled = [];
    private array $onRejected = [];

    public function __construct(callable $executor)
    {
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
                throw $reason;
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
            } else {
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
            $value->then(
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
            $result->then($resolve, $reject);
        } else {
            $resolve($result);
        }
    }
}
