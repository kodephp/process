<?php

declare(strict_types=1);

namespace Kode\Process\Async;

final class EventEmitter implements EventEmitterInterface
{
    private array $listeners = [];
    private array $onceListeners = [];
    private int $maxListeners = 1000;

    public function on(string $event, callable $listener): self
    {
        $this->addListener($this->listeners, $event, $listener);
        return $this;
    }

    public function once(string $event, callable $listener): self
    {
        $this->addListener($this->onceListeners, $event, $listener);
        return $this;
    }

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

    public function emit(string $event, array $args = []): self
    {
        $listeners = $this->listeners[$event] ?? [];
        $onceListeners = $this->onceListeners[$event] ?? [];

        foreach ($listeners as $listener) {
            $this->callListener($listener, $args);
        }

        foreach ($onceListeners as $listener) {
            $this->callListener($listener, $args);
        }

        unset($this->onceListeners[$event]);

        return $this;
    }

    public function listeners(string $event): array
    {
        return array_merge(
            $this->listeners[$event] ?? [],
            $this->onceListeners[$event] ?? []
        );
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]) || !empty($this->onceListeners[$event]);
    }

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

        $storage[$event] = array_filter(
            $storage[$event],
            fn($l) => $l !== $listener
        );

        if (empty($storage[$event])) {
            unset($storage[$event]);
        }
    }

    private function callListener(callable $listener, array $args): void
    {
        try {
            $listener(...$args);
        } catch (\Throwable $e) {
            $this->emit('error', [$e]);
        }
    }
}
