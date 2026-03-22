<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\IPC\SocketIPC;

final class Server
{
    private string $host;
    private int $port;
    private array $data = [];
    private $socket = null;
    private bool $running = false;
    private array $clients = [];

    public function __construct(string $host = '127.0.0.1', int $port = 2207)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($this->socket === false) {
            throw new \RuntimeException('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (!socket_bind($this->socket, $this->host, $this->port)) {
            throw new \RuntimeException('Failed to bind socket: ' . socket_strerror(socket_last_error($this->socket)));
        }

        if (!socket_listen($this->socket, 128)) {
            throw new \RuntimeException('Failed to listen on socket: ' . socket_strerror(socket_last_error($this->socket)));
        }

        socket_set_nonblock($this->socket);
        $this->running = true;

        echo "GlobalData Server started at {$this->host}:{$this->port}\n";

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
            $this->clients[(int)$client] = $client;
        }
    }

    private function handleClients(): void
    {
        foreach ($this->clients as $clientId => $client) {
            $buffer = '';
            $bytes = @socket_recv($client, $buffer, 65535, MSG_DONTWAIT);
            
            if ($bytes === false || $bytes === 0) {
                $this->closeClient($clientId);
                continue;
            }

            if (!empty($buffer)) {
                $this->handleRequest($client, $buffer);
            }
        }
    }

    private function handleRequest($client, string $buffer): void
    {
        $request = json_decode($buffer, true);
        
        if ($request === null) {
            return;
        }

        $response = match ($request['action'] ?? '') {
            'get' => $this->handleGet($request),
            'set' => $this->handleSet($request),
            'isset' => $this->handleIsset($request),
            'unset' => $this->handleUnset($request),
            'increment' => $this->handleIncrement($request),
            'decrement' => $this->handleDecrement($request),
            'cas' => $this->handleCas($request),
            'keys' => $this->handleKeys($request),
            'stats' => $this->handleStats(),
            default => ['success' => false, 'error' => 'Unknown action']
        };

        $responseJson = json_encode($response);
        @socket_send($client, $responseJson, strlen($responseJson), MSG_DONTWAIT);
    }

    private function handleGet(array $request): array
    {
        $key = $request['key'] ?? '';
        
        if (!isset($this->data[$key])) {
            return ['success' => true, 'value' => null, 'exists' => false];
        }

        return ['success' => true, 'value' => $this->data[$key], 'exists' => true];
    }

    private function handleSet(array $request): array
    {
        $key = $request['key'] ?? '';
        $value = $request['value'] ?? null;
        $ttl = $request['ttl'] ?? 0;

        $this->data[$key] = [
            'value' => $value,
            'expire' => $ttl > 0 ? time() + $ttl : 0
        ];

        return ['success' => true];
    }

    private function handleIsset(array $request): array
    {
        $key = $request['key'] ?? '';
        
        if (!isset($this->data[$key])) {
            return ['success' => true, 'exists' => false];
        }

        $item = $this->data[$key];
        
        if ($item['expire'] > 0 && $item['expire'] < time()) {
            unset($this->data[$key]);
            return ['success' => true, 'exists' => false];
        }

        return ['success' => true, 'exists' => true];
    }

    private function handleUnset(array $request): array
    {
        $key = $request['key'] ?? '';
        unset($this->data[$key]);
        
        return ['success' => true];
    }

    private function handleIncrement(array $request): array
    {
        $key = $request['key'] ?? '';
        $step = $request['step'] ?? 1;

        if (!isset($this->data[$key])) {
            $this->data[$key] = ['value' => $step, 'expire' => 0];
            return ['success' => true, 'value' => $step];
        }

        $item = $this->data[$key];
        
        if ($item['expire'] > 0 && $item['expire'] < time()) {
            $this->data[$key] = ['value' => $step, 'expire' => 0];
            return ['success' => true, 'value' => $step];
        }

        if (!is_numeric($item['value'])) {
            return ['success' => false, 'error' => 'Value is not numeric'];
        }

        $newValue = $item['value'] + $step;
        $this->data[$key]['value'] = $newValue;
        
        return ['success' => true, 'value' => $newValue];
    }

    private function handleDecrement(array $request): array
    {
        $request['step'] = -($request['step'] ?? 1);
        return $this->handleIncrement($request);
    }

    private function handleCas(array $request): array
    {
        $key = $request['key'] ?? '';
        $oldValue = $request['old_value'] ?? null;
        $newValue = $request['new_value'] ?? null;

        if (!isset($this->data[$key])) {
            return ['success' => false, 'error' => 'Key not found'];
        }

        $item = $this->data[$key];
        
        if ($item['value'] !== $oldValue) {
            return ['success' => false, 'error' => 'Value mismatch', 'current' => $item['value']];
        }

        $this->data[$key]['value'] = $newValue;
        
        return ['success' => true];
    }

    private function handleKeys(array $request): array
    {
        $pattern = $request['pattern'] ?? '*';
        $keys = [];

        foreach (array_keys($this->data) as $key) {
            if ($pattern === '*' || fnmatch($pattern, $key)) {
                $keys[] = $key;
            }
        }

        return ['success' => true, 'keys' => $keys];
    }

    private function handleStats(): array
    {
        return [
            'success' => true,
            'stats' => [
                'keys' => count($this->data),
                'clients' => count($this->clients),
                'memory' => memory_get_usage(true),
                'uptime' => time()
            ]
        ];
    }

    private function closeClient(int $clientId): void
    {
        if (isset($this->clients[$clientId])) {
            @socket_close($this->clients[$clientId]);
            unset($this->clients[$clientId]);
        }
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

    public function getData(): array
    {
        return $this->data;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }
}
