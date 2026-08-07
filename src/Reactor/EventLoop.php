<?php

declare(strict_types=1);

namespace Kode\Process\Reactor;

use Event;
use EventBase;

/**
 * 基于 ext-event（libevent）的事件循环。
 *
 * I/O 多路复用与定时器全部下沉到 C 层，连接数增长时为 O(1) 事件分发，
 * 相比 stream_select 的 O(n) 扫描在高连接数下有数量级差异。
 *
 * 实测（4 worker、HTTP 最小响应）：单请求 CPU 成本约比 stream_select 路径低一个量级，
 * 但端到端吞吐仍受内核网络栈限制，压测数据见 docs/benchmark.md。
 *
 * 自研 Native 运行时会通过 {@see LoopFactory} 自动择优到本实现（若已安装 ext-event）。
 */
final class EventLoop implements LoopInterface
{
    private EventBase $base;

    /** @var array<int, Event> */
    private array $readEvents = [];

    /** @var array<int, Event> */
    private array $writeEvents = [];

    /** @var array<int, Event> */
    private array $signalEvents = [];

    /** @var array<int, Event> */
    private array $timerEvents = [];

    /** @var list<callable> */
    private array $deferred = [];

    private ?Event $deferTimer = null;

    private int $timerSeq = 0;

    private bool $running = false;

    public function __construct()
    {
        if (!self::isSupported()) {
            throw new \RuntimeException('ext-event 未安装，无法使用 EventLoop 驱动');
        }
        $this->base = new EventBase();
    }

    public static function isSupported(): bool
    {
        return \extension_loaded('event') && \class_exists(EventBase::class);
    }

    public static function name(): string
    {
        return 'event';
    }

    public static function priority(): int
    {
        return 100;
    }

    /** 暴露底层 EventBase，供需要直接使用 libevent 高级特性的组件（如 bufferevent）复用。 */
    public function base(): EventBase
    {
        return $this->base;
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offReadable($stream);

        $event = new Event(
            $this->base,
            $stream,
            Event::READ | Event::PERSIST,
            static function ($fd) use ($callback, $stream): void {
                $callback($stream);
            }
        );
        $event->add();
        $this->readEvents[$id] = $event;
    }

    public function offReadable($stream): void
    {
        $id = (int)$stream;
        if (isset($this->readEvents[$id])) {
            $this->readEvents[$id]->del();
            unset($this->readEvents[$id]);
        }
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offWritable($stream);

        $event = new Event(
            $this->base,
            $stream,
            Event::WRITE | Event::PERSIST,
            static function ($fd) use ($callback, $stream): void {
                $callback($stream);
            }
        );
        $event->add();
        $this->writeEvents[$id] = $event;
    }

    public function offWritable($stream): void
    {
        $id = (int)$stream;
        if (isset($this->writeEvents[$id])) {
            $this->writeEvents[$id]->del();
            unset($this->writeEvents[$id]);
        }
    }

    public function onSignal(int $signal, callable $callback): void
    {
        $this->offSignal($signal);

        $event = Event::signal($this->base, $signal, static function ($sig) use ($callback): void {
            $callback($sig);
        });
        $event->add();
        $this->signalEvents[$signal] = $event;
    }

    public function offSignal(int $signal): void
    {
        if (isset($this->signalEvents[$signal])) {
            $this->signalEvents[$signal]->del();
            unset($this->signalEvents[$signal]);
        }
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = false): int
    {
        $interval = max(0.0, $interval);
        $id       = ++$this->timerSeq;

        $flags = Event::TIMEOUT | ($periodic ? Event::PERSIST : 0);
        $event = new Event($this->base, -1, $flags, function () use ($callback, $id, $periodic): void {
            if (!$periodic) {
                unset($this->timerEvents[$id]);
            }
            $callback();
        });
        $event->add($interval);
        $this->timerEvents[$id] = $event;

        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timerEvents[$timerId])) {
            return false;
        }
        $this->timerEvents[$timerId]->del();
        unset($this->timerEvents[$timerId]);
        return true;
    }

    public function defer(callable $callback): void
    {
        $this->deferred[] = $callback;

        // libevent 无原生 "next tick"，用 0 秒定时器模拟；复用同一个定时器批量执行
        if ($this->deferTimer !== null) {
            return;
        }
        $this->deferTimer = new Event($this->base, -1, Event::TIMEOUT, function (): void {
            $this->deferTimer = null;
            $pending          = $this->deferred;
            $this->deferred   = [];
            foreach ($pending as $cb) {
                $cb();
            }
        });
        $this->deferTimer->add(0.0);
    }

    public function run(): void
    {
        $this->running = true;
        $this->base->loop();
        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->base->exit();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function destroy(): void
    {
        foreach ($this->readEvents as $e) {
            $e->del();
        }
        foreach ($this->writeEvents as $e) {
            $e->del();
        }
        foreach ($this->signalEvents as $e) {
            $e->del();
        }
        foreach ($this->timerEvents as $e) {
            $e->del();
        }
        $this->deferTimer?->del();

        $this->readEvents   = [];
        $this->writeEvents  = [];
        $this->signalEvents = [];
        $this->timerEvents  = [];
        $this->deferred     = [];
        $this->deferTimer   = null;

        $this->stop();
    }

    public function stats(): array
    {
        return [
            'driver'   => self::name(),
            'read'     => \count($this->readEvents),
            'write'    => \count($this->writeEvents),
            'timer'    => \count($this->timerEvents),
            'signal'   => \count($this->signalEvents),
            'deferred' => \count($this->deferred),
        ];
    }
}
