<?php

declare(strict_types=1);

namespace Kode\Process\Integration;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;

final class IntegrationManager
{
    private static ?self $instance = null;

    private ?FrameworkAdapterInterface $adapter = null;
    private array $adapters = [];
    private bool $booted = false;

    private function __construct()
    {
        $this->registerDefaultAdapters();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function registerDefaultAdapters(): void
    {
        $this->adapters['laravel'] = LaravelAdapter::class;
        $this->adapters['symfony'] = SymfonyAdapter::class;
        $this->adapters['generic'] = GenericAdapter::class;
    }

    public function register(string $name, string $adapterClass): self
    {
        $this->adapters[$name] = $adapterClass;
        return $this;
    }

    public function detect(): ?string
    {
        foreach ($this->adapters as $name => $class) {
            if ($name === 'generic') {
                continue;
            }

            $adapter = new $class();

            if ($adapter instanceof FrameworkAdapterInterface && $adapter->isAvailable()) {
                return $name;
            }
        }

        return 'generic';
    }

    public function boot(?string $framework = null, array $config = []): Response
    {
        if ($this->booted) {
            return Response::ok(['booted' => true], '集成管理器已启动');
        }

        $framework = $framework ?? $this->detect();

        if (!isset($this->adapters[$framework])) {
            return Response::invalid("未知的框架: {$framework}");
        }

        $adapterClass = $this->adapters[$framework];
        $this->adapter = new $adapterClass($config);

        $response = $this->adapter->boot();

        if (!$response->isSuccess()) {
            $this->adapter = null;
            return $response;
        }

        $registerResponse = $this->adapter->register($config);

        $this->booted = true;

        return Response::ok([
            'framework' => $framework,
            'booted' => true,
            'registered' => $registerResponse->isSuccess(),
            'version' => $this->adapter->getVersion(),
        ], "框架 {$framework} 集成成功");
    }

    public function getAdapter(): ?FrameworkAdapterInterface
    {
        return $this->adapter;
    }

    public function hasAdapter(): bool
    {
        return $this->adapter !== null;
    }

    public function get(string $service): ?object
    {
        return $this->adapter?->get($service);
    }

    public function has(string $service): bool
    {
        return $this->adapter?->has($service) ?? false;
    }

    public function make(string $class, array $params = []): ?object
    {
        return $this->adapter?->make($class, $params);
    }

    public function call(callable $callback, array $params = []): mixed
    {
        return $this->adapter?->call($callback, $params) ?? $callback(...$params);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->adapter?->config($key, $default) ?? $default;
    }

    public function environment(): string
    {
        return $this->adapter?->environment() ?? 'production';
    }

    public function isDebug(): bool
    {
        return $this->adapter?->isDebug() ?? false;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->adapter?->log($level, $message, $context);
    }

    public function handleError(\Throwable $e): void
    {
        $this->adapter?->handleError($e);
    }

    public function getFrameworkName(): string
    {
        return $this->adapter?->getName() ?? 'Unknown';
    }

    public function getFrameworkVersion(): string
    {
        return $this->adapter?->getVersion() ?? '0.0.0';
    }

    public function getAvailableFrameworks(): array
    {
        $available = [];

        foreach ($this->adapters as $name => $class) {
            $adapter = new $class();

            if ($adapter instanceof FrameworkAdapterInterface) {
                $available[$name] = [
                    'name' => $adapter->getName(),
                    'available' => $adapter->isAvailable(),
                    'version' => $adapter->isAvailable() ? $adapter->getVersion() : null,
                ];
            }
        }

        return $available;
    }

    public function terminate(): void
    {
        $this->adapter?->terminate();
        $this->adapter = null;
        $this->booted = false;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->terminate();
        }

        self::$instance = null;
    }
}
