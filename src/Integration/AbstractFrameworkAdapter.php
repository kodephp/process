<?php

declare(strict_types=1);

namespace Kode\Process\Integration;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;
use Kode\Process\Server;

abstract class AbstractFrameworkAdapter implements FrameworkAdapterInterface
{
    protected ?object $container = null;
    protected array $config = [];
    protected bool $booted = false;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getContainer(): ?object
    {
        return $this->container;
    }

    public function boot(): Response
    {
        if ($this->booted) {
            return Response::ok(['booted' => true], '框架已启动');
        }

        if (!$this->isAvailable()) {
            return Response::error('框架不可用: ' . $this->getName());
        }

        $this->booted = true;

        return Response::ok(['booted' => true], '框架启动成功');
    }

    public function register(array $config = []): Response
    {
        $this->config = array_merge($this->config, $config);

        return Response::ok(['registered' => true], '配置已注册');
    }

    public function get(string $service): ?object
    {
        if ($this->container === null) {
            return null;
        }

        try {
            return $this->container->get($service);
        } catch (\Throwable) {
            return null;
        }
    }

    public function has(string $service): bool
    {
        if ($this->container === null) {
            return false;
        }

        try {
            if (method_exists($this->container, 'has')) {
                return $this->container->has($service);
            }
        } catch (\Throwable) {
        }

        return false;
    }

    public function make(string $class, array $params = []): ?object
    {
        if ($this->container === null) {
            return null;
        }

        try {
            if (method_exists($this->container, 'make')) {
                return $this->container->make($class, $params);
            }

            if (method_exists($this->container, 'get')) {
                return $this->container->get($class);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function call(callable $callback, array $params = []): mixed
    {
        if ($this->container === null) {
            return $callback(...$params);
        }

        try {
            if (method_exists($this->container, 'call')) {
                return $this->container->call($callback, $params);
            }
        } catch (\Throwable) {
        }

        return $callback(...$params);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function environment(): string
    {
        return $this->config['env'] ?? 'production';
    }

    public function isDebug(): bool
    {
        return (bool) ($this->config['debug'] ?? false);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $logger = $this->getLogger();

        if ($logger !== null && method_exists($logger, $level)) {
            $logger->$level($message, $context);
        }
    }

    public function handleError(\Throwable $e): void
    {
        $this->log('error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function terminate(): void
    {
        $this->booted = false;
        $this->container = null;
    }

    protected function getLogger(): ?object
    {
        return $this->get('logger') ?? $this->get('log');
    }
}
