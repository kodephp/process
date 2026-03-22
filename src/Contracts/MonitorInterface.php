<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

/**
 * 进程监控器接口
 * 
 * 定义进程监控的标准方法
 */
interface MonitorInterface
{
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_UNHEALTHY = 'unhealthy';

    public function start(): void;

    public function stop(): void;

    public function check(int $pid): array;

    public function checkAll(): array;

    public function getHealth(): string;

    public function getMetrics(): array;

    public function setHeartbeatInterval(float $seconds): void;

    public function setMaxMemoryUsage(int $bytes): void;

    public function setMaxCpuUsage(float $percent): void;

    public function setMaxResponseTime(float $seconds): void;

    public function onUnhealthy(callable $callback): void;

    public function onRestart(callable $callback): void;
}
