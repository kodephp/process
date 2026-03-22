<?php

declare(strict_types=1);

namespace Kode\Process\Integration;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;

final class GenericAdapter extends AbstractFrameworkAdapter
{
    private array $services = [];
    private array $aliases = [];
    private array $singletons = [];

    public function getName(): string
    {
        return 'Generic';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function boot(): Response
    {
        $response = parent::boot();

        if (!$response->isSuccess()) {
            return $response;
        }

        $this->registerCoreServices();

        return Response::ok([
            'booted' => true,
            'version' => $this->getVersion(),
        ], '通用框架适配器启动成功');
    }

    public function register(array $config = []): Response
    {
        parent::register($config);

        foreach ($config['services'] ?? [] as $name => $service) {
            if (is_object($service)) {
                $this->services[$name] = $service;
            } elseif (is_string($service) && class_exists($service)) {
                $this->services[$name] = new $service();
            }
        }

        foreach ($config['aliases'] ?? [] as $alias => $target) {
            $this->aliases[$alias] = $target;
        }

        return Response::ok(['registered' => true], '服务注册成功');
    }

    public function bind(string $name, mixed $service): self
    {
        if (is_callable($service)) {
            $this->services[$name] = $service();
        } elseif (is_object($service)) {
            $this->services[$name] = $service;
        } elseif (is_string($service) && class_exists($service)) {
            $this->services[$name] = new $service();
        }

        return $this;
    }

    public function singleton(string $name, mixed $service): self
    {
        if (isset($this->singletons[$name])) {
            return $this;
        }

        $this->singletons[$name] = true;

        if (is_callable($service)) {
            $this->services[$name] = $service();
        } elseif (is_object($service)) {
            $this->services[$name] = $service;
        } elseif (is_string($service) && class_exists($service)) {
            $this->services[$name] = new $service();
        }

        return $this;
    }

    public function alias(string $alias, string $target): self
    {
        $this->aliases[$alias] = $target;
        return $this;
    }

    public function get(string $service): ?object
    {
        $name = $this->resolveAlias($service);

        if (isset($this->services[$name])) {
            $service = $this->services[$name];

            if (is_callable($service) && !is_object($service)) {
                return $service();
            }

            return $service;
        }

        if (class_exists($name)) {
            try {
                return new $name();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function has(string $service): bool
    {
        $name = $this->resolveAlias($service);

        return isset($this->services[$name]) || class_exists($name);
    }

    public function make(string $class, array $params = []): ?object
    {
        if (isset($this->services[$class])) {
            return $this->services[$class];
        }

        if (class_exists($class)) {
            try {
                return new $class(...$params);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function extend(string $name, callable $callback): self
    {
        $service = $this->get($name);

        if ($service !== null) {
            $this->services[$name] = $callback($service);
        }

        return $this;
    }

    public function forget(string $name): self
    {
        unset($this->services[$name], $this->singletons[$name], $this->aliases[$name]);

        return $this;
    }

    public function registered(): array
    {
        return array_keys($this->services);
    }

    public function aliases(): array
    {
        return $this->aliases;
    }

    public function terminate(): void
    {
        $this->services = [];
        $this->aliases = [];
        $this->singletons = [];

        parent::terminate();
    }

    private function resolveAlias(string $name): string
    {
        return $this->aliases[$name] ?? $name;
    }

    private function registerCoreServices(): void
    {
        $this->singleton(GlobalProcessManager::class, GlobalProcessManager::getInstance());
    }
}
