<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Fibers\Fibers;
use Kode\Process\Cluster\ClusterManager;
use Kode\Process\Exceptions\ParallelException;
use Kode\Process\Integration\IntegrationManager;
use Kode\Process\Parallel\FutureInterface;
use Kode\Process\Parallel\Parallel;
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

    /**
     * 检查运行环境，返回问题清单（为空表示通过）
     *
     * @return list<string>
     */
    public static function checkEnvironment(): array
    {
        return Version::checkEnvironment();
    }

    /**
     * 断言运行环境满足要求，不满足时抛出异常
     *
     * @throws Exceptions\ProcessException
     */
    public static function requireEnvironment(): void
    {
        Version::requireSupportedEnvironment();
    }

    /**
     * 运行环境与版本信息
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return Version::getInfo();
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
     * 在并行（多线程）运行时中执行任务
     *
     * 将 CPU 密集型任务投到独立 OS 线程（需 ZTS + ext-parallel）。
     * 与 {@see self::go()}/{@see self::batch()} 的协作式协程互补：
     * 协程负责 I/O 并发，并行负责 CPU 并发。返回值经 {@see self::awaitParallel()} 在协程内等待。
     *
     * @param callable $task 要在独立线程中执行的可调用体（不能捕获 $this / 引用外部变量）
     * @param mixed    ...$args 传给任务的参数
     *
     * @return FutureInterface 可用于 {@see self::awaitParallel()} 等待结果
     *
     * @throws ParallelException 当前环境不支持真正的多线程时
     *
     * @example
     * // 协程内等待并行任务（ZTS + ext-parallel 环境）
     * $future = Kode::parallel(fn($n) => heavyCompute($n), 42);
     * $result = Kode::awaitParallel($future);
     */
    public static function parallel(callable $task, mixed ...$args): FutureInterface
    {
        return Parallel::run($task, ...$args);
    }

    /**
     * 等待并行任务完成并获取结果
     *
     * 在协程（Fiber）内调用时会挂起当前协程，由所在事件循环（Async 或 kode/fibers FiberPool）
     * 在任务完成后自动恢复，等待期间不阻塞其它协程；普通上下文则阻塞等待。
     *
     * @throws \Throwable 任务执行失败时透传原异常
     *
     * @see Parallel::await()
     */
    public static function awaitParallel(FutureInterface $future): mixed
    {
        return Parallel::await($future);
    }

    /**
     * 当前是否支持真正的多线程并行（ZTS + ext-parallel）
     */
    public static function supportsParallel(): bool
    {
        return Parallel::isAvailable();
    }

    /**
     * 当前并行后端：'ext-parallel' | 'kode-parallel' | 'none'
     */
    public static function parallelBackend(): string
    {
        return Parallel::backend();
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
