<?php

declare(strict_types=1);

namespace Kode\Process\Monitor;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 心跳检测器
 * 
 * 负责进程心跳检测和超时处理
 */
class Heartbeat
{
    private LoggerInterface $logger;

    private float $interval = 5.0;

    private float $timeout = 30.0;

    private array $heartbeats = [];

    private array $callbacks = [];

    private bool $running = false;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(int $pid, array $metadata = []): void
    {
        $this->heartbeats[$pid] = [
            'pid' => $pid,
            'last_beat' => microtime(true),
            'count' => 0,
            'metadata' => $metadata,
            'status' => 'active',
        ];

        $this->logger->debug('心跳检测已注册', ['pid' => $pid]);
    }

    public function unregister(int $pid): void
    {
        unset($this->heartbeats[$pid]);

        $this->logger->debug('心跳检测已注销', ['pid' => $pid]);
    }

    public function beat(int $pid, array $data = []): bool
    {
        if (!isset($this->heartbeats[$pid])) {
            $this->register($pid);
        }

        $this->heartbeats[$pid]['last_beat'] = microtime(true);
        $this->heartbeats[$pid]['count']++;
        $this->heartbeats[$pid]['data'] = $data;
        $this->heartbeats[$pid]['status'] = 'active';

        $this->logger->debug('心跳已更新', ['pid' => $pid, 'count' => $this->heartbeats[$pid]['count']]);

        return true;
    }

    public function check(): array
    {
        $now = microtime(true);
        $results = [
            'active' => [],
            'timeout' => [],
            'dead' => [],
        ];

        foreach ($this->heartbeats as $pid => &$heartbeat) {
            $elapsed = $now - $heartbeat['last_beat'];

            if ($elapsed > $this->timeout) {
                $heartbeat['status'] = 'timeout';
                $results['timeout'][] = [
                    'pid' => $pid,
                    'elapsed' => $elapsed,
                    'last_beat' => $heartbeat['last_beat'],
                ];

                $this->handleTimeout($pid, $elapsed);
            } else {
                $results['active'][] = [
                    'pid' => $pid,
                    'elapsed' => $elapsed,
                    'count' => $heartbeat['count'],
                ];
            }
        }

        return $results;
    }

    public function checkPid(int $pid): array
    {
        if (!isset($this->heartbeats[$pid])) {
            return [
                'status' => 'unknown',
                'pid' => $pid,
            ];
        }

        $heartbeat = $this->heartbeats[$pid];
        $elapsed = microtime(true) - $heartbeat['last_beat'];

        return [
            'status' => $elapsed > $this->timeout ? 'timeout' : 'active',
            'pid' => $pid,
            'elapsed' => $elapsed,
            'last_beat' => $heartbeat['last_beat'],
            'count' => $heartbeat['count'],
            'data' => $heartbeat['data'] ?? [],
        ];
    }

    private function handleTimeout(int $pid, float $elapsed): void
    {
        $this->logger->warning('心跳超时', [
            'pid' => $pid,
            'elapsed' => $elapsed,
            'timeout' => $this->timeout
        ]);

        foreach ($this->callbacks as $callback) {
            $callback($pid, $elapsed);
        }
    }

    public function onTimeout(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function setInterval(float $seconds): void
    {
        $this->interval = $seconds;
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function setTimeout(float $seconds): void
    {
        $this->timeout = $seconds;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getActiveCount(): int
    {
        $count = 0;
        $now = microtime(true);

        foreach ($this->heartbeats as $heartbeat) {
            if (($now - $heartbeat['last_beat']) <= $this->timeout) {
                $count++;
            }
        }

        return $count;
    }

    public function getTimeoutCount(): int
    {
        $count = 0;
        $now = microtime(true);

        foreach ($this->heartbeats as $heartbeat) {
            if (($now - $heartbeat['last_beat']) > $this->timeout) {
                $count++;
            }
        }

        return $count;
    }

    public function getAll(): array
    {
        return $this->heartbeats;
    }

    public function clear(): void
    {
        $this->heartbeats = [];
    }

    public function start(): void
    {
        $this->running = true;
        $this->logger->info('心跳检测器已启动');
    }

    public function stop(): void
    {
        $this->running = false;
        $this->logger->info('心跳检测器已停止');
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
