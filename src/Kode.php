<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Cluster\ClusterManager;
use Kode\Process\Fiber\FiberPool;
use Kode\Process\Fiber\FiberScheduler;
use Kode\Process\Integration\IntegrationManager;
use Kode\Process\Protocol\ProtocolFactory;
use Kode\Process\Protocol\ProtocolManager;
use Psr\Log\LoggerInterface;

use Kode\Process\Version;

final class Kode
{
    public static function version(): string
    {
        return Version::get();
    }

    public static function app(array $config = []): Application
    {
        return Application::create($config);
    }

    public static function worker(string $address, int $count = 4): Application
    {
        return Application::create(['worker_count' => $count])
            ->listen($address);
    }

    public static function http(string $address = 'http://0.0.0.0:8080', int $count = 4): Application
    {
        return Application::create(['worker_count' => $count])
            ->http($address);
    }

    public static function websocket(string $address = 'websocket://0.0.0.0:8081', int $count = 4): Application
    {
        return Application::create(['worker_count' => $count])
            ->websocket($address);
    }

    public static function tcp(string $address = 'tcp://0.0.0.0:9000', int $count = 4): Application
    {
        return Application::create(['worker_count' => $count])
            ->tcp($address);
    }

    public static function text(string $address = 'text://0.0.0.0:9001', int $count = 4): Application
    {
        return Application::create(['worker_count' => $count])
            ->text($address);
    }

    public static function server(array $config = []): Server
    {
        return Server::create($config);
    }

    public static function cluster(array $config): ClusterManager
    {
        return ClusterManager::getInstance($config);
    }

    public static function processManager(): GlobalProcessManager
    {
        return GlobalProcessManager::getInstance();
    }

    public static function protocolManager(): ProtocolManager
    {
        return ProtocolManager::getInstance();
    }

    public static function fiberScheduler(): FiberScheduler
    {
        return FiberScheduler::getInstance();
    }

    public static function fiberPool(int $concurrency = 100): FiberPool
    {
        return new FiberPool($concurrency);
    }

    public static function integration(): IntegrationManager
    {
        return IntegrationManager::getInstance();
    }

    public static function laravel(): ?IntegrationManager
    {
        $manager = IntegrationManager::getInstance();
        $response = $manager->boot('laravel');
        return $response->isSuccess() ? $manager : null;
    }

    public static function symfony(array $config = []): ?IntegrationManager
    {
        $manager = IntegrationManager::getInstance();
        $response = $manager->boot('symfony', $config);
        return $response->isSuccess() ? $manager : null;
    }

    public static function response(mixed $data = null, string $message = 'success'): Response
    {
        return Response::ok($data, $message);
    }

    public static function error(string $message = 'error', int $code = Response::CODE_ERROR): Response
    {
        return Response::error($message, $code);
    }

    public static function run(): void
    {
        Application::run();
    }

    public static function stop(bool $graceful = true): void
    {
        Application::shutdown($graceful);
    }
}
