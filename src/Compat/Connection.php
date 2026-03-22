<?php

declare(strict_types=1);

namespace Kode\Process\Compat;

/**
 * 连接类
 * 
 * 兼容 Workerman 的 Connection 类
 */
class Connection
{
    public int $id;

    public string $protocol = '';

    public Worker $worker;

    private array $data;

    private static int $idCounter = 0;

    public function __construct(array $data, Worker $worker)
    {
        $this->id = ++self::$idCounter;
        $this->data = $data;
        $this->worker = $worker;

        $worker->connections[$this->id] = $this;
    }

    public function send(mixed $data): bool
    {
        return true;
    }

    public function sendRaw(string $data): bool
    {
        return true;
    }

    public function close(mixed $data = null): void
    {
        if ($data !== null) {
            $this->send($data);
        }

        $this->destroy();
    }

    public function destroy(): void
    {
        unset($this->worker->connections[$this->id]);
    }

    public function pauseRecv(): void
    {
    }

    public function resumeRecv(): void
    {
    }

    public function getRemoteIp(): string
    {
        return $this->data['remote_ip'] ?? '0.0.0.0';
    }

    public function getRemotePort(): int
    {
        return $this->data['remote_port'] ?? 0;
    }

    public function getLocalIp(): string
    {
        return $this->data['local_ip'] ?? '0.0.0.0';
    }

    public function getLocalPort(): int
    {
        return $this->data['local_port'] ?? 0;
    }

    public function getRemoteAddress(): string
    {
        return $this->getRemoteIp() . ':' . $this->getRemotePort();
    }

    public function getLocalAddress(): string
    {
        return $this->getLocalIp() . ':' . $this->getLocalPort();
    }

    public function isIpV4(): bool
    {
        return filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function isIpV6(): bool
    {
        return filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }
}
