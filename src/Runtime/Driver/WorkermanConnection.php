<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Runtime\ConnectionInterface;

/**
 * Workerman TcpConnection 的统一连接适配。
 *
 * @internal 由 {@see WorkermanRuntime} 创建
 */
final class WorkermanConnection implements ConnectionInterface
{
    /** @var array<string, mixed> */
    private array $context = [];

    /**
     * @param object $conn Workerman\Connection\TcpConnection
     */
    public function __construct(private readonly object $conn)
    {
    }

    public function id(): int
    {
        return (int)($this->conn->id ?? 0);
    }

    public function send(string $data, bool $raw = false): bool
    {
        return (bool)$this->conn->send($data, $raw);
    }

    public function close(?string $data = null): void
    {
        $data === null ? $this->conn->close() : $this->conn->close($data);
    }

    public function remoteAddress(): string
    {
        return method_exists($this->conn, 'getRemoteAddress')
            ? (string)$this->conn->getRemoteAddress()
            : '';
    }

    public function localAddress(): string
    {
        return method_exists($this->conn, 'getLocalAddress')
            ? (string)$this->conn->getLocalAddress()
            : '';
    }

    public function isAlive(): bool
    {
        // Workerman TcpConnection::STATUS_CLOSED = 8
        $status = $this->conn->getStatus(false) ?? null;
        return $status !== 8 && $status !== 'CLOSED';
    }

    public function native(): mixed
    {
        return $this->conn;
    }

    public function setContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function getContext(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}
