<?php

declare(strict_types=1);

namespace Kode\Process\Signal;

use Kode\Process\Signal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 信号调度器
 * 
 * 提供更高级的信号管理功能，包括事件系统和信号优先级
 */
class SignalDispatcher
{
    private SignalHandler $handler;

    private LoggerInterface $logger;

    private array $eventListeners = [];

    private array $signalGroups = [];

    private int $priorityCounter = 0;

    private bool $propagationStopped = false;

    private array $handledSignals = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->handler = SignalHandler::getInstance($logger);
        $this->logger = $logger ?? new NullLogger();
    }

    public function on(int $signal, callable $listener, int $priority = 0): string
    {
        // 稳定的唯一事件 id（不依赖 spl_object_hash，避免 off() 时无法匹配）
        $eventId = 'evt_' . (++$this->priorityCounter);

        $this->eventListeners[$signal][$eventId] = [
            'listener' => $listener,
            'priority' => $priority,
        ];

        uasort($this->eventListeners[$signal], function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });

        if (!$this->handler->hasHandler($signal)) {
            $this->handler->register($signal, function (int $sig) use ($signal) {
                $this->dispatchEvent($sig);
            });
        }

        $this->logger->debug('注册信号监听器: {name} (优先级: {priority})', [
            'name' => Signal::getName($signal),
            'priority' => $priority,
        ]);

        return $eventId;
    }

    public function once(int $signal, callable $listener, int $priority = 0): string
    {
        $wrapper = function (int $sig, ...$args) use ($listener, $signal, &$wrapper) {
            $result = ($listener)($sig, ...$args);
            $this->off($signal, $wrapper);
            return $result;
        };

        return $this->on($signal, $wrapper, $priority);
    }

    public function off(int $signal, callable|string $listener): void
    {
        if (is_string($listener)) {
            unset($this->eventListeners[$signal][$listener]);
            return;
        }

        // 按可调用对象身份（===）匹配，避免 spl_object_hash((object)$listener)
        // 每次生成新 stdClass 导致永远匹配不上、监听器泄漏。
        foreach ($this->eventListeners[$signal] ?? [] as $id => $data) {
            if (($data['listener'] ?? null) === $listener) {
                unset($this->eventListeners[$signal][$id]);
            }
        }
    }

    public function offAll(int $signal): void
    {
        unset($this->eventListeners[$signal]);
        $this->logger->debug('移除信号 {name} 的所有监听器', ['name' => Signal::getName($signal)]);
    }

    private function dispatchEvent(int $signal): void
    {
        $this->propagationStopped = false;
        $this->handledSignals[$signal] = true;

        $listeners = $this->eventListeners[$signal] ?? [];

        foreach ($listeners as $id => $data) {
            if ($this->propagationStopped) {
                break;
            }

            try {
                ($data['listener'])($signal);
            } catch (\Throwable $e) {
                $this->logger->error('信号监听器执行失败 [%s]: %s', [
                    Signal::getName($signal),
                    $e->getMessage()
                ]);
            }
        }

        $this->logger->debug('信号事件已分发: {name} ({count} 个监听器)', [
            'name' => Signal::getName($signal),
            'count' => count($listeners),
        ]);
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function createSignalGroup(string $name, array $signals): void
    {
        $this->signalGroups[$name] = $signals;

        foreach ($signals as $signal) {
            if (!isset($this->eventListeners[$signal])) {
                $this->eventListeners[$signal] = [];
            }
        }

        $this->logger->debug('创建信号组: {name} ({count} 个信号)', [
            'name' => $name,
            'count' => count($signals),
        ]);
    }

    public function dispatchToGroup(string $groupName, int $signal): bool
    {
        if (!isset($this->signalGroups[$groupName])) {
            $this->logger->warning('信号组不存在: {name}', ['name' => $groupName]);
            return false;
        }

        if (!in_array($signal, $this->signalGroups[$groupName], true)) {
            $this->logger->warning('信号 {name} 不属于组 {group}', [
                'name' => Signal::getName($signal),
                'group' => $groupName,
            ]);
            return false;
        }

        $this->dispatchEvent($signal);
        return true;
    }

    public function dispatchToGroups(int $signal): array
    {
        $dispatched = [];

        foreach ($this->signalGroups as $name => $signals) {
            if (in_array($signal, $signals, true)) {
                $this->dispatchEvent($signal);
                $dispatched[] = $name;
            }
        }

        return $dispatched;
    }

    public function getGroupSignals(string $groupName): array
    {
        return $this->signalGroups[$groupName] ?? [];
    }

    public function getSignalListeners(int $signal): array
    {
        return array_keys($this->eventListeners[$signal] ?? []);
    }

    public function hasListeners(int $signal): bool
    {
        return !empty($this->eventListeners[$signal] ?? []);
    }

    public function getHandledSignals(): array
    {
        return array_keys($this->handledSignals);
    }

    public function clearHandledSignals(): void
    {
        $this->handledSignals = [];
    }

    public function getHandler(): SignalHandler
    {
        return $this->handler;
    }

    public function __call(string $method, array $args): mixed
    {
        return call_user_func_array([$this->handler, $method], $args);
    }
}
