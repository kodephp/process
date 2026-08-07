<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Rpc;

use JsonException;
use Kode\Process\Runtime;
use Kode\Process\Runtime\ConnectionInterface;
use Kode\Process\Runtime\RuntimeInterface;
use Throwable;

/**
 * 集群 RPC 服务端——让节点之间能互相调用方法。
 *
 * 建在 {@see RuntimeInterface} 之上，因此 Native / Swoole / Workerman 三种运行时
 * 都能跑，且代码完全一致。
 *
 * ```php
 * $server = new RpcServer();
 *
 * $server->register('cache.evict', function (array $params) {
 *     Cache::forget($params['key']);
 *     return ['evicted' => $params['key']];
 * });
 *
 * $server->register('node.stats', fn () => Kode::diagnose());
 *
 * $server->listen('tcp://0.0.0.0:9700', ['workers' => 2])->start();
 * ```
 *
 * 报文在裸 TCP 上按 {@see RpcFrame} 自行拆包，每个连接维护独立缓冲区，
 * 因此粘包/半包都能正确处理。
 *
 * 安全提示：RPC 端口不应暴露到公网。请绑内网地址，或用 `token` 选项开启简单校验。
 *
 * @since 5.0.0
 */
final class RpcServer
{
    /** @var array<string, callable(array<string, mixed>, ConnectionInterface|null): mixed> */
    private array $handlers = [];

    /** 各连接的接收缓冲区，键为连接 ID。 @var array<int, string> */
    private array $buffers = [];

    private ?RuntimeInterface $runtime = null;

    /** 已处理请求数。 */
    private int $handled = 0;

    /** 失败请求数。 */
    private int $failed = 0;

    /**
     * @param string|null $token 非空时，请求需在 params 中带上 `_token` 且匹配
     */
    public function __construct(private readonly ?string $token = null)
    {
    }

    /**
     * 注册一个可被远程调用的方法。
     *
     * 处理器签名：`fn(array $params, ?ConnectionInterface $conn): mixed`，
     * 返回值会被 JSON 序列化后回给调用方。
     *
     * @param callable(array<string, mixed>, ConnectionInterface|null): mixed $handler
     */
    public function register(string $method, callable $handler): self
    {
        $this->handlers[$method] = $handler;

        return $this;
    }

    /**
     * 批量注册。
     *
     * @param array<string, callable> $handlers
     */
    public function registerMany(array $handlers): self
    {
        foreach ($handlers as $method => $handler) {
            $this->register($method, $handler);
        }

        return $this;
    }

    /** 已注册的方法名。 @return list<string> */
    public function methods(): array
    {
        return array_keys($this->handlers);
    }

    public function runtime(): ?RuntimeInterface
    {
        return $this->runtime;
    }

    /**
     * 处理一条请求报文，返回响应报文。
     *
     * 纯函数式，不依赖连接，便于单元测试。
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request, ?ConnectionInterface $conn = null): array
    {
        $id     = (string) ($request['i'] ?? '');
        $method = (string) ($request['m'] ?? '');
        $params = (array) ($request['p'] ?? []);

        if ($method === '') {
            $this->failed++;

            return RpcFrame::fail($id, '缺少方法名');
        }

        if ($this->token !== null && (string) ($params['_token'] ?? '') !== $this->token) {
            $this->failed++;

            return RpcFrame::fail($id, '鉴权失败');
        }

        $handler = $this->handlers[$method] ?? null;
        if ($handler === null) {
            $this->failed++;

            return RpcFrame::fail($id, sprintf('未注册的方法 %s', $method));
        }

        try {
            $result = $handler($params, $conn);
            $this->handled++;

            return RpcFrame::ok($id, $result);
        } catch (Throwable $e) {
            $this->failed++;

            // 只回错误摘要，不外泄调用栈
            return RpcFrame::fail($id, $e->getMessage());
        }
    }

    /**
     * 在指定运行时上监听 RPC 端口。
     *
     * @param string                          $address 形如 `tcp://0.0.0.0:9700`
     * @param array<string, mixed>            $options 透传给运行时（workers、reusePort…）
     * @param RuntimeInterface|string|null    $runtime 运行时实例或名称；null 表示自动择优（默认 Native）
     */
    public function listen(
        string $address,
        array $options = [],
        RuntimeInterface|string|null $runtime = null,
    ): RuntimeInterface {
        $rt = $runtime instanceof RuntimeInterface
            ? $runtime
            : ($runtime === null ? Runtime::auto() : Runtime::make($runtime));

        $rt->listen($address, $options);

        $rt->on('message', function (ConnectionInterface $conn, mixed $data): void {
            $this->onMessage($conn, is_string($data) ? $data : (string) json_encode($data));
        });

        $rt->on('close', function (ConnectionInterface $conn): void {
            unset($this->buffers[$conn->id()]);
        });

        return $this->runtime = $rt;
    }

    /**
     * 累积并解析帧，逐条应答。
     */
    private function onMessage(ConnectionInterface $conn, string $chunk): void
    {
        $cid                  = $conn->id();
        $this->buffers[$cid] = ($this->buffers[$cid] ?? '') . $chunk;

        while (true) {
            try {
                $request = RpcFrame::shift($this->buffers[$cid]);
            } catch (JsonException $e) {
                // 报文体损坏：回一条错误并丢弃剩余缓冲，防止后续全部错位
                $conn->send(RpcFrame::encode(RpcFrame::fail('', '报文解析失败：' . $e->getMessage())), true);
                $this->buffers[$cid] = '';
                $this->failed++;

                return;
            }

            if ($request === null) {
                return;
            }

            $conn->send(RpcFrame::encode($this->handle($request, $conn)), true);
        }
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'methods'     => count($this->handlers),
            'handled'     => $this->handled,
            'failed'      => $this->failed,
            'connections' => count($this->buffers),
            'auth'        => $this->token !== null,
        ];
    }
}
