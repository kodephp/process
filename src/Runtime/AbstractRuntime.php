<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

use InvalidArgumentException;
use Kode\Process\Runtime\Exception\RuntimeNotSupportedException;

/**
 * 运行时公共实现：事件注册、地址解析、配置归一。
 */
abstract class AbstractRuntime implements RuntimeInterface
{
    public const EVENTS = [
        'workerStart',
        'workerStop',
        'connect',
        'message',
        'close',
        'error',
        'task',
        'finish',
    ];

    /** @var array<string, callable> */
    protected array $handlers = [];

    /**
     * 监听项。
     *
     * @var list<array{scheme:string, host:string, port:int, path:string, options:array<string,mixed>}>
     */
    protected array $listeners = [];

    protected bool $running = false;

    /** @var array<int, int> 定时器 ID 映射（本包 ID → 底层 ID） */
    protected array $timers = [];

    protected int $timerSeq = 0;

    public function on(string $event, callable $handler): static
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException(sprintf(
                '未知事件 "%s"，可用事件：%s',
                $event,
                implode(', ', self::EVENTS)
            ));
        }
        $this->handlers[$event] = $handler;
        return $this;
    }

    public function listen(string $address, array $options = []): static
    {
        $parsed = self::parseAddress($address);
        $scheme = $parsed['scheme'];

        if (!in_array($scheme, $this->supportedSchemes(), true)) {
            throw RuntimeNotSupportedException::scheme(static::type(), $scheme);
        }
        if (($options['reusePort'] ?? false) && !$this->supports(Capability::ReusePort)) {
            throw RuntimeNotSupportedException::capability(static::type(), Capability::ReusePort);
        }
        if (isset($options['ssl']) && !$this->supports(Capability::Ssl)) {
            throw RuntimeNotSupportedException::capability(static::type(), Capability::Ssl);
        }

        $parsed['options'] = $options + [
            'workers'    => 4,
            'name'       => 'kode-process',
            'reusePort'  => false,
            'maxRequest' => 0,
            'backlog'    => 65535,
        ];
        $this->listeners[] = $parsed;

        return $this;
    }

    /**
     * 解析监听地址。
     *
     * @return array{scheme:string, host:string, port:int, path:string, options:array<string,mixed>}
     * @throws InvalidArgumentException 地址格式非法
     */
    public static function parseAddress(string $address): array
    {
        if (!str_contains($address, '://')) {
            throw new InvalidArgumentException(sprintf(
                '监听地址缺少协议前缀："%s"，示例：tcp://0.0.0.0:9501',
                $address
            ));
        }

        [$scheme, $rest] = explode('://', $address, 2);
        $scheme = strtolower($scheme);

        if ($scheme === 'unix') {
            if ($rest === '') {
                throw new InvalidArgumentException('unix:// 地址缺少 socket 文件路径');
            }
            return ['scheme' => 'unix', 'host' => '', 'port' => 0, 'path' => $rest, 'options' => []];
        }

        $pos = strrpos($rest, ':');
        if ($pos === false) {
            throw new InvalidArgumentException(sprintf('监听地址缺少端口："%s"', $address));
        }

        $host = substr($rest, 0, $pos);
        $port = (int)substr($rest, $pos + 1);

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('端口号非法：%d（应在 1-65535）', $port));
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => '', 'options' => []];
    }

    /**
     * 本运行时支持的协议。
     *
     * @return list<string>
     */
    abstract protected function supportedSchemes(): array;

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function reload(): void
    {
        throw RuntimeNotSupportedException::capability(static::type(), Capability::HotReload);
    }

    /**
     * 触发事件回调，异常统一交由 error 处理器，避免单个回调异常打挂整个 worker。
     */
    protected function fire(string $event, mixed ...$args): mixed
    {
        $handler = $this->handlers[$event] ?? null;
        if ($handler === null) {
            return null;
        }

        try {
            return $handler(...$args);
        } catch (\Throwable $e) {
            if ($event !== 'error' && isset($this->handlers['error'])) {
                $conn = $args[0] instanceof ConnectionInterface ? $args[0] : null;
                ($this->handlers['error'])($conn, $e);
                return null;
            }
            throw $e;
        }
    }

    /**
     * 派发 message 事件——热路径专用的定参版本。
     *
     * 语义与 `fire('message', $connection, $message)` 完全一致，包括异常兜底；
     * 唯一的区别是不经过可变参数：`...$args` 每次调用都要打包一个数组、
     * 再在调用点展开，而 message 是唯一每请求都必然触发一次的事件，
     * 这笔固定开销会被请求量整体放大。
     */
    protected function fireMessage(mixed $connection, mixed $message): void
    {
        $handler = $this->handlers['message'] ?? null;
        if ($handler === null) {
            return;
        }

        try {
            $handler($connection, $message);
        } catch (\Throwable $e) {
            if (isset($this->handlers['error'])) {
                ($this->handlers['error'])(
                    $connection instanceof ConnectionInterface ? $connection : null,
                    $e
                );
                return;
            }
            throw $e;
        }
    }

    protected function hasHandler(string $event): bool
    {
        return isset($this->handlers[$event]);
    }

    /** 第一个监听项的配置，未配置时返回空数组 */
    protected function primaryOptions(): array
    {
        return $this->listeners[0]['options'] ?? [];
    }

    /**
     * @throws RuntimeNotSupportedException 未调用 listen()
     */
    protected function requireListener(): array
    {
        if ($this->listeners === []) {
            throw new RuntimeNotSupportedException('尚未调用 listen() 配置监听地址');
        }
        return $this->listeners[0];
    }

    /**
     * 当前 worker 序号。未运行或运行时无此概念时返回 0。
     */
    public function workerId(): int
    {
        return 0;
    }

    /**
     * 当前 worker 持有的连接。
     *
     * 各运行时只能看到**本进程**的连接，跨进程广播请用共享表 / 队列协同。
     *
     * @return array<int, ConnectionInterface>
     */
    public function connections(): array
    {
        return [];
    }

    /**
     * 向当前 worker 的全部连接群发。
     *
     * @return int 实际投递成功的连接数
     */
    public function broadcast(string $data, bool $raw = false): int
    {
        $sent = 0;
        foreach ($this->connections() as $conn) {
            if ($conn->isAlive() && $conn->send($data, $raw)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * 投递任务。
     *
     * 运行时具备 {@see Capability::TaskWorker} 时投递到独立任务进程（异步）；
     * 不具备时**降级为当前进程内同步执行** `task` 回调，保证业务代码在切换
     * 运行时后无需改动。可用 `supports(Capability::TaskWorker)` 判断是否异步。
     *
     * @throws RuntimeNotSupportedException 未注册 task 回调且运行时不支持任务进程
     */
    public function task(mixed $data): bool
    {
        if (!$this->hasHandler('task')) {
            throw RuntimeNotSupportedException::capability(static::type(), Capability::TaskWorker);
        }

        $result = $this->fire('task', $data, $this->workerId());
        if ($result !== null && $this->hasHandler('finish')) {
            $this->fire('finish', $result);
        }

        return true;
    }

    public function stats(): array
    {
        return [
            'runtime'   => static::type()->value,
            'version'   => static::version(),
            'running'   => $this->running,
            'listeners' => count($this->listeners),
            'timers'    => count($this->timers),
            'events'    => array_keys($this->handlers),
        ];
    }
}
