<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Runtime\ConnectionInterface;

/**
 * Swoole 连接适配。
 *
 * Swoole 用整型 fd 标识连接，且 HTTP 场景下响应要通过独立的 Response 对象写出，
 * 因此本类支持两种写出模式：
 *  - 流模式：$server->send($fd, $data)
 *  - HTTP 模式：$response->end($data)（仅能写一次）
 *
 * @internal 由 {@see SwooleRuntime} 创建
 */
final class SwooleConnection implements ConnectionInterface
{
    /** @var array<string, mixed> */
    private array $context = [];

    private bool $responded = false;

    /**
     * @param object $server   Swoole\Server 实例
     * @param int    $fd       连接标识
     * @param object|null $response Swoole\Http\Response（HTTP 模式）
     */
    public function __construct(
        private readonly object $server,
        private readonly int $fd,
        private readonly ?object $response = null,
    ) {
    }

    public function id(): int
    {
        return $this->fd;
    }

    public function send(string $data, bool $raw = false): bool
    {
        if ($this->response !== null) {
            if ($this->responded) {
                return false; // HTTP 响应只能写一次
            }
            $this->responded = true;
            $this->response->end($data);
            return true;
        }

        // WebSocket 帧
        if (!$raw && method_exists($this->server, 'isEstablished') && $this->server->isEstablished($this->fd)) {
            return (bool)$this->server->push($this->fd, $data);
        }

        return (bool)$this->server->send($this->fd, $data);
    }

    public function close(?string $data = null): void
    {
        if ($data !== null && $data !== '') {
            $this->send($data);
        }
        if ($this->response !== null) {
            if (!$this->responded) {
                $this->responded = true;
                $this->response->end();
            }
            return;
        }
        $this->server->close($this->fd);
    }

    public function remoteAddress(): string
    {
        $info = $this->info();
        if ($info === []) {
            return '';
        }
        return sprintf('%s:%d', $info['remote_ip'] ?? '', $info['remote_port'] ?? 0);
    }

    public function localAddress(): string
    {
        $info = $this->info();
        if ($info === []) {
            return '';
        }
        return sprintf('%s:%d', $info['server_ip'] ?? '', $info['server_port'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function info(): array
    {
        if (!method_exists($this->server, 'getClientInfo')) {
            return [];
        }
        $info = $this->server->getClientInfo($this->fd);
        return is_array($info) ? $info : [];
    }

    public function isAlive(): bool
    {
        if ($this->response !== null) {
            return !$this->responded;
        }
        return method_exists($this->server, 'exists') && (bool)$this->server->exists($this->fd);
    }

    public function native(): mixed
    {
        return $this->response ?? $this->fd;
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
