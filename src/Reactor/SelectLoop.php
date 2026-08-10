<?php

declare(strict_types=1);

namespace Kode\Process\Reactor;

/**
 * 零扩展兜底事件循环，基于 stream_select()。
 *
 * 不依赖任何 PHP 扩展，任何 PHP 8.3+ 环境都可运行。
 * 失效流采用「惰性 prune」：正常 tick 不做任何额外扫描（用户态 O(1)），仅当流被外部
 * fclose 却未调用 off* 导致 stream_select 抛 ValueError 时才剔除失效流并重试一次。
 * fd 数量仍受 FD_SETSIZE 限制；连接数较多时应安装 ext-event 或 ext-ev 以自动切换到
 * C 层多路复用。
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
        $read   = $this->readStreams;
        $write  = $this->writeStreams;
        $except = [];

        // 空集合：仅定时器在跑，直接睡到最近到期，避免无意义的 stream_select 调用。
        if ($read === [] && $write === []) {
            if ($timeout !== null && $timeout > 0) {
                \usleep((int)($timeout * 1_000_000));
            }
            return;
        }

        // 上限 1 秒，保证信号与定时器能被及时处理
        $wait = $timeout === null ? 1.0 : \min($timeout, 1.0);
        $sec  = (int)$wait;
        $usec = (int)(($wait - $sec) * 1_000_000);

        // 惰性 prune：正常情形（流均有效）直接 select，每 tick 零额外扫描（O(1) 用户态开销）。
        // 仅当流被外部 fclose 却未调用 off* 时，stream_select 会对失效资源抛 ValueError
        //（@ 无法抑制），此时才剔除失效流并重试一次，避免每轮空转 100% CPU。
        try {
            $count = @\stream_select($read, $write, $except, $sec, $usec);
        } catch (\Throwable $e) {
            $this->pruneInvalidStreams();
            $read  = $this->readStreams;
            $write = $this->writeStreams;
            if ($read === [] && $write === []) {
                return;
            }
            try {
                $count = @\stream_select($read, $write, $except, $sec, $usec);
            } catch (\Throwable $e2) {
                // 重试仍抛错：若集合里仍含高 fd（>= FD_SETSIZE），给出明确告警一次，
                // 否则静默退避，避免无谓刷屏。
                if ($this->hasFdAtOrAboveSetSize()) {
                    $this->warnFdSetSize();
                }
                \usleep(1000);
                return;
            }
        }

        if ($count === false) {
            // 其余失败（如高 fd 超 FD_SETSIZE 等平台限制）：剔除失效流并短暂退避再重试。
            $this->pruneInvalidStreams();
            if ($this->hasFdAtOrAboveSetSize()) {
                $this->warnFdSetSize();
            }
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
     * 任何 fd 编号 >= 上限都会让 select 直接失败并退化为每 tick 空转。
     * 该状态在「注册期」与「运行期 stream_select 失败」两处都可能暴露，
     * 用统一标志保证全生命周期只告警一次，避免刷屏、也避免用户无感知地空转。
     */
    private bool $fdSetSizeWarned = false;

    /**
     * FD_SETSIZE（fd >= 1024）告警统一出口：注册期 guardFdLimit 与运行期 select 失败检测
     * 共用同一标志，保证无论哪条路径先触发，都只记录一次。
     */
    private function warnFdSetSize(): void
    {
        if ($this->fdSetSizeWarned) {
            return;
        }
        $this->fdSetSizeWarned = true;
        \error_log(
            'SelectLoop: 检测到 fd >= 1024 (FD_SETSIZE)，stream_select 无法安全监听该流，'
            . '多路复用将退化为每 tick 空转。请安装 ext-event / ext-ev 切换到 C 层多路复用以解除连接数上限。'
        );
    }

    /**
     * 当前读写流中是否存在 fd 编号 >= 1024 的连接。仅在 select 失败 / 重试失败的分支调用，
     * 不进入正常 tick 热路径。
     */
    private function hasFdAtOrAboveSetSize(): bool
    {
        foreach ($this->readStreams as $id => $_) {
            if ($id >= 1024) {
                return true;
            }
        }
        foreach ($this->writeStreams as $id => $_) {
            if ($id >= 1024) {
                return true;
            }
        }
        return false;
    }

    private function guardFdLimit(int $fd): void
    {
        if ($fd >= 1024) {
            $this->warnFdSetSize();
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
