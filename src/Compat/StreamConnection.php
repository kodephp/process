<?php

declare(strict_types=1);

namespace Kode\Process\Compat;

/**
 * Stream 连接类
 * 
 * 用于 SSL/TCP 流连接管理
 */
final class StreamConnection
{
    private $stream;
    private ?Worker $worker;
    private int $fd;
    private string $remoteIp = '';
    private int $remotePort = 0;
    private array $data = [];

    public function __construct($stream, ?Worker $worker = null)
    {
        $this->stream = $stream;
        $this->worker = $worker;
        $this->fd = (int)$stream;

        $peerName = stream_socket_get_name($stream, true);
        if ($peerName && strpos($peerName, ':') !== false) {
            $parts = explode(':', $peerName);
            $this->remotePort = (int)array_pop($parts);
            $this->remoteIp = implode(':', $parts);
        }
    }

    public function getFd(): int
    {
        return $this->fd;
    }

    public function send(mixed $data): bool
    {
        if ($this->worker !== null) {
            if (is_array($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            $this->worker->sendToConnection($this->fd, (string)$data);
            return true;
        }

        if (!is_resource($this->stream)) {
            return false;
        }

        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return fwrite($this->stream, (string)$data) !== false;
    }

    public function recv(int $length = 8192): string|false
    {
        if (!is_resource($this->stream)) {
            return false;
        }

        return fread($this->stream, $length);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }

    public function getRemotePort(): int
    {
        return $this->remotePort;
    }

    public function getRemoteAddress(): string
    {
        return "{$this->remoteIp}:{$this->remotePort}";
    }

    public function getWorker(): ?Worker
    {
        return $this->worker;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function isConnected(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }
}
