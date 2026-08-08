<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Protocol\HttpProtocol;
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

    private bool $chunkStarted = false;

    private bool $gzipAuto = false;

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
        // 自动 gzip：仅 HTTP 连接、未分块、字符串响应体达阈值时拼完整压缩报文（raw 发送）
        if (
            !$raw
            && $this->gzipAuto
            && !$this->chunkStarted
            && is_string($data)
            && strlen($data) >= HttpProtocol::GZIP_MIN_SIZE
        ) {
            $compressed = HttpProtocol::encodeCompressed($data);
            if ($compressed !== '') {
                return (bool)$this->conn->send($compressed, true);
            }
        }
        return (bool)$this->conn->send($data, $raw);
    }

    public function isGzipAuto(): bool
    {
        return $this->gzipAuto;
    }

    public function setGzipAuto(bool $enabled): void
    {
        $this->gzipAuto = $enabled;
    }

    public function gzip(string $data, int $status = 200, array $headers = []): bool
    {
        $compressed = HttpProtocol::encodeCompressed(['status' => $status, 'headers' => $headers, 'body' => $data]);
        if ($compressed === '') {
            return false;
        }
        return (bool)$this->conn->send($compressed, true);
    }

    public function isChunkStarted(): bool
    {
        return $this->chunkStarted;
    }

    public function beginChunked(int $status = 200, array $headers = []): bool
    {
        if ($this->chunkStarted) {
            return false;
        }
        if (!$this->conn->send(HttpProtocol::beginChunked($status, $headers), true)) {
            return false;
        }
        $this->chunkStarted = true;
        return true;
    }

    public function chunk(string $data): bool
    {
        if (!$this->chunkStarted) {
            if (!$this->conn->send(HttpProtocol::beginChunked(), true)) {
                return false;
            }
            $this->chunkStarted = true;
        }
        $frame = HttpProtocol::chunkFrame($data);
        if ($frame === '') {
            return true;
        }
        return (bool)$this->conn->send($frame, true);
    }

    public function endChunk(): bool
    {
        if (!$this->chunkStarted) {
            return false;
        }
        if (!$this->conn->send(HttpProtocol::chunkEnd(), true)) {
            return false;
        }
        $this->chunkStarted = false;
        return true;
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
