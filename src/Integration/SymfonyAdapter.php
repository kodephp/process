<?php

declare(strict_types=1);

namespace Kode\Process\Integration;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;

final class SymfonyAdapter extends AbstractFrameworkAdapter
{
    private ?object $kernel = null;

    public function getName(): string
    {
        return 'Symfony';
    }

    public function getVersion(): string
    {
        if (!$this->isAvailable()) {
            return '0.0.0';
        }

        if (class_exists(\Symfony\Component\HttpKernel\Kernel::class)) {
            return \Symfony\Component\HttpKernel\Kernel::VERSION;
        }

        return '0.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists(\Symfony\Component\HttpKernel\Kernel::class) ||
               interface_exists(\Psr\Container\ContainerInterface::class);
    }

    public function boot(): Response
    {
        $response = parent::boot();

        if (!$response->isSuccess()) {
            return $response;
        }

        try {
            if (isset($this->config['kernel_class']) && class_exists($this->config['kernel_class'])) {
                $kernelClass = $this->config['kernel_class'];
                $env = $this->config['env'] ?? 'prod';
                $debug = $this->config['debug'] ?? false;

                $this->kernel = new $kernelClass($env, $debug);
                $this->kernel->boot();
                $this->container = $this->kernel->getContainer();
            }

            return Response::ok([
                'booted' => true,
                'version' => $this->getVersion(),
                'env' => $this->environment(),
            ], 'Symfony 框架启动成功');
        } catch (\Throwable $e) {
            return Response::error('Symfony 启动失败: ' . $e->getMessage());
        }
    }

    public function register(array $config = []): Response
    {
        parent::register($config);

        if ($this->container === null) {
            return Response::ok(['registered' => true], 'Symfony 容器未初始化');
        }

        try {
            if (method_exists($this->container, 'set')) {
                $this->container->set(GlobalProcessManager::class, GlobalProcessManager::getInstance());
            }

            return Response::ok(['registered' => true], 'Symfony 服务注册成功');
        } catch (\Throwable $e) {
            return Response::error('Symfony 服务注册失败: ' . $e->getMessage());
        }
    }

    public function get(string $service): ?object
    {
        if ($this->container === null) {
            return null;
        }

        try {
            if ($this->container->has($service)) {
                return $this->container->get($service);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function has(string $service): bool
    {
        if ($this->container === null) {
            return false;
        }

        try {
            return $this->container->has($service);
        } catch (\Throwable) {
            return false;
        }
    }

    public function config(string $key, mixed $default = null): mixed
    {
        if ($this->container !== null && $this->container->has('parameter_bag')) {
            $parameterBag = $this->container->get('parameter_bag');

            if (method_exists($parameterBag, 'get')) {
                return $parameterBag->get($key) ?? $default;
            }
        }

        return parent::config($key, $default);
    }

    public function environment(): string
    {
        if ($this->kernel !== null && method_exists($this->kernel, 'getEnvironment')) {
            return $this->kernel->getEnvironment();
        }

        return parent::environment();
    }

    public function isDebug(): bool
    {
        if ($this->kernel !== null && method_exists($this->kernel, 'isDebug')) {
            return $this->kernel->isDebug();
        }

        return parent::isDebug();
    }

    public function terminate(): void
    {
        if ($this->kernel !== null && method_exists($this->kernel, 'shutdown')) {
            $this->kernel->shutdown();
        }

        parent::terminate();
        $this->kernel = null;
    }

    public function getKernel(): ?object
    {
        return $this->kernel;
    }
}
