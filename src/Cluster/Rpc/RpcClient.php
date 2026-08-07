<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Rpc;

use Kode\Process\Cluster\Node;
use Kode\Process\Exceptions\ClusterException;
use Throwable;

/**
 * 集群 RPC 客户端。
 *
 * ```php
 * $rpc = new RpcClient(timeout: 2.0);
 *
 * // 单点调用
 * $stats = $rpc->call('10.0.0.11:9700', 'node.stats');
 *
 * // 扇出到全集群（并行，总耗时约等于最慢的那台，而不是累加）
 * $results = $rpc->broadcast($registry->nodes('cache'), 'cache.evict', ['key' => 'hot:1']);
 * ```
 *
 * 连接按地址复用（长连接），断开后自动重连一次。
 *
 * @since 5.0.0
 */
final class RpcClient
{
    /** 地址 => socket 资源。 @var array<string, resource> */
    private array $pool = [];

    /** 请求序号，用于生成请求 ID。 */
    private int $seq = 0;

    /** 各连接的接收缓冲（处理粘包/半包），键为规范化地址。 @var array<string, string> */
    private array $readBuffers = [];

    /**
     * @param float       $timeout 单次调用超时（秒），含连接与读写
     * @param string|null $token   与服务端 `token` 对应的鉴权串
     * @param bool        $persistent 是否复用长连接
     */
    public function __construct(
        private readonly float $timeout = 3.0,
        private readonly ?string $token = null,
        private readonly bool $persistent = true,
    ) {
    }

    /**
     * 规范化地址：`host:port` / `tcp://host:port` 都接受。
     */
    private function normalize(string $address): string
    {
        if (str_contains($address, '://')) {
            return $address;
        }

        return 'tcp://' . $address;
    }

    private function nextId(): string
    {
        return dechex(++$this->seq) . '-' . dechex(random_int(0, 0xFFFF));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildParams(array $params): array
    {
        if ($this->token !== null) {
            $params['_token'] = $this->token;
        }

        return $params;
    }

    /**
     * 获取（或建立）到目标地址的连接。
     *
     * @return resource
     * @throws ClusterException
     */
    private function connect(string $address)
    {
        $normalized = $this->normalize($address);

        if ($this->persistent && isset($this->pool[$normalized])) {
            $socket = $this->pool[$normalized];

            // feof 能识别对端已关闭的连接，避免拿着死连接发请求
            if (is_resource($socket) && !feof($socket)) {
                return $socket;
            }

            unset($this->pool[$normalized]);
        }

        $socket = @stream_socket_client(
            $normalized,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw ClusterException::rpcFailed($address, sprintf('连接失败（%d %s）', $errno, $errstr));
        }

        stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1_000_000));

        if ($this->persistent) {
            $this->pool[$normalized] = $socket;
        }

        return $socket;
    }

    /**
     * 同步调用远程方法。
     *
     * @param  array<string, mixed> $params
     * @throws ClusterException 连接失败、超时或远端返回错误
     */
    public function call(string $address, string $method, array $params = []): mixed
    {
        $id         = $this->nextId();
        $normalized = $this->normalize($address);
        $payload    = RpcFrame::encode(RpcFrame::request($id, $method, $this->buildParams($params)));

        // 首次失败可能是长连接过期，丢弃重连再试一次
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $socket = $this->connect($address);

            if (@fwrite($socket, $payload) === false) {
                $this->drop($address);
                continue;
            }

            // 持续读帧直到匹配本次请求 ID，跳过可能滞留的服务端通知/乱序响应
            do {
                $response = $this->readFrame($socket, $normalized);

                if ($response === null) {
                    $this->drop($address);

                    if ($attempt === 0 && $this->persistent) {
                        break;
                    }

                    throw ClusterException::rpcFailed($address, '读取响应超时或连接中断');
                }
            } while (($response['i'] ?? null) !== $id);

            if (($response['o'] ?? false) !== true) {
                throw ClusterException::rpcFailed($address, (string) ($response['e'] ?? '未知错误'));
            }

            return $response['r'] ?? null;
        }

        throw ClusterException::rpcFailed($address, '写入请求失败');
    }

    /**
     * 同 {@see call()}，但失败时返回 $default 而不抛异常。
     *
     * @param array<string, mixed> $params
     */
    public function tryCall(string $address, string $method, array $params = [], mixed $default = null): mixed
    {
        try {
            return $this->call($address, $method, $params);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * 调用某个注册中心节点。
     *
     * @param array<string, mixed> $params
     */
    public function callNode(Node $node, string $method, array $params = []): mixed
    {
        return $this->call($node->address(), $method, $params);
    }

    /**
     * 单向通知：只发不等回包，适合日志上报、缓存失效广播这类不关心结果的调用。
     *
     * @param array<string, mixed> $params
     */
    public function notify(string $address, string $method, array $params = []): bool
    {
        try {
            $socket  = $this->connect($address);
            $payload = RpcFrame::encode(RpcFrame::request('', $method, $this->buildParams($params)));

            return @fwrite($socket, $payload) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 并行扇出到多个节点。
     *
     * 所有请求同时发出，用 `stream_select` 统一等待，因此总耗时约等于**最慢的那个节点**，
     * 而不是逐个串行累加。任一节点失败不影响其它节点的结果。
     *
     * @param  list<Node|string>   $targets 节点对象或 `host:port` 字符串
     * @param  array<string, mixed> $params
     * @return array<string, array{ok: bool, result?: mixed, error?: string}> 键为地址
     */
    public function broadcast(array $targets, string $method, array $params = []): array
    {
        $addresses = array_map(
            static fn (Node|string $t): string => $t instanceof Node ? $t->address() : $t,
            $targets
        );

        if ($addresses === []) {
            return [];
        }

        $sockets = [];
        $buffers = [];
        $results = [];

        // 阶段一：全部连上并把请求写出去
        foreach ($addresses as $address) {
            try {
                $socket  = $this->connect($address);
                $payload = RpcFrame::encode(RpcFrame::request($this->nextId(), $method, $this->buildParams($params)));

                if (@fwrite($socket, $payload) === false) {
                    throw ClusterException::rpcFailed($address, '写入请求失败');
                }

                $sockets[$address] = $socket;
                $buffers[$address] = '';
            } catch (Throwable $e) {
                $this->drop($address);
                $results[$address] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // 阶段二：统一 select 收包
        $deadline = microtime(true) + $this->timeout;

        while ($sockets !== [] && microtime(true) < $deadline) {
            $read   = array_values($sockets);
            $write  = null;
            $except = null;

            $left = $deadline - microtime(true);
            $sec  = (int) $left;
            $usec = (int) (($left - $sec) * 1_000_000);

            if (@stream_select($read, $write, $except, $sec, $usec) === false || $read === []) {
                break;
            }

            foreach ($read as $ready) {
                $address = array_search($ready, $sockets, true);
                if ($address === false) {
                    continue;
                }

                $chunk = @fread($ready, 65536);

                if ($chunk === false || $chunk === '') {
                    $results[$address] = ['ok' => false, 'error' => '连接中断'];
                    unset($sockets[$address]);
                    $this->drop($address);
                    continue;
                }

                $buffers[$address] .= $chunk;

                try {
                    $frame = RpcFrame::shift($buffers[$address]);
                } catch (Throwable $e) {
                    $results[$address] = ['ok' => false, 'error' => '响应解析失败：' . $e->getMessage()];
                    unset($sockets[$address]);
                    continue;
                }

                if ($frame === null) {
                    continue;   // 半包，继续等
                }

                $results[$address] = ($frame['o'] ?? false) === true
                    ? ['ok' => true, 'result' => $frame['r'] ?? null]
                    : ['ok' => false, 'error' => (string) ($frame['e'] ?? '未知错误')];

                unset($sockets[$address]);
            }
        }

        // 剩下没回包的按超时处理
        foreach (array_keys($sockets) as $address) {
            $results[$address] = ['ok' => false, 'error' => '响应超时'];
        }

        return $results;
    }

    /**
     * 阻塞读取一条完整帧。
     *
     * 缓冲跨调用保留（按连接），因此多次回包粘在一起（如 notify 回包紧跟 call 回包）
     * 时不会丢失后续帧；同时持续按请求 ID 过滤，丢弃不匹配的遗留响应。
     *
     * @param  resource $socket
     * @return array<string, mixed>|null 超时或连接中断返回 null
     */
    private function readFrame($socket, string $normalized): ?array
    {
        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            // 先尝试从已读缓冲里解析出一条完整帧
            $buffer = $this->readBuffers[$normalized] ?? '';
            if ($buffer !== '') {
                try {
                    $frame = RpcFrame::shift($buffer);
                } catch (Throwable) {
                    $this->readBuffers[$normalized] = '';
                    return null;
                }
                $this->readBuffers[$normalized] = $buffer;

                if ($frame !== null) {
                    return $frame;
                }
            }

            $read   = [$socket];
            $write  = null;
            $except = null;

            $left = $deadline - microtime(true);
            $sec  = (int) $left;
            $usec = (int) (($left - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                return null;
            }

            $chunk = @fread($socket, 65536);
            if ($chunk === false || $chunk === '') {
                return null;
            }

            $this->readBuffers[$normalized] = ($this->readBuffers[$normalized] ?? '') . $chunk;
        }

        return null;
    }

    /** 丢弃某个地址的连接。 */
    private function drop(string $address): void
    {
        $normalized = $this->normalize($address);

        if (isset($this->pool[$normalized])) {
            if (is_resource($this->pool[$normalized])) {
                @fclose($this->pool[$normalized]);
            }
            unset($this->pool[$normalized]);
        }

        unset($this->readBuffers[$normalized]);
    }

    /** 当前连接池大小。 */
    public function poolSize(): int
    {
        return count($this->pool);
    }

    /** 关闭所有连接。 */
    public function close(): void
    {
        foreach ($this->pool as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }

        $this->pool = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
