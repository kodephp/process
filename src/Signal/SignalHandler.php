<?php

declare(strict_types=1);

namespace Kode\Process\Signal;

use Kode\Process\Exceptions\SignalException;
use Kode\Process\Signal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 信号处理器
 * 
 * 负责管理进程信号处理，包括注册、分发和默认处理器
 */
class SignalHandler
{
    private static ?self $instance = null;

    private array $handlers = [];

    private array $defaultHandlers = [];

    private LoggerInterface $logger;

    private bool $async = false;

    private array $signalQueue = [];

    private bool $processing = false;

    /** 异步排队信号上限，避免信号风暴（如 SIGCHLD）下队列无界增长 */
    private const int MAX_QUEUED = 256;

    private function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();

        if (!function_exists('pcntl_signal')) {
            throw SignalException::extensionNotLoaded();
        }

        $this->registerDefaultHandlers();
    }

    public static function getInstance(?LoggerInterface $logger = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logger);
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    private function registerDefaultHandlers(): void
    {
        $this->defaultHandlers = [
            Signal::TERM => function (int $signal): void {
                $this->logger->info('收到 SIGTERM 信号，准备优雅关闭');
            },
            Signal::INT => function (int $signal): void {
                $this->logger->info('收到 SIGINT 信号 (Ctrl+C)，准备优雅关闭');
            },
            Signal::QUIT => function (int $signal): void {
                $this->logger->info('收到 SIGQUIT 信号，退出');
            },
            Signal::HUP => function (int $signal): void {
                $this->logger->info('收到 SIGHUP 信号，重新加载配置');
            },
            Signal::USR1 => function (int $signal): void {
                $this->logger->info('收到 SIGUSR1 信号，自定义操作');
            },
            Signal::USR2 => function (int $signal): void {
                $this->logger->info('收到 SIGUSR2 信号，自定义操作');
            },
            Signal::CHLD => function (int $signal): void {
                $this->logger->debug('收到 SIGCHLD 信号，子进程状态改变');
            },
            Signal::CONT => function (int $signal): void {
                $this->logger->info('收到 SIGCONT 信号，继续执行');
            },
            Signal::STOP => function (int $signal): void {
                $this->logger->info('收到 SIGSTOP 信号，停止执行');
            },
            Signal::TSTP => function (int $signal): void {
                $this->logger->info('收到 SIGTSTP 信号，终端停止');
            },
        ];
    }

    public function register(int $signal, callable $handler): void
    {
        if (!Signal::isCatchable($signal)) {
            $this->logger->warning('信号 {signal} 不可捕获，将使用默认处理器', ['signal' => $signal]);
        }

        $this->handlers[$signal] = $handler;

        // 第 3 参是 restart_syscalls（默认 true）：让被信号打断的慢系统调用
        // （如 read/write）自动重启，避免 EINTR。异步分发由 pcntl_async_signals() 控制。
        $result = pcntl_signal($signal, [$this, 'handleSignal'], true);

        if ($this->async && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        if (!$result) {
            throw SignalException::handlerRegistrationFailed($signal, error_get_last()['message'] ?? '');
        }

        $this->logger->debug('已注册信号处理器: {name} ({signal})', [
            'name' => Signal::getName($signal),
            'signal' => $signal,
        ]);
    }

    public function unregister(int $signal): void
    {
        unset($this->handlers[$signal]);

        pcntl_signal($signal, SIG_DFL);

        $this->logger->debug('已注销信号处理器: {name} ({signal})', [
            'name' => Signal::getName($signal),
            'signal' => $signal,
        ]);
    }

    public function dispatch(int $signal, mixed $info = null): void
    {
        $handler = $this->handlers[$signal] ?? $this->defaultHandlers[$signal] ?? null;

        if ($handler === null) {
            $this->logger->warning('信号 {signal} 没有注册处理器', ['signal' => $signal]);
            return;
        }

        try {
            ($handler)($signal, $info);
        } catch (\Throwable $e) {
            $this->logger->error('信号处理器执行失败 [{name}]: {message}', [
                'name' => Signal::getName($signal),
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function handleSignal(int $signal): void
    {
        if ($this->async) {
            // 去重 + 上限：信号风暴（如 SIGCHLD）下避免队列无界增长与重复分发
            if (count($this->signalQueue) < self::MAX_QUEUED && !in_array($signal, $this->signalQueue, true)) {
                $this->signalQueue[] = $signal;
            }
            return;
        }

        $this->dispatch($signal);
    }

    public function processQueue(): void
    {
        if ($this->processing) {
            return;
        }

        $this->processing = true;

        try {
            while (!empty($this->signalQueue)) {
                $signal = array_shift($this->signalQueue);
                $this->dispatch($signal);
            }
        } finally {
            $this->processing = false;
        }
    }

    public function getRegisteredSignals(): array
    {
        return array_keys($this->handlers);
    }

    public function hasHandler(int $signal): bool
    {
        return isset($this->handlers[$signal]);
    }

    public function clear(): void
    {
        $this->handlers = [];
        $this->signalQueue = [];

        foreach (array_keys($this->defaultHandlers) as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        $this->logger->debug('已清除所有信号处理器');
    }

    public function ignore(int $signal): void
    {
        pcntl_signal($signal, SIG_IGN);
        unset($this->handlers[$signal]);

        $this->logger->debug('已忽略信号: {name} ({signal})', [
            'name' => Signal::getName($signal),
            'signal' => $signal,
        ]);
    }

    public function getDefaultHandler(int $signal): ?callable
    {
        return $this->defaultHandlers[$signal] ?? null;
    }

    public function setAsyncDispatch(bool $async): void
    {
        $this->async = $async;

        // 真正启用/禁用引擎级异步信号分发：开启后信号会在 opcode 边界立即送达 handleSignal
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals($async);
        }

        foreach (array_keys($this->handlers) as $signal) {
            pcntl_signal($signal, [$this, 'handleSignal'], true);
        }

        $this->logger->debug('异步信号分发模式: {mode}', [
            'mode' => $async ? '启用' : '禁用',
        ]);
    }

    public function isAsyncDispatch(): bool
    {
        return $this->async;
    }

    public function waitForSignal(?float $timeout = null): ?int
    {
        if ($timeout === null) {
            pcntl_signal_dispatch();
            return null;
        }

        $start = microtime(true);
        while (true) {
            pcntl_signal_dispatch();

            if (!empty($this->signalQueue)) {
                return array_shift($this->signalQueue);
            }

            if (microtime(true) - $start >= $timeout) {
                return null;
            }

            usleep(1000);
        }
    }

    public function getSignalInfo(int $signal): array
    {
        return [
            'name' => Signal::getName($signal),
            'description' => Signal::getDescription($signal),
            'catchable' => Signal::isCatchable($signal),
            'has_handler' => $this->hasHandler($signal),
            'handler' => $this->handlers[$signal] ?? null,
        ];
    }
}
