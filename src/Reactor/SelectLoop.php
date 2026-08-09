<?php

declare(strict_types=1);

namespace Kode\Process\Reactor;

/**
 * 零扩展兜底事件循环，基于 stream_select()。
 *
 * 不依赖任何 PHP 扩展，任何 PHP 8.3+ 环境都可运行。
 * 代价是 fd 数量受 FD_SETSIZE 限制、且随连接数增长为 O(n) 扫描；
 * 连接数较多时应安装 ext-event 或 ext-ev 以自动切换到 C 层多路复用。
 */
final class SelectLoop implements LoopInterface
{
    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, callable> */
    private array $readCallbacks = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, callable> */
    private array $writeCallbacks = [];

    /** @var array<int, callable> */
    private array $signalCallbacks = [];

    /**
     * 定时器表。
     *
     * @var array<int, array{at:float, interval:float, periodic:bool, callback:callable}>
     */
    private array $timers = [];

    /** @var list<callable> */
    private array $deferred = [];

    private int $timerSeq = 0;

    private bool $running = false;

    private bool $signalsEnabled = false;

    public static function isSupported(): bool
    {
        return true; // 永远可用，作为兜底
    }

    public static function name(): string
    {
        return 'select';
    }

    public static function priority(): int
    {
        return 0;
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->guardFdLimit($id);
        $this->readStreams[$id]   = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    public function offReadable($stream): void
    {
        $id = (int)$stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->guardFdLimit($id);
        $this->writeStreams[$id]   = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    public function offWritable($stream): void
    {
        $id = (int)$stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    public function onSignal(int $signal, callable $callback): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        $this->enableAsyncSignals();
        $this->signalCallbacks[$signal] = $callback;
        \pcntl_signal($signal, function (int $sig): void {
            if (!isset($this->signalCallbacks[$sig])) {
                return;
            }
            try {
                ($this->signalCallbacks[$sig])($sig);
            } catch (\Throwable $e) {
                \error_log("SelectLoop: signal#{$sig} 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        });
    }

    public function offSignal(int $signal): void
    {
        unset($this->signalCallbacks[$signal]);
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal($signal, \SIG_DFL);
        }
    }

    private function enableAsyncSignals(): void
    {
        if (!$this->signalsEnabled && \function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            $this->signalsEnabled = true;
        }
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = false): int
    {
        $interval = max(0.0, $interval);
        $id = ++$this->timerSeq;
        $this->timers[$id] = [
            'at'       => $this->now() + $interval,
            'interval' => $interval,
            'periodic' => $periodic,
            'callback' => $callback,
        ];
        return $id;
    }

    public function delTimer(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        unset($this->timers[$timerId]);
        return true;
    }

    public function defer(callable $callback): void
    {
        $this->deferred[] = $callback;
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $this->runDeferred();
            if (!$this->running) {
                break;
            }

            $timeout = $this->nextTimeout();

            // 无任何监听源且无定时器 —— 循环无事可做，退出避免空转
            if ($this->readStreams === [] && $this->writeStreams === [] && $timeout === null) {
                if ($this->deferred === []) {
                    break;
                }
                continue;
            }

            $this->select($timeout);
            $this->runTimers();
        }

        $this->running = false;
    }

    /**
     * @param float|null $timeout null 表示无定时器，可阻塞较长时间
     */
    private function select(?float $timeout): void
    {
        // 先剔除已失效的流：把非资源传给 stream_select 会直接抛 TypeError（@ 不抑制 Throwable），
        // 导致每轮空转 100% CPU；提前剔除可避免。
        $this->pruneInvalidStreams();

        $read   = $this->readStreams;
        $write  = $this->writeStreams;
        $except = [];

        if ($read === [] && $write === []) {
            // 只有定时器：睡到最近一个到期
            if ($timeout !== null && $timeout > 0) {
                usleep((int)($timeout * 1_000_000));
            }
            return;
        }

        // 上限 1 秒，保证信号与定时器能被及时处理
        $wait = $timeout === null ? 1.0 : min($timeout, 1.0);
        $sec  = (int)$wait;
        $usec = (int)(($wait - $sec) * 1_000_000);

        $count = @\stream_select($read, $write, $except, $sec, $usec);
        if ($count === false) {
            // stream_select 失败通常是集合里混入了高 fd（>= FD_SETSIZE）等平台限制。
            // 若不处理会每轮空转 100% CPU：剔除失效流并短暂退避再重试。
            $this->pruneInvalidStreams();
            \usleep(1000);
            return;
        }

        if ($count === 0) {
            return;
        }

        foreach ($read as $stream) {
            $id = (int)$stream;
            if (!isset($this->readCallbacks[$id])) {
                continue;
            }
            try {
                ($this->readCallbacks[$id])($stream);
            } catch (\Throwable $e) {
                \error_log("SelectLoop: read 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        }
        foreach ($write as $stream) {
            $id = (int)$stream;
            if (!isset($this->writeCallbacks[$id])) {
                continue;
            }
            try {
                ($this->writeCallbacks[$id])($stream);
            } catch (\Throwable $e) {
                \error_log("SelectLoop: write 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        }
    }

    private function runTimers(): void
    {
        if ($this->timers === []) {
            return;
        }
        $now = $this->now();
        foreach ($this->timers as $id => $timer) {
            if ($timer['at'] > $now) {
                continue;
            }
            if ($timer['periodic']) {
                // 基于当前时间重排，避免回调耗时导致的漂移累积
                $this->timers[$id]['at'] = $now + $timer['interval'];
            } else {
                unset($this->timers[$id]);
            }
            try {
                ($timer['callback'])();
            } catch (\Throwable $e) {
                \error_log("SelectLoop: timer#{$id} 回调异常已隔离，循环继续: " . $e->getMessage());
            }
        }
    }

    private function runDeferred(): void
    {
        if ($this->deferred === []) {
            return;
        }
        $pending        = $this->deferred;
        $this->deferred = [];
        foreach ($pending as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                \error_log('SelectLoop: deferred 回调异常已隔离，循环继续: ' . $e->getMessage());
            }
        }
    }

    /**
     * stream_select 底层 bitset 受系统 FD_SETSIZE 限制（通常 1024），
     * 任何 fd 编号 >= 上限都会导致 select 直接失败并空转。超过时仅告警一次，
     * 提示切换到 ext-event / ext-ev 的 C 层多路复用。
     */
    private bool $fdSetSizeWarned = false;

    private function guardFdLimit(int $fd): void
    {
        if ($fd >= 1024 && !$this->fdSetSizeWarned) {
            $this->fdSetSizeWarned = true;
            // 非抛出：高 fd 在某些平台（如 macOS）是常态，避免打断运行；
            // 仅记录一次，提示切换到 C 层多路复用。
            \error_log(
                "SelectLoop: fd #{$fd} >= 1024 (FD_SETSIZE)，stream_select 将无法监听该流；"
                . '请安装 ext-event / ext-ev 切换到 C 层多路复用以避免连接数受限。'
            );
        }
    }

    /**
     * 剔除已失效（非资源）的读写流，避免 stream_select 因 Bad file descriptor 反复失败空转。
     */
    private function pruneInvalidStreams(): void
    {
        foreach ($this->readStreams as $id => $stream) {
            if (!\is_resource($stream)) {
                unset($this->readStreams[$id], $this->readCallbacks[$id]);
            }
        }

        foreach ($this->writeStreams as $id => $stream) {
            if (!\is_resource($stream)) {
                unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
            }
        }
    }

    private function nextTimeout(): ?float
    {
        if ($this->timers === []) {
            return null;
        }
        $now  = $this->now();
        $next = null;
        foreach ($this->timers as $timer) {
            $delta = $timer['at'] - $now;
            if ($next === null || $delta < $next) {
                $next = $delta;
            }
        }
        return $next === null ? null : max(0.0, $next);
    }

    private function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function destroy(): void
    {
        $this->stop();
        foreach (array_keys($this->signalCallbacks) as $signal) {
            $this->offSignal($signal);
        }
        $this->readStreams    = [];
        $this->readCallbacks  = [];
        $this->writeStreams   = [];
        $this->writeCallbacks = [];
        $this->timers         = [];
        $this->deferred       = [];
    }

    public function stats(): array
    {
        return [
            'driver'   => self::name(),
            'read'     => \count($this->readStreams),
            'write'    => \count($this->writeStreams),
            'timer'    => \count($this->timers),
            'signal'   => \count($this->signalCallbacks),
            'deferred' => \count($this->deferred),
        ];
    }
}
