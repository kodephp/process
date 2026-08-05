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
    private $socket = null;
    private int $timeout = 5;
    private int $retryCount = 3;
    private int $retryDelay = 100000;

    public function __construct(string $address = '127.0.0.1:2207')
    {
        $parts = explode(':', $address);
        $this->host = $parts[0] ?? '127.0.0.1';
        $this->port = (int) ($parts[1] ?? 2207);
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

    public function increment(string $key, int $step = 1): int|false
    {
        $response = $this->sendRequest(['action' => 'increment', 'key' => $key, 'step' => $step]);
        return ($response['success'] ?? false) ? $response['value'] : false;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        $response = $this->sendRequest(['action' => 'decrement', 'key' => $key, 'step' => $step]);
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

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($this->exists($key)) {
            return false;
        }
        return $this->set($key, $value, $ttl);
    }

    public function replace(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->exists($key)) {
            return false;
        }
        return $this->set($key, $value, $ttl);
    }

    public function getMulti(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
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
