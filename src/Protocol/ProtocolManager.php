<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

use Kode\Process\Response;

final class ProtocolManager
{
    private static ?self $instance = null;

    private array $listeners = [];
    private array $connections = [];
    private array $options = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function listen(string $protocol, string $address, int $port, array $options = []): Response
    {
        $protocol = strtolower($protocol);

        if (!ProtocolFactory::has($protocol)) {
            return Response::invalid("未知的协议: {$protocol}");
        }

        $key = "{$protocol}://{$address}:{$port}";

        if (isset($this->listeners[$key])) {
            return Response::duplicate("监听器已存在: {$key}");
        }

        $this->listeners[$key] = [
            'protocol' => $protocol,
            'address' => $address,
            'port' => $port,
            'options' => $options,
            'status' => 'pending',
            'connections' => 0,
            'created_at' => microtime(true),
        ];

        return Response::ok([
            'listener' => $key,
            'protocol' => $protocol,
            'address' => $address,
            'port' => $port,
        ], '监听器已创建');
    }

    public function listenHttp(string $address = '0.0.0.0', int $port = 8080): Response
    {
        return $this->listen(ProtocolFactory::HTTP, $address, $port);
    }

    public function listenWebSocket(string $address = '0.0.0.0', int $port = 8081): Response
    {
        return $this->listen(ProtocolFactory::WEBSOCKET, $address, $port);
    }

    public function listenTcp(string $address = '0.0.0.0', int $port = 9000): Response
    {
        return $this->listen(ProtocolFactory::TCP, $address, $port);
    }

    public function listenText(string $address = '0.0.0.0', int $port = 9001): Response
    {
        return $this->listen(ProtocolFactory::TEXT, $address, $port);
    }

    public function close(string $listenerKey): Response
    {
        if (!isset($this->listeners[$listenerKey])) {
            return Response::notFound("监听器不存在: {$listenerKey}");
        }

        unset($this->listeners[$listenerKey]);

        return Response::ok(['listener' => $listenerKey], '监听器已关闭');
    }

    public function getListeners(): array
    {
        return $this->listeners;
    }

    public function getListener(string $key): ?array
    {
        return $this->listeners[$key] ?? null;
    }

    public function getListenersByProtocol(string $protocol): array
    {
        $protocol = strtolower($protocol);

        return array_filter($this->listeners, fn($l) => $l['protocol'] === $protocol);
    }

    public function addConnection(string $listenerKey, int $connectionId, array $meta = []): Response
    {
        if (!isset($this->listeners[$listenerKey])) {
            return Response::notFound("监听器不存在: {$listenerKey}");
        }

        $this->connections[$connectionId] = [
            'listener' => $listenerKey,
            'meta' => $meta,
            'created_at' => microtime(true),
            'bytes_read' => 0,
            'bytes_written' => 0,
        ];

        $this->listeners[$listenerKey]['connections']++;

        return Response::ok(['connection_id' => $connectionId], '连接已添加');
    }

    public function removeConnection(int $connectionId): Response
    {
        if (!isset($this->connections[$connectionId])) {
            return Response::notFound("连接不存在: {$connectionId}");
        }

        $listenerKey = $this->connections[$connectionId]['listener'];
        unset($this->connections[$connectionId]);

        if (isset($this->listeners[$listenerKey])) {
            $this->listeners[$listenerKey]['connections']--;
        }

        return Response::ok(['connection_id' => $connectionId], '连接已移除');
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    public function getListenerCount(): int
    {
        return count($this->listeners);
    }

    public function getStatus(): array
    {
        return [
            'listeners' => count($this->listeners),
            'connections' => count($this->connections),
            'protocols' => ProtocolFactory::available(),
            'by_protocol' => $this->getListenerStats(),
        ];
    }

    private function getListenerStats(): array
    {
        $stats = [];

        foreach ($this->listeners as $listener) {
            $protocol = $listener['protocol'];

            if (!isset($stats[$protocol])) {
                $stats[$protocol] = [
                    'listeners' => 0,
                    'connections' => 0,
                ];
            }

            $stats[$protocol]['listeners']++;
            $stats[$protocol]['connections'] += $listener['connections'];
        }

        return $stats;
    }

    public function setOption(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function encode(string $protocol, mixed $data): string
    {
        return ProtocolFactory::create($protocol)->encode($data);
    }

    public function decode(string $protocol, string $data): mixed
    {
        return ProtocolFactory::create($protocol)->decode($data);
    }

    public function reset(): void
    {
        $this->listeners = [];
        $this->connections = [];
        $this->options = [];
    }

    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->reset();
        }

        self::$instance = null;
    }
}
