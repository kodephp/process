<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Fibers\Fibers;
use Kode\Process\Cluster\ClusterManager;
use Kode\Process\Integration\IntegrationManager;
use Kode\Process\Protocol\ProtocolManager;
use Psr\Log\LoggerInterface;

/**
 * Kode Process 静态入口类
 * 
 * 提供极简 API，一行代码启动服务器
 * 
 * @example
 * // 统一使用 worker 方法，自动解析协议
 * Kode::worker('http://0.0.0.0:8080', 4)->start();
 * Kode::worker('websocket://0.0.0.0:8081', 4)->start();
 * Kode::worker('tcp://0.0.0.0:9000', 4)->start();
 */
final class Kode
{
    public static function version(): string
    {
        return Version::get();
    }

    public static function phpVersion(): string
    {
        return PhpCompat::version();
    }

    public static function phpVersionId(): int
    {
        return PhpCompat::versionId();
    }

    public static function isPhp81(): bool
    {
        return PhpCompat::isPhp81();
    }

    public static function isPhp82(): bool
    {
        return PhpCompat::isPhp82();
    }

    public static function isPhp83(): bool
    {
        return PhpCompat::isPhp83();
    }

    public static function isPhp84(): bool
    {
        return PhpCompat::isPhp84();
    }

    public static function isPhp85(): bool
    {
        return PhpCompat::isPhp85();
    }

    public static function hasPipeOperator(): bool
    {
        return PhpCompat::hasPipeOperator();
    }

    public static function hasPersistentCurlShare(): bool
    {
        return PhpCompat::hasPersistentCurlShare();
    }

    public static function app(array $config = []): Application
    {
        return Application::create($config);
    }

    /**
     * 创建 Worker（统一入口）
     * 
     * 根据地址前缀自动解析协议：
     * - http:// -> HTTP 协议
     * - https:// -> HTTPS 协议
     * - websocket:// -> WebSocket 协议
     * - ws:// -> WebSocket 协议
     * - tcp:// -> TCP 原始协议
     * - text:// -> 文本+换行符协议
     * - udp:// -> UDP 协议
     * - ssl:// -> SSL/TLS 协议
     * 
     * @param string $address 监听地址（包含协议前缀）
     * @param int $count Worker 进程数
     * @return Application
     * 
     * @example
     * // HTTP 服务
     * Kode::worker('http://0.0.0.0:8080', 4)
     *     ->onMessage(fn($conn, $req) => $conn->send('Hello'))
     *     ->start();
     * 
     * // WebSocket 服务
     * Kode::worker('websocket://0.0.0.0:8081', 4)
     *     ->onMessage(fn($conn, $data) => $conn->send($data))
     *     ->start();
     * 
     * // TCP 服务
     * Kode::worker('tcp://0.0.0.0:9000', 4)
     *     ->onMessage(fn($conn, $data) => $conn->send($data))
     *     ->start();
     */
    public static function worker(string $address, int $count = 4): Application
    {
        $protocol = self::parseProtocol($address);
        
        return Application::create(['worker_count' => $count])
            ->listen($address, ['protocol' => $protocol]);
    }

    /**
     * 解析协议
     */
    private static function parseProtocol(string $address): string
    {
        $parsed = parse_url($address);
        return $parsed['scheme'] ?? 'tcp';
    }

    /**
     * 创建服务器实例
     */
    public static function server(array $config = []): Server
    {
        return Server::create($config);
    }

    /**
     * 获取集群管理器
     */
    public static function cluster(array $config): ClusterManager
    {
        return ClusterManager::getInstance($config);
    }

    /**
     * 获取全局进程管理器
     */
    public static function processManager(): GlobalProcessManager
    {
        return GlobalProcessManager::getInstance();
    }

    /**
     * 获取协议管理器
     */
    public static function protocolManager(): ProtocolManager
    {
        return ProtocolManager::getInstance();
    }

    /**
     * 获取协程管理器
     */
    public static function coroutine(?string $driver = null): Coroutine\CoroutineManager
    {
        return Coroutine\CoroutineManager::getInstance($driver);
    }

    /**
     * 创建协程
     * 
     * @param callable $callback 协程回调
     * @return mixed 协程返回值
     */
    public static function go(callable $callback): mixed
    {
        return Coroutine\CoroutineManager::getInstance()->go($callback);
    }

    /**
     * 批量并发执行
     * 
     * @param array $items 数据项
     * @param callable $callback 处理回调
     * @param int $concurrency 并发数
     * @return array 结果数组
     */
    public static function batch(array $items, callable $callback, int $concurrency = 10): array
    {
        return Coroutine\CoroutineManager::getInstance()->batch($items, $callback, $concurrency);
    }

    /**
     * 获取集成管理器
     */
    public static function integration(): IntegrationManager
    {
        return IntegrationManager::getInstance();
    }

    /**
     * 集成 Laravel
     */
    public static function laravel(): ?IntegrationManager
    {
        $manager = IntegrationManager::getInstance();
        $response = $manager->boot('laravel');
        return $response->isSuccess() ? $manager : null;
    }

    /**
     * 集成 Symfony
     */
    public static function symfony(array $config = []): ?IntegrationManager
    {
        $manager = IntegrationManager::getInstance();
        $response = $manager->boot('symfony', $config);
        return $response->isSuccess() ? $manager : null;
    }

    /**
     * 创建成功响应
     */
    public static function response(mixed $data = null, string $message = 'success'): Response
    {
        return Response::ok($data, $message);
    }

    /**
     * 创建错误响应
     */
    public static function error(string $message = 'error', int $code = Response::CODE_ERROR): Response
    {
        return Response::error($message, $code);
    }

    /**
     * 运行应用
     */
    public static function run(): void
    {
        Application::run();
    }

    /**
     * 停止应用
     */
    public static function stop(bool $graceful = true): void
    {
        Application::shutdown($graceful);
    }
}
