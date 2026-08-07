<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Protocol\ProtocolInterface;
use Kode\Process\Runtime\ConnectionInterface;

/**
 * 自研（Native）运行时连接适配。
 *
 * 基于纯 PHP 流套接字（stream_socket），不依赖任何扩展。
 * 编码/解码复用本包的 Protocol 协议系统，因此 send() 入参语义与
 * Swoole / Workerman 运行时保持一致（HTTP 传响应数组、WebSocket 传字符串等）。
 *
 * @internal 由 {@see NativeRuntime} 创建
 */
final class NativeConnection implements ConnectionInterface
{
    private static int $seq = 0;

    /** @var array<string, mixed> */
    private array $context = [];

    private string $buffer = '';

    private bool $handshakeDone = false;

    private bool $closed = false;

    private int $connId;

    public function __construct(
        private readonly mixed $socket,
        private readonly string $peerName = '',
        private readonly ?string $protocolClass = null,
    ) {
        $this->connId = ++self::$seq;
    }

    public function id(): int
    {
        return $this->connId;
    }

    public function send(string $data, bool $raw = false): bool
    {
        if ($this->closed || !is_resource($this->socket)) {
            return false;
        }

        $bytes = $raw || $this->protocolClass === null
            ? $data
            : ($this->protocolClass)::encode($data, $this);

        $written = @fwrite($this->socket, $bytes);

        return $written !== false && $written > 0;
    }

    /** 写裸字节（用于 WebSocket 握手响应等不经过协议编码的场景） */
    public function sendRaw(string $data): bool
    {
        if ($this->closed || !is_resource($this->socket)) {
            return false;
        }
        $written = @fwrite($this->socket, $data);
        return $written !== false && $written > 0;
    }

    public function close(?string $data = null): void
    {
        if ($this->closed) {
            return;
        }
        if ($data !== null && $data !== '') {
            $this->send($data);
        }
        $this->closed = true;
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function remoteAddress(): string
    {
        if ($this->peerName !== '') {
            return $this->peerName;
        }
        if (is_resource($this->socket)) {
            $name = @stream_socket_get_name($this->socket, true);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return '';
    }

    public function localAddress(): string
    {
        if (is_resource($this->socket)) {
            $name = @stream_socket_get_name($this->socket, false);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return '';
    }

    public function isAlive(): bool
    {
        return !$this->closed && is_resource($this->socket) && !@feof($this->socket);
    }

    public function native(): mixed
    {
        return $this->socket;
    }

    public function protocolClass(): ?string
    {
        return $this->protocolClass;
    }

    // ---- 接收缓冲（由 NativeRuntime 驱动） ----

    public function appendBuffer(string $data): void
    {
        $this->buffer .= $data;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function setBuffer(string $buffer): void
    {
        $this->buffer = $buffer;
    }

    public function clearBuffer(): void
    {
        $this->buffer = '';
    }

    public function hasFullHttpRequest(): bool
    {
        return strpos($this->buffer, "\r\n\r\n") !== false;
    }

    public function isHandshakeDone(): bool
    {
        return $this->handshakeDone;
    }

    public function setHandshakeDone(): void
    {
        $this->handshakeDone = true;
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
