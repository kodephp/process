<?php

declare(strict_types=1);

namespace Kode\Process\Task;

/**
 * 异步任务客户端
 * 
 * 用于向任务服务器发送异步任务
 */
final class TaskClient
{
    private string $host;
    private int $port;
    private $socket = null;
    private int $timeout = 5;

    public function __construct(string $address = '127.0.0.1:12345')
    {
        $parts = explode(':', $address);
        $this->host = $parts[0] ?? '127.0.0.1';
        $this->port = (int)($parts[1] ?? 12345);
    }

    public function connect(): bool
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->socket === false) {
            return false;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);

        return @socket_connect($this->socket, $this->host, $this->port);
    }

    public function send(string $type, mixed $data, ?callable $callback = null): bool
    {
        if ($this->socket === null && !$this->connect()) {
            return false;
        }

        $message = json_encode(['type' => $type, 'data' => $data]) . "\n";
        $sent = @socket_write($this->socket, $message);

        if ($sent === false) {
            return false;
        }

        if ($callback !== null) {
            $response = @socket_read($this->socket, 65535);

            if ($response !== false) {
                $result = json_decode($response, true);
                $callback($result['result'] ?? null);
            }
        }

        return true;
    }

    public function sendAsync(string $type, mixed $data): bool
    {
        return $this->send($type, $data, null);
    }

    public function close(): void
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
}
