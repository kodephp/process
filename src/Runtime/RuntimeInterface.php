<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

/**
 * 运行时契约：一套 API，三种实现。
 *
 * 应用只面向本接口编程，即可在 Swoole / Workerman 之间无缝切换：
 *
 * ```php
 * $rt = Runtime::auto();                       // 自动择优
 * $rt->listen('http://0.0.0.0:8080', ['workers' => 4]);
 * $rt->on('message', fn($conn, $req) => $conn->send('Hello'));
 * $rt->start();
 * ```
 *
 * 支持的事件名：
 *  - workerStart(int $workerId)
 *  - workerStop(int $workerId)
 *  - connect(ConnectionInterface $conn)
 *  - message(ConnectionInterface $conn, mixed $data)
 *  - close(ConnectionInterface $conn)
 *  - error(ConnectionInterface|null $conn, \Throwable $e)
 */
interface RuntimeInterface
{
    /** 当前环境是否可用 */
    public static function isAvailable(): bool;

    /** 运行时类型 */
    public static function type(): RuntimeType;

    /** 底层运行时版本号；不可用时返回 null */
    public static function version(): ?string;

    /**
     * 监听地址。
     *
     * @param string $address 形如 tcp://0.0.0.0:9501、http://0.0.0.0:8080、
     *                        websocket://0.0.0.0:8080、unix:///tmp/app.sock
     * @param array{
     *     workers?:int,
     *     name?:string,
     *     reusePort?:bool,
     *     ssl?:array<string,mixed>,
     *     maxRequest?:int,
     *     backlog?:int
     * } $options
     * @throws Exception\RuntimeNotSupportedException 运行时不支持该地址协议
     */
    public function listen(string $address, array $options = []): static;

    /**
     * 注册事件回调。
     *
     * @param string $event 事件名，见类文档
     * @param callable $handler
     * @throws \InvalidArgumentException 未知事件名
     */
    public function on(string $event, callable $handler): static;

    /** 启动服务（阻塞，直到 stop()） */
    public function start(): void;

    /**
     * 停止服务。
     *
     * @param bool $graceful true=等待在途请求处理完毕
     */
    public function stop(bool $graceful = true): void;

    /**
     * 平滑重载 worker 进程（不中断服务）。
     *
     * @throws Exception\RuntimeNotSupportedException 运行时不支持 HotReload
     */
    public function reload(): void;

    /**
     * 添加定时器。
     *
     * @param float $interval 间隔秒数
     * @return int 定时器 ID
     */
    public function addTimer(float $interval, callable $callback, bool $periodic = true): int;

    public function delTimer(int $timerId): bool;

    /** 查询是否具备某项能力 */
    public function supports(Capability $capability): bool;

    /**
     * 本运行时的全部能力。
     *
     * @return list<Capability>
     */
    public function capabilities(): array;

    /**
     * 运行期状态快照。
     *
     * @return array<string, mixed>
     */
    public function stats(): array;

    /** 服务是否正在运行 */
    public function isRunning(): bool;
}
