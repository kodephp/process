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
            $this->logger->warning('信号 %d 不可捕获，将使用默认处理器', [$signal]);
        }

        $this->handlers[$signal] = $handler;

        $result = pcntl_signal($signal, [$this, 'handleSignal'], $this->async);

        if (!$result) {
            throw SignalException::handlerRegistrationFailed($signal, error_get_last()['message'] ?? '');
        }

        $this->logger->debug('已注册信号处理器: %s (%d)', [Signal::getName($signal), $signal]);
    }

    public function unregister(int $signal): void
    {
        unset($this->handlers[$signal]);

        pcntl_signal($signal, SIG_DFL);

        $this->logger->debug('已注销信号处理器: %s (%d)', [Signal::getName($signal), $signal]);
    }

    public function dispatch(int $signal, mixed $info = null): void
    {
        $handler = $this->handlers[$signal] ?? $this->defaultHandlers[$signal] ?? null;

        if ($handler === null) {
            $this->logger->warning('信号 %d 没有注册处理器', [$signal]);
            return;
        }

        try {
            ($handler)($signal, $info);
        } catch (\Throwable $e) {
            $this->logger->error('信号处理器执行失败 [%s]: %s', [
                Signal::getName($signal),
                $e->getMessage()
            ]);
        }
    }

    public function handleSignal(int $signal): void
    {
        if ($this->async) {
            $this->signalQueue[] = $signal;
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

        $this->logger->debug('已忽略信号: %s (%d)', [Signal::getName($signal), $signal]);
    }

    public function getDefaultHandler(int $signal): ?callable
    {
        return $this->defaultHandlers[$signal] ?? null;
    }

    public function setAsyncDispatch(bool $async): void
    {
        $this->async = $async;

        foreach (array_keys($this->handlers) as $signal) {
            pcntl_signal($signal, [$this, 'handleSignal'], $async);
        }

        $this->logger->debug('异步信号分发模式: %s', [$async ? '启用' : '禁用']);
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
