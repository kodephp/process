<?php

declare(strict_types=1);

namespace Kode\Process\Monitor;

final class ConnectionHeartbeat
{
    private int $heartbeatInterval;
    private int $heartbeatTimeout;
    private array $connections = [];
    private bool $running = false;
    private $onTimeoutCallback = null;
    private $onHeartbeatCallback = null;

    public function __construct(int $interval = 55, int $timeout = 110)
    {
        $this->heartbeatInterval = $interval;
        $this->heartbeatTimeout = $timeout;
    }

    public function register(int $connectionId, array $metadata = []): void
    {
        $this->connections[$connectionId] = [
            'id' => $connectionId,
            'last_message_time' => time(),
            'last_heartbeat_sent' => 0,
            'heartbeat_count' => 0,
            'metadata' => $metadata,
            'status' => 'active'
        ];
    }

    public function unregister(int $connectionId): void
    {
        unset($this->connections[$connectionId]);
    }

    public function updateActivity(int $connectionId): void
    {
        if (isset($this->connections[$connectionId])) {
            $this->connections[$connectionId]['last_message_time'] = time();
            $this->connections[$connectionId]['status'] = 'active';
        }
    }

    public function check(): array
    {
        $now = time();
        $result = [
            'active' => [],
            'timeout' => [],
            'need_heartbeat' => []
        ];

        foreach ($this->connections as $id => &$conn) {
            $elapsed = $now - $conn['last_message_time'];

            if ($elapsed > $this->heartbeatTimeout) {
                $conn['status'] = 'timeout';
                $result['timeout'][] = [
                    'id' => $id,
                    'elapsed' => $elapsed,
                    'last_message_time' => $conn['last_message_time']
                ];

                $this->handleTimeout($id, $conn);
            } elseif ($elapsed > $this->heartbeatInterval) {
                $result['need_heartbeat'][] = [
                    'id' => $id,
                    'elapsed' => $elapsed
                ];
            } else {
                $result['active'][] = [
                    'id' => $id,
                    'elapsed' => $elapsed
                ];
            }
        }

        return $result;
    }

    public function sendHeartbeat(int $connectionId): bool
    {
        if (!isset($this->connections[$connectionId])) {
            return false;
        }

        $this->connections[$connectionId]['last_heartbeat_sent'] = time();
        $this->connections[$connectionId]['heartbeat_count']++;

        if ($this->onHeartbeatCallback !== null) {
            return ($this->onHeartbeatCallback)($connectionId, $this->connections[$connectionId]);
        }

        return true;
    }

    public function sendHeartbeats(): int
    {
        $now = time();
        $sent = 0;

        foreach ($this->connections as $id => $conn) {
            $elapsed = $now - $conn['last_message_time'];

            if ($elapsed > $this->heartbeatInterval && $elapsed <= $this->heartbeatTimeout) {
                if ($this->sendHeartbeat($id)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    private function handleTimeout(int $connectionId, array $connection): void
    {
        if ($this->onTimeoutCallback !== null) {
            ($this->onTimeoutCallback)($connectionId, $connection);
        }

        $this->unregister($connectionId);
    }

    public function onTimeout(callable $callback): self
    {
        $this->onTimeoutCallback = $callback;
        return $this;
    }

    public function onHeartbeat(callable $callback): self
    {
        $this->onHeartbeatCallback = $callback;
        return $this;
    }

    public function setInterval(int $seconds): self
    {
        $this->heartbeatInterval = $seconds;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->heartbeatTimeout = $seconds;
        return $this;
    }

    public function getInterval(): int
    {
        return $this->heartbeatInterval;
    }

    public function getTimeout(): int
    {
        return $this->heartbeatTimeout;
    }

    public function getConnection(int $connectionId): ?array
    {
        return $this->connections[$connectionId] ?? null;
    }

    public function getActiveCount(): int
    {
        $now = time();
        $count = 0;

        foreach ($this->connections as $conn) {
            if (($now - $conn['last_message_time']) <= $this->heartbeatTimeout) {
                $count++;
            }
        }

        return $count;
    }

    public function getTimeoutCount(): int
    {
        $now = time();
        $count = 0;

        foreach ($this->connections as $conn) {
            if (($now - $conn['last_message_time']) > $this->heartbeatTimeout) {
                $count++;
            }
        }

        return $count;
    }

    public function getAll(): array
    {
        return $this->connections;
    }

    public function count(): int
    {
        return count($this->connections);
    }

    public function clear(): void
    {
        $this->connections = [];
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getStats(): array
    {
        $now = time();
        $stats = [
            'total' => count($this->connections),
            'active' => 0,
            'idle' => 0,
            'timeout' => 0,
            'total_heartbeats' => 0
        ];

        foreach ($this->connections as $conn) {
            $elapsed = $now - $conn['last_message_time'];
            $stats['total_heartbeats'] += $conn['heartbeat_count'];

            if ($elapsed > $this->heartbeatTimeout) {
                $stats['timeout']++;
            } elseif ($elapsed > $this->heartbeatInterval) {
                $stats['idle']++;
            } else {
                $stats['active']++;
            }
        }

        return $stats;
    }
}
