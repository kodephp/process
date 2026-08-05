<?php

declare(strict_types=1);

namespace Kode\Process\Async;

final class EventEmitter implements EventEmitterInterface
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var array<string, list<callable>> */
    private array $onceListeners = [];

    private int $maxListeners = 1000;

    /** 标记当前是否正在派发 error 事件，用于阻断递归 */
    private bool $handlingError = false;

    #[\Override]
    public function on(string $event, callable $listener): self
    {
        $this->addListener($this->listeners, $event, $listener);
        return $this;
    }

    #[\Override]
    public function once(string $event, callable $listener): self
    {
        $this->addListener($this->onceListeners, $event, $listener);
        return $this;
    }

    #[\Override]
    public function off(string $event, ?callable $listener = null): self
    {
        if ($listener === null) {
            unset($this->listeners[$event], $this->onceListeners[$event]);
        } else {
            $this->removeListener($this->listeners, $event, $listener);
            $this->removeListener($this->onceListeners, $event, $listener);
        }

        return $this;
    }

    /**
     * 派发事件
     *
     * 采用普通可变参数，直接透传给监听器：
     *   $emitter->emit('data', $payload)     // 监听器收到 ($payload)
     *   $emitter->emit('data', $a, $b, $c)   // 监听器收到 ($a, $b, $c)
     *
     * @param mixed ...$args 透传给监听器的参数
     */
    #[\Override]
    public function emit(string $event, mixed ...$args): self
    {
        $listeners = $this->listeners[$event] ?? [];
        $onceListeners = $this->onceListeners[$event] ?? [];

        // 先摘除 once 队列再回调：若监听器在执行期间又注册了新的 once，
        // 原实现在遍历结束后统一 unset，会把新注册的一并误删。
        unset($this->onceListeners[$event]);

        foreach ($listeners as $listener) {
            $this->callListener($listener, $args);
        }

        foreach ($onceListeners as $listener) {
            $this->callListener($listener, $args);
        }

        return $this;
    }

    #[\Override]
    public function listeners(string $event): array
    {
        return array_merge(
            $this->listeners[$event] ?? [],
            $this->onceListeners[$event] ?? []
        );
    }

    #[\Override]
    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]) || !empty($this->onceListeners[$event]);
    }

    #[\Override]
    public function removeAllListeners(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
            $this->onceListeners = [];
        } else {
            unset($this->listeners[$event], $this->onceListeners[$event]);
        }

        return $this;
    }

    public function setMaxListeners(int $max): self
    {
        $this->maxListeners = $max;
        return $this;
    }

    public function getMaxListeners(): int
    {
        return $this->maxListeners;
    }

    public function listenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []) + count($this->onceListeners[$event] ?? []);
    }

    public function eventNames(): array
    {
        $events = array_merge(
            array_keys($this->listeners),
            array_keys($this->onceListeners)
        );

        return array_unique($events);
    }

    public function prependListener(string $event, callable $listener): self
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        array_unshift($this->listeners[$event], $listener);
        return $this;
    }

    public function prependOnceListener(string $event, callable $listener): self
    {
        if (!isset($this->onceListeners[$event])) {
            $this->onceListeners[$event] = [];
        }

        array_unshift($this->onceListeners[$event], $listener);
        return $this;
    }

    public static function onceStatic(EventEmitter $emitter, string $event, callable $listener): void
    {
        $emitter->once($event, $listener);
    }

    private function addListener(array &$storage, string $event, callable $listener): void
    {
        if (!isset($storage[$event])) {
            $storage[$event] = [];
        }

        if (count($storage[$event]) >= $this->maxListeners) {
            trigger_error(
                "Possible EventEmitter memory leak detected. {$event} has " . count($storage[$event]) . " listeners.",
                E_USER_WARNING
            );
        }

        $storage[$event][] = $listener;
    }

    private function removeListener(array &$storage, string $event, callable $listener): void
    {
        if (!isset($storage[$event])) {
            return;
        }

        // array_values 重排索引，避免多次 off 后留下空洞键
        $storage[$event] = array_values(array_filter(
            $storage[$event],
            static fn (callable $l): bool => $l !== $listener
        ));

        if ($storage[$event] === []) {
            unset($storage[$event]);
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function callListener(callable $listener, array $args): void
    {
        try {
            $listener(...$args);
        } catch (\Throwable $e) {
            $this->handleListenerError($e);
        }
    }

    /**
     * 监听器异常处理
     *
     * 原实现无条件 emit('error')，存在两个问题：
     * 1. error 监听器自身抛异常会再次触发 emit('error')，形成无限递归；
     * 2. 没有注册 error 监听器时异常被彻底吞掉，故障现场毫无痕迹。
     */
    private function handleListenerError(\Throwable $e): void
    {
        if ($this->handlingError) {
            trigger_error(
                'EventEmitter: error 监听器自身抛出异常 - ' . $e->getMessage(),
                E_USER_WARNING
            );
            return;
        }

        if (!$this->hasListeners('error')) {
            trigger_error(
                'EventEmitter: 未处理的监听器异常 - ' . $e->getMessage(),
                E_USER_WARNING
            );
            return;
        }

        $this->handlingError = true;

        try {
            $this->emit('error', $e);
        } finally {
            $this->handlingError = false;
        }
    }
}
