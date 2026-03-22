<?php

declare(strict_types=1);

namespace Kode\Process\Auth;

/**
 * 连接认证管理器
 * 
 * 管理未认证连接的超时关闭
 */
final class ConnectionAuth
{
    private int $timeout;
    private array $pendingConnections = [];
    private $onTimeout = null;
    private $onAuth = null;
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function register($connection): void
    {
        $id = spl_object_id($connection);

        $this->pendingConnections[$id] = [
            'connection' => $connection,
            'registered_at' => time(),
            'timer_id' => $this->timeout > 0 ? $this->scheduleTimeout($connection) : null
        ];
    }

    public function authenticate($connection): bool
    {
        $id = spl_object_id($connection);

        if (!isset($this->pendingConnections[$id])) {
            return false;
        }

        $data = $this->pendingConnections[$id];

        if ($data['timer_id'] !== null) {
            $this->cancelTimeout($data['timer_id']);
        }

        unset($this->pendingConnections[$id]);

        if ($this->onAuth !== null) {
            ($this->onAuth)($connection);
        }

        return true;
    }

    public function isAuthenticated($connection): bool
    {
        return !isset($this->pendingConnections[spl_object_id($connection)]);
    }

    public function checkTimeouts(): array
    {
        $now = time();
        $expired = [];

        foreach ($this->pendingConnections as $id => $data) {
            if ($now - $data['registered_at'] >= $this->timeout) {
                $expired[] = $data['connection'];
                unset($this->pendingConnections[$id]);

                if ($this->onTimeout !== null) {
                    ($this->onTimeout)($data['connection']);
                }
            }
        }

        return $expired;
    }

    public function onTimeout(callable $callback): self
    {
        $this->onTimeout = $callback;
        return $this;
    }

    public function onAuth(callable $callback): self
    {
        $this->onAuth = $callback;
        return $this;
    }

    public function getPendingCount(): int
    {
        return count($this->pendingConnections);
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function clear(): void
    {
        foreach ($this->pendingConnections as $data) {
            if ($data['timer_id'] !== null) {
                $this->cancelTimeout($data['timer_id']);
            }
        }

        $this->pendingConnections = [];
    }

    private function scheduleTimeout($connection): int
    {
        return \Kode\Process\Compat\Timer::add(
            $this->timeout,
            function () use ($connection) {
                $id = spl_object_id($connection);

                if (isset($this->pendingConnections[$id])) {
                    unset($this->pendingConnections[$id]);

                    if ($this->onTimeout !== null) {
                        ($this->onTimeout)($connection);
                    }

                    try {
                        $connection->close();
                    } catch (\Throwable) {
                    }
                }
            },
            [],
            false
        );
    }

    private function cancelTimeout(int $timerId): void
    {
        \Kode\Process\Compat\Timer::del($timerId);
    }
}
