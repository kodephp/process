<?php

declare(strict_types=1);

namespace Kode\Process\Channel;

final class Server
{
    private string $host;
    private int $port;
    private $socket = null;
    private bool $running = false;
    private array $clients = [];
    private array $events = [];
    private array $subscriberMap = [];
    private int $clientCount = 0;

    public function __construct(string $host = '0.0.0.0', int $port = 2206)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($this->socket === false) {
            throw new \RuntimeException('创建 Socket 失败: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (!socket_bind($this->socket, $this->host, $this->port)) {
            throw new \RuntimeException('绑定端口失败: ' . socket_strerror(socket_last_error($this->socket)));
        }

        if (!socket_listen($this->socket, 128)) {
            throw new \RuntimeException('监听失败: ' . socket_strerror(socket_last_error($this->socket)));
        }

        socket_set_nonblock($this->socket);
        $this->running = true;

        echo "[Channel Server] 启动成功 {$this->host}:{$this->port}\n";

        while ($this->running) {
            $this->acceptConnections();
            $this->handleClients();
            usleep(1000);
        }
    }

    private function acceptConnections(): void
    {
        $client = @socket_accept($this->socket);
        
        if ($client !== false) {
            socket_set_nonblock($client);
            $clientId = ++$this->clientCount;
            $this->clients[$clientId] = [
                'socket' => $client,
                'id' => $clientId,
                'buffer' => '',
                'subscriptions' => []
            ];
        }
    }

    private function handleClients(): void
    {
        foreach ($this->clients as $clientId => $client) {
            $buffer = '';
            $bytes = @socket_recv($client['socket'], $buffer, 65535, MSG_DONTWAIT);
            
            if ($bytes === false || $bytes === 0) {
                $this->closeClient($clientId);
                continue;
            }

            if (!empty($buffer)) {
                $this->clients[$clientId]['buffer'] .= $buffer;
                $this->processBuffer($clientId);
            }
        }
    }

    private function processBuffer(int $clientId): void
    {
        $buffer = &$this->clients[$clientId]['buffer'];

        while (($pos = strpos($buffer, "\n")) !== false) {
            $message = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $data = json_decode($message, true);
            
            if ($data === null) {
                continue;
            }

            $this->handleMessage($clientId, $data);
        }
    }

    private function handleMessage(int $clientId, array $data): void
    {
        $type = $data['type'] ?? '';
        $event = $data['event'] ?? '';
        $eventData = $data['data'] ?? null;

        switch ($type) {
            case 'subscribe':
                $this->subscribe($clientId, $event);
                break;

            case 'unsubscribe':
                $this->unsubscribe($clientId, $event);
                break;

            case 'publish':
                $this->publish($event, $eventData);
                break;

            case 'ping':
                $this->sendToClient($clientId, ['type' => 'pong']);
                break;
        }
    }

    private function subscribe(int $clientId, string $event): void
    {
        if (!isset($this->subscriberMap[$event])) {
            $this->subscriberMap[$event] = [];
        }

        $this->subscriberMap[$event][$clientId] = true;
        $this->clients[$clientId]['subscriptions'][$event] = true;

        $this->sendToClient($clientId, [
            'type' => 'subscribed',
            'event' => $event
        ]);
    }

    private function unsubscribe(int $clientId, string $event): void
    {
        unset($this->subscriberMap[$event][$clientId]);
        unset($this->clients[$clientId]['subscriptions'][$event]);

        if (empty($this->subscriberMap[$event])) {
            unset($this->subscriberMap[$event]);
        }

        $this->sendToClient($clientId, [
            'type' => 'unsubscribed',
            'event' => $event
        ]);
    }

    private function publish(string $event, mixed $data): void
    {
        if (!isset($this->subscriberMap[$event])) {
            return;
        }

        $message = json_encode([
            'type' => 'event',
            'event' => $event,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE) . "\n";

        foreach ($this->subscriberMap[$event] as $clientId => $true) {
            if (isset($this->clients[$clientId])) {
                @socket_write($this->clients[$clientId]['socket'], $message);
            }
        }
    }

    private function sendToClient(int $clientId, array $data): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        @socket_write($this->clients[$clientId]['socket'], $message);
    }

    private function closeClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        foreach ($this->clients[$clientId]['subscriptions'] as $event => $true) {
            unset($this->subscriberMap[$event][$clientId]);
            
            if (empty($this->subscriberMap[$event])) {
                unset($this->subscriberMap[$event]);
            }
        }

        @socket_close($this->clients[$clientId]['socket']);
        unset($this->clients[$clientId]);
    }

    public function stop(): void
    {
        $this->running = false;

        foreach ($this->clients as $clientId => $client) {
            $this->closeClient($clientId);
        }

        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    public function getStats(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'running' => $this->running,
            'clients' => count($this->clients),
            'events' => count($this->subscriberMap),
            'total_subscriptions' => array_sum(array_map('count', $this->subscriberMap))
        ];
    }
}
