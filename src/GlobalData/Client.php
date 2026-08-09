<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

/**
 * GlobalData 网络客户端（跨主机共享数据）
 *
 * 协议与 Server 对齐：4 字节大端长度前缀 + JSON 包体。
 * 客户端严格按长度读取完整帧，避免旧版“按 65535 分块臆断收尾”导致的粘包 / 截断问题。
 */
final class Client
{
    private string $host;
    private int $port;
    private ?string $token;
    private $socket = null;
    private int $timeout = 5;
    private int $retryCount = 3;
    private int $retryDelay = 100000;

    /**
     * @param string|null $token 与服务端 `token` 对应的鉴权串；服务端未开启鉴权时留空
     */
    public function __construct(string $address = '127.0.0.1:2207', ?string $token = null)
    {
        $parts = explode(':', $address);
        $this->host = $parts[0] ?? '127.0.0.1';
        $this->port = (int) ($parts[1] ?? 2207);
        $this->token = $token;
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->exists($name);
    }

    public function __unset(string $name): void
    {
        $this->delete($name);
    }

    public function get(string $key): mixed
    {
        $response = $this->sendRequest(['action' => 'get', 'key' => $key]);
        return $response['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $response = $this->sendRequest(['action' => 'set', 'key' => $key, 'value' => $value, 'ttl' => $ttl]);
        return $response['success'] ?? false;
    }

    public function exists(string $key): bool
    {
        $response = $this->sendRequest(['action' => 'isset', 'key' => $key]);
        return $response['exists'] ?? false;
    }

    public function delete(string $key): bool
    {
        $response = $this->sendRequest(['action' => 'unset', 'key' => $key]);
        return $response['success'] ?? false;
    }

    /**
     * 原子自增。$ttl 仅在键首次创建（或过期重建）时生效——滑动窗口限流靠它划定窗口。
     */
    public function increment(string $key, int $step = 1, int $ttl = 0): int|false
    {
        $response = $this->sendRequest(['action' => 'increment', 'key' => $key, 'step' => $step, 'ttl' => $ttl]);
        return ($response['success'] ?? false) ? $response['value'] : false;
    }

    public function decrement(string $key, int $step = 1, int $ttl = 0): int|false
    {
        $response = $this->sendRequest(['action' => 'decrement', 'key' => $key, 'step' => $step, 'ttl' => $ttl]);
        return ($response['success'] ?? false) ? $response['value'] : false;
    }

    public function cas(string $key, mixed $oldValue, mixed $newValue): bool
    {
        $response = $this->sendRequest([
            'action' => 'cas',
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
        return $response['success'] ?? false;
    }

    public function keys(string $pattern = '*'): array
    {
        $response = $this->sendRequest(['action' => 'keys', 'pattern' => $pattern]);
        return $response['keys'] ?? [];
    }

    public function stats(): array
    {
        $response = $this->sendRequest(['action' => 'stats']);
        return $response['stats'] ?? [];
    }

    /**
     * 仅当键不存在时写入（服务端原子执行）。
     *
     * 服务端单线程串行处理请求，因此本操作是真正的原子 set-if-absent，
     * 可直接用作分布式锁 / Leader 选举的基石。
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $response = $this->sendRequest(['action' => 'add', 'key' => $key, 'value' => $value, 'ttl' => $ttl]);
        return $response['success'] ?? false;
    }

    /**
     * 仅当当前值等于 $oldValue 时才删除（服务端原子执行）。
     *
     * 释放分布式锁时用它，避免误删「本节点锁已超时、被他人重新获得」的锁。
     *
     * @since 5.0.0
     */
    public function casDelete(string $key, mixed $oldValue): bool
    {
        $response = $this->sendRequest(['action' => 'casdel', 'key' => $key, 'old_value' => $oldValue]);
        return $response['success'] ?? false;
    }

    /**
     * 重设键的存活时间（秒）；键不存在返回 false。
     *
     * @since 5.0.0
     */
    public function expire(string $key, int $ttl): bool
    {
        $response = $this->sendRequest(['action' => 'expire', 'key' => $key, 'ttl' => $ttl]);
        return $response['success'] ?? false;
    }

    /**
     * 带 TTL 的 CAS：值匹配时同时更新值与存活时间（锁续期）。
     *
     * @since 5.0.0
     */
    public function casWithTtl(string $key, mixed $oldValue, mixed $newValue, int $ttl): bool
    {
        $response = $this->sendRequest([
            'action'    => 'cas',
            'key'       => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ttl'       => $ttl,
        ]);
        return $response['success'] ?? false;
    }

    public function replace(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->exists($key)) {
            return false;
        }
        return $this->set($key, $value, $ttl);
    }

    /**
     * 批量读取（单次往返）。
     *
     * 只返回存在且未过期的键，服务发现列举节点时把 N 次 RTT 压成 1 次。
     */
    public function getMulti(array $keys): array
    {
        $response = $this->sendRequest(['action' => 'mget', 'keys' => array_values($keys)]);
        return $response['values'] ?? [];
    }

    public function setMulti(array $items, int $ttl = 0): bool
    {
        $success = true;
        foreach ($items as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMulti(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    private function sendRequest(array $request): array
    {
        $retry = 0;
        $lastError = null;

        if ($this->token !== null) {
            $request['_token'] = $this->token;
        }

        while ($retry < $this->retryCount) {
            try {
                if ($this->socket === null) {
                    $this->connect();
                }

                $json = json_encode($request);
                $frame = pack('N', strlen($json)) . $json;
                $sent = @socket_write($this->socket, $frame, strlen($frame));

                if ($sent === false) {
                    $this->disconnect();
                    $retry++;
                    usleep($this->retryDelay);
                    continue;
                }

                $header = $this->readExact(4);
                if ($header === null) {
                    $this->disconnect();
                    $retry++;
                    usleep($this->retryDelay);
                    continue;
                }
                $len = unpack('N', $header)[1];
                // 与服务端一致的帧长上限，避免异常 / 恶意长度前缀撑爆客户端内存
                if ($len > Server::MAX_FRAME_SIZE) {
                    $this->disconnect();
                    $retry++;
                    usleep($this->retryDelay);
                    continue;
                }
                $body = $this->readExact($len);
                if ($body === null) {
                    $this->disconnect();
                    $retry++;
                    usleep($this->retryDelay);
                    continue;
                }

                $decoded = json_decode($body, true);
                return is_array($decoded) ? $decoded : ['success' => false, 'error' => 'Invalid response'];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->disconnect();
                $retry++;
                usleep($this->retryDelay);
            }
        }

        return ['success' => false, 'error' => $lastError ?? 'Connection failed'];
    }

    private function readExact(int $n): ?string
    {
        $data = '';
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = @socket_read($this->socket, min($remaining, 65535), PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    private function connect(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            throw new \RuntimeException('Failed to create socket');
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);

        if (!@socket_connect($this->socket, $this->host, $this->port)) {
            socket_close($this->socket);
            $this->socket = null;
            throw new \RuntimeException('Failed to connect to GlobalData server');
        }
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function setRetryCount(int $count): self
    {
        $this->retryCount = $count;
        return $this;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
