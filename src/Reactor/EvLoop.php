<?php

declare(strict_types=1);

namespace Kode\Process\Reactor;

use Ev;
use EvIo;
use EvSignal;
use EvTimer;

/**
 * 基于 ext-ev（libev）的事件循环。
 *
 * 与 EventLoop 定位相同，作为 ext-event 不可用时的次选 C 层驱动。
 * ext-ev 使用全局默认 loop，不支持多 loop 实例共存，因此本类为单实例语义。
 */
final class EvLoop implements LoopInterface
{
    /** @var array<int, EvIo> */
    private array $readWatchers = [];

    /** @var array<int, EvIo> */
    private array $writeWatchers = [];

    /** @var array<int, EvSignal> */
    private array $signalWatchers = [];

    /** @var array<int, EvTimer> */
    private array $timerWatchers = [];

    /** @var list<callable> */
    private array $deferred = [];

    private ?EvTimer $deferTimer = null;

    private int $timerSeq = 0;

    private bool $running = false;

    public function __construct()
    {
        if (!self::isSupported()) {
            throw new \RuntimeException('ext-ev 未安装，无法使用 EvLoop 驱动');
        }
    }

    public static function isSupported(): bool
    {
        return \extension_loaded('ev') && \class_exists(Ev::class);
    }

    public static function name(): string
    {
        return 'ev';
    }

    public static function priority(): int
    {
        return 90;
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offReadable($stream);
        $this->readWatchers[$id] = new EvIo($stream, Ev::READ, static function () use ($callback, $stream): void {
            try {
                $callback($stream);
            } catch (\Throwable $e) {
                \error_log("EvLoop: read 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        });
    }

    public function offReadable($stream): void
    {
        $id = (int)$stream;
        if (isset($this->readWatchers[$id])) {
            $this->readWatchers[$id]->stop();
            unset($this->readWatchers[$id]);
        }
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offWritable($stream);
        $this->writeWatchers[$id] = new EvIo($stream, Ev::WRITE, static function () use ($callback, $stream): void {
            try {
                $callback($stream);
            } catch (\Throwable $e) {
                \error_log("EvLoop: write 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        });
    }

    public function offWritable($stream): void
    {
        $id = (int)$stream;
        if (isset($this->writeWatchers[$id])) {
            $this->writeWatchers[$id]->stop();
            unset($this->writeWatchers[$id]);
        }
    }

    public function onSignal(int $signal, callable $callback): void
    {
        $this->offSignal($signal);
        $this->signalWatchers[$signal] = new EvSignal($signal, static function () use ($callback, $signal): void {
            try {
                $callback($signal);
            } catch (\Throwable $e) {
                \error_log("EvLoop: signal#{$signal} 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        });
    }

    public function offSignal(int $signal): void
    {
        if (isset($this->signalWatchers[$signal])) {
            $this->signalWatchers[$signal]->stop();
            unset($this->signalWatchers[$signal]);
        }
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = false): int
    {
        $interval = max(0.0, $interval);
        $id       = ++$this->timerSeq;

        $repeat = $periodic ? max($interval, 0.000001) : 0.0;
        $this->timerWatchers[$id] = new EvTimer(
            $interval,
            $repeat,
            function () use ($callback, $id, $periodic): void {
                if (!$periodic) {
                    unset($this->timerWatchers[$id]);
                }
                try {
                    $callback();
                } catch (\Throwable $e) {
                    \error_log("EvLoop: timer#{$id} 回调异常已隔离，循环继续: " . $e->getMessage());
                }
            }
        );

        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timerWatchers[$timerId])) {
            return false;
        }
        $this->timerWatchers[$timerId]->stop();
        unset($this->timerWatchers[$timerId]);
        return true;
    }

    public function defer(callable $callback): void
    {
        $this->deferred[] = $callback;
        if ($this->deferTimer !== null) {
            return;
        }
        $this->deferTimer = new EvTimer(0.0, 0.0, function (): void {
            $this->deferTimer = null;
            $pending          = $this->deferred;
            $this->deferred   = [];
            foreach ($pending as $cb) {
                try {
                    $cb();
                } catch (\Throwable $e) {
                    \error_log('EvLoop: deferred 回调异常已隔离，循环继续: ' . $e->getMessage());
                }
            }
        });
    }

    public function run(): void
    {
        $this->running = true;
        Ev::run();
        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
        Ev::stop(Ev::BREAK_ALL);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function destroy(): void
    {
        foreach ($this->readWatchers as $w) {
            $w->stop();
        }
        foreach ($this->writeWatchers as $w) {
            $w->stop();
        }
        foreach ($this->signalWatchers as $w) {
            $w->stop();
        }
        foreach ($this->timerWatchers as $w) {
            $w->stop();
        }
        $this->deferTimer?->stop();

        $this->readWatchers   = [];
        $this->writeWatchers  = [];
        $this->signalWatchers = [];
        $this->timerWatchers  = [];
        $this->deferred       = [];
        $this->deferTimer     = null;

        $this->stop();
    }

    public function stats(): array
    {
        return [
            'driver'   => self::name(),
            'read'     => \count($this->readWatchers),
            'write'    => \count($this->writeWatchers),
            'timer'    => \count($this->timerWatchers),
            'signal'   => \count($this->signalWatchers),
            'deferred' => \count($this->deferred),
        ];
    }
}
