<?php

declare(strict_types=1);

namespace Kode\Process\Integration;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;

final class LaravelAdapter extends AbstractFrameworkAdapter
{
    private ?object $app = null;

    public function getName(): string
    {
        return 'Laravel';
    }

    public function getVersion(): string
    {
        if (!$this->isAvailable()) {
            return '0.0.0';
        }

        if (function_exists('app')) {
            return \app()->version() ?? '0.0.0';
        }

        return '0.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists(\Illuminate\Foundation\Application::class) ||
               function_exists('app');
    }

    public function boot(): Response
    {
        $response = parent::boot();

        if (!$response->isSuccess()) {
            return $response;
        }

        try {
            if (function_exists('app')) {
                $this->app = \app();
                $this->container = $this->app;

                if ($this->app->bound('config')) {
                    $this->config['env'] = $this->app->environment();
                    $this->config['debug'] = $this->app->hasDebugModeEnabled();
                }
            }

            return Response::ok([
                'booted' => true,
                'version' => $this->getVersion(),
                'env' => $this->environment(),
            ], 'Laravel 框架启动成功');
        } catch (\Throwable $e) {
            return Response::error('Laravel 启动失败: ' . $e->getMessage());
        }
    }

    public function register(array $config = []): Response
    {
        parent::register($config);

        if ($this->app === null) {
            return Response::error('Laravel 应用未初始化');
        }

        try {
            if (method_exists($this->app, 'singleton')) {
                $this->app->singleton(GlobalProcessManager::class, function () {
                    return GlobalProcessManager::getInstance();
                });
            }

            return Response::ok(['registered' => true], 'Laravel 服务注册成功');
        } catch (\Throwable $e) {
            return Response::error('Laravel 服务注册失败: ' . $e->getMessage());
        }
    }

    public function get(string $service): ?object
    {
        if ($this->app === null) {
            return null;
        }

        try {
            return $this->app->make($service);
        } catch (\Throwable) {
            return null;
        }
    }

    public function has(string $service): bool
    {
        if ($this->app === null) {
            return false;
        }

        try {
            return $this->app->bound($service);
        } catch (\Throwable) {
            return false;
        }
    }

    public function make(string $class, array $params = []): ?object
    {
        if ($this->app === null) {
            return null;
        }

        try {
            return $this->app->make($class, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    public function call(callable $callback, array $params = []): mixed
    {
        if ($this->app === null) {
            return $callback(...$params);
        }

        try {
            return $this->app->call($callback, $params);
        } catch (\Throwable) {
            return $callback(...$params);
        }
    }

    public function config(string $key, mixed $default = null): mixed
    {
        if ($this->app !== null && $this->app->bound('config')) {
            return $this->app['config']->get($key, $default);
        }

        return parent::config($key, $default);
    }

    public function environment(): string
    {
        if ($this->app !== null && method_exists($this->app, 'environment')) {
            return $this->app->environment();
        }

        return parent::environment();
    }

    public function isDebug(): bool
    {
        if ($this->app !== null && method_exists($this->app, 'hasDebugModeEnabled')) {
            return $this->app->hasDebugModeEnabled();
        }

        return parent::isDebug();
    }

    public function terminate(): void
    {
        if ($this->app !== null && method_exists($this->app, 'terminate')) {
            $this->app->terminate();
        }

        parent::terminate();
        $this->app = null;
    }

    public function getApplication(): ?object
    {
        return $this->app;
    }
}
