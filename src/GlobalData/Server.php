<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\Exceptions\GlobalDataException;

/**
 * GlobalData 网络服务（跨主机共享数据）
 *
 * 协议：每个请求/响应以 4 字节大端长度前缀 + JSON 包体组成，
 * 解决了旧版“裸 JSON 在 TCP 分包时被截断导致静默丢弃”的健壮性缺陷。
 * 服务主循环使用 socket_select 替代固定 usleep 轮询，数据到达即时唤醒，降低延迟。
 *
 * 注：同主机高吞吐共享数据请优先使用 SharedMemoryTable（无网络往返、无 JSON 开销）。
 */
final class Server
{
    private string $host;
    private int $port;
    private array $data = [];
    private $socket = null;
    private bool $running = false;
    /** @var array<int, array{socket: mixed, buffer: string}> */
    private array $clients = [];
    private int $clientSeq = 0;

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
            $read = array_merge([$this->socket], array_column($this->clients, 'socket'));
            $write = $except = [];

            $n = @socket_select($read, $write, $except, 1);

            if ($n === false) {
                usleep(1000);
                continue;
            }

            if ($n < 1) {
                continue;
            }

            if (in_array($this->socket, $read, true)) {
                $this->acceptConnections();
            }

            foreach ($this->clients as $id => $client) {
                if (in_array($client['socket'], $read, true)) {
                    $this->readClient($id);
                }
            }
        }
    }

    private function acceptConnections(): void
    {
        $client = @socket_accept($this->socket);

        if ($client !== false) {
            socket_set_nonblock($client);
            $id = ++$this->clientSeq;
            $this->clients[$id] = ['socket' => $client, 'buffer' => ''];
        }
    }

    private function readClient(int $id): void
    {
        $client = $this->clients[$id]['socket'];
        $buffer = '';
        $bytes = @socket_recv($client, $buffer, 65535, MSG_DONTWAIT);

        if ($bytes === false || $bytes === 0) {
            $this->closeClient($id);
            return;
        }

        $this->clients[$id]['buffer'] .= $buffer;
        $this->drainFrames($id);
    }

    private function drainFrames(int $id): void
    {
        $client = $this->clients[$id]['socket'];
        $buffer = &$this->clients[$id]['buffer'];

        while (strlen($buffer) >= 4) {
            $len = unpack('N', substr($buffer, 0, 4))[1];

            if (strlen($buffer) - 4 < $len) {
                break; // 帧未收齐，等待下一次可读
            }

            $body = substr($buffer, 4, $len);
            $buffer = substr($buffer, 4 + $len);

            $request = json_decode($body, true);

            if (!is_array($request)) {
                continue;
            }

            $response = $this->handleRequest($request);
            $payload = json_encode($response);
            $frame = pack('N', strlen($payload)) . $payload;
            @socket_send($client, $frame, strlen($frame), MSG_DONTWAIT);
        }
    }

    private function handleRequest(array $request): array
    {
        return match ($request['action'] ?? '') {
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
    }

    private function handleGet(array $request): array
    {
        $key = $request['key'] ?? '';
        if (!isset($this->data[$key])) {
            return ['success' => true, 'value' => null, 'exists' => false];
        }
        return ['success' => true, 'value' => $this->data[$key]['value'], 'exists' => true];
    }

    private function handleSet(array $request): array
    {
        $key = $request['key'] ?? '';
        $this->data[$key] = [
            'value' => $request['value'] ?? null,
            'expire' => ($request['ttl'] ?? 0) > 0 ? time() + (int) $request['ttl'] : 0,
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
        unset($this->data[$request['key'] ?? '']);
        return ['success' => true];
    }

    private function handleIncrement(array $request): array
    {
        $key = $request['key'] ?? '';
        $step = (int) ($request['step'] ?? 1);

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
        $request['step'] = -((int) ($request['step'] ?? 1));
        return $this->handleIncrement($request);
    }

    private function handleCas(array $request): array
    {
        $key = $request['key'] ?? '';
        if (!isset($this->data[$key])) {
            return ['success' => false, 'error' => 'Key not found'];
        }
        if ($this->data[$key]['value'] !== ($request['old_value'] ?? null)) {
            return ['success' => false, 'error' => 'Value mismatch', 'current' => $this->data[$key]['value']];
        }
        $this->data[$key]['value'] = $request['new_value'] ?? null;
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
                'uptime' => time(),
            ],
        ];
    }

    private function closeClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        @socket_close($this->clients[$id]['socket']);
        unset($this->clients[$id]);
    }

    public function stop(): void
    {
        $this->running = false;
        foreach ($this->clients as $id => $client) {
            @socket_close($client['socket']);
        }
        $this->clients = [];
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
