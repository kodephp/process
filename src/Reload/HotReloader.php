<?php

declare(strict_types=1);

namespace Kode\Process\Reload;

/**
 * 平滑重载管理器
 * 
 * 支持不中断服务的代码更新
 */
final class HotReloader
{
    private int $maxRequests;
    private int $requestCount = 0;
    private bool $enabled = true;
    private $onReload = null;
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function __construct(int $maxRequests = 10000)
    {
        $this->maxRequests = $maxRequests;
    }

    public function setMaxRequests(int $count): self
    {
        $this->maxRequests = $count;
        return $this;
    }

    public function onReload(callable $callback): self
    {
        $this->onReload = $callback;
        return $this;
    }

    public function increment(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->requestCount++;

        if ($this->requestCount >= $this->maxRequests) {
            $this->triggerReload();
            return true;
        }

        return false;
    }

    public function check(): bool
    {
        return $this->requestCount >= $this->maxRequests;
    }

    public function triggerReload(): void
    {
        if ($this->onReload !== null) {
            ($this->onReload)();
        }

        $this->requestCount = 0;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getRemainingRequests(): int
    {
        return max(0, $this->maxRequests - $this->requestCount);
    }

    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    public function reset(): self
    {
        $this->requestCount = 0;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    public function getProgress(): float
    {
        if ($this->maxRequests <= 0) {
            return 0.0;
        }

        return min(1.0, $this->requestCount / $this->maxRequests);
    }

    public function getProgressPercent(): float
    {
        return $this->getProgress() * 100;
    }
}
