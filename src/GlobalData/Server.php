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
 *
 * 安全提示：本服务持有集群锁 / 选举状态，端口不应暴露到公网。请绑内网地址，
 * 或用构造参数 `$token` 开启简单校验（未设置时不做校验，保持向后兼容）。
 */
final class Server
{
    /**
     * 单帧包体长度上限（8 MiB）。
     *
     * 长度前缀是攻击者可控的 32 位无符号数，不设上限时一个 0xFFFFFFFF
     * 就能让服务端一直累积缓冲区直到 OOM。
     */
    public const int MAX_FRAME_SIZE = 8 * 1024 * 1024;

    private string $host;
    private int $port;
    private ?string $token;
    private array $data = [];
    private $socket = null;
    private bool $running = false;
    /** @var array<int, array{socket: mixed, buffer: string, out: string}> */
    private array $clients = [];
    private int $clientSeq = 0;

    /**
     * @param string|null $token 非空时，请求需带上 `_token` 字段且匹配；为 null 时不鉴权
     */
    public function __construct(string $host = '127.0.0.1', int $port = 2207, ?string $token = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->token = $token;
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
            $write = [];
            $except = [];

            // 有未写完的响应就关注可写事件，等内核腾出发送缓冲区再续写
            foreach ($this->clients as $client) {
                if ($client['out'] !== '') {
                    $write[] = $client['socket'];
                }
            }

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
                if (in_array($client['socket'], $write, true)) {
                    $this->flushClient($id);
                }
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
            $this->clients[$id] = ['socket' => $client, 'buffer' => '', 'out' => ''];
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
        $buffer = &$this->clients[$id]['buffer'];

        while (strlen($buffer) >= 4) {
            $len = unpack('N', substr($buffer, 0, 4))[1];

            // 长度前缀完全由对端控制：超过上限说明对端要么在攻击、要么协议已错位，
            // 继续等待只会让 buffer 无限增长，直接断连。
            if ($len > self::MAX_FRAME_SIZE) {
                $this->closeClient($id);
                return;
            }

            if (strlen($buffer) - 4 < $len) {
                break; // 帧未收齐，等待下一次可读
            }

            $body = substr($buffer, 4, $len);
            $buffer = substr($buffer, 4 + $len);

            $request = json_decode($body, true);

            if (!is_array($request)) {
                continue;
            }

            $this->enqueue($id, $this->handleRequest($request));
        }
    }

    /**
     * 把响应编帧后放入发送队列，并尽可能立即写出。
     */
    private function enqueue(int $id, array $response): void
    {
        $payload = json_encode($response);
        $this->clients[$id]['out'] .= pack('N', strlen($payload)) . $payload;
        $this->flushClient($id);
    }

    /**
     * 尽可能写出待发数据；写不完的留在队列里，等下一次可写事件继续。
     *
     * 旧实现用单次 `socket_send(..., MSG_DONTWAIT)` 并忽略返回值，
     * 内核发送缓冲区满时只写出半个帧，客户端按长度前缀读取就会永久错位。
     */
    private function flushClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $socket = $this->clients[$id]['socket'];

        while ($this->clients[$id]['out'] !== '') {
            $pending = $this->clients[$id]['out'];
            $sent = @socket_send($socket, $pending, strlen($pending), MSG_DONTWAIT);

            if ($sent === false) {
                $error = socket_last_error($socket);
                socket_clear_error($socket);
                // 发送缓冲区暂时写满：保留剩余数据，等可写事件再续写
                if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK || $error === SOCKET_EINTR) {
                    return;
                }
                $this->closeClient($id);
                return;
            }

            if ($sent === 0) {
                return;
            }

            $this->clients[$id]['out'] = substr($pending, $sent);
        }
    }

    private function handleRequest(array $request): array
    {
        if ($this->token !== null
            && !hash_equals($this->token, (string) ($request['_token'] ?? ''))
        ) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

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
            // 集群协调原语（单线程串行处理，天然原子）
            'add' => $this->handleAdd($request),
            'casdel' => $this->handleCasDelete($request),
            'expire' => $this->handleExpire($request),
            'mget' => $this->handleMget($request),
            default => ['success' => false, 'error' => 'Unknown action']
        };
    }

    /**
     * 键是否已过期；过期则顺手清理。
     */
    private function isExpired(string $key): bool
    {
        if (!isset($this->data[$key])) {
            return true;
        }

        $expire = $this->data[$key]['expire'] ?? 0;
        if ($expire > 0 && $expire < time()) {
            unset($this->data[$key]);
            return true;
        }

        return false;
    }

    /**
     * 仅当键不存在（或已过期）时写入——分布式锁与选举的原子基石。
     */
    private function handleAdd(array $request): array
    {
        $key = $request['key'] ?? '';
        if (!$this->isExpired($key)) {
            return ['success' => false, 'error' => 'Key exists'];
        }

        $ttl = (int) ($request['ttl'] ?? 0);
        $this->data[$key] = [
            'value'  => $request['value'] ?? null,
            'expire' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return ['success' => true];
    }

    /**
     * 仅当当前值匹配时删除——用于安全释放锁，避免误删他人重新获得的锁。
     */
    private function handleCasDelete(array $request): array
    {
        $key = $request['key'] ?? '';
        if ($this->isExpired($key)) {
            return ['success' => false, 'error' => 'Key not found'];
        }
        if ($this->data[$key]['value'] !== ($request['old_value'] ?? null)) {
            return ['success' => false, 'error' => 'Value mismatch'];
        }

        unset($this->data[$key]);

        return ['success' => true];
    }

    /**
     * 重设存活时间（锁续期 / 心跳保活）。
     */
    private function handleExpire(array $request): array
    {
        $key = $request['key'] ?? '';
        if ($this->isExpired($key)) {
            return ['success' => false, 'error' => 'Key not found'];
        }

        $ttl                        = (int) ($request['ttl'] ?? 0);
        $this->data[$key]['expire'] = $ttl > 0 ? time() + $ttl : 0;

        return ['success' => true];
    }

    /**
     * 批量读取——服务发现列举节点时把 N 次往返压成 1 次。
     */
    private function handleMget(array $request): array
    {
        $values = [];
        foreach ((array) ($request['keys'] ?? []) as $key) {
            $key = (string) $key;
            if (!$this->isExpired($key)) {
                $values[$key] = $this->data[$key]['value'];
            }
        }

        return ['success' => true, 'values' => $values];
    }

    private function handleGet(array $request): array
    {
        $key = $request['key'] ?? '';
        if ($this->isExpired($key)) {
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
        return ['success' => true, 'exists' => !$this->isExpired($key)];
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
        $ttl  = (int) ($request['ttl'] ?? 0);

        // 键不存在或已过期：以 step 为初值重建，并应用首次创建的 TTL（限流窗口靠它）
        if ($this->isExpired($key)) {
            $this->data[$key] = ['value' => $step, 'expire' => $ttl > 0 ? time() + $ttl : 0];
            return ['success' => true, 'value' => $step];
        }

        $item = $this->data[$key];
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
        if ($this->isExpired($key)) {
            return ['success' => false, 'error' => 'Key not found'];
        }
        if ($this->data[$key]['value'] !== ($request['old_value'] ?? null)) {
            return ['success' => false, 'error' => 'Value mismatch', 'current' => $this->data[$key]['value']];
        }
        $this->data[$key]['value'] = $request['new_value'] ?? null;

        // 允许 CAS 的同时刷新 TTL（锁续期场景）
        if (array_key_exists('ttl', $request)) {
            $ttl                        = (int) $request['ttl'];
            $this->data[$key]['expire'] = $ttl > 0 ? time() + $ttl : 0;
        }

        return ['success' => true];
    }

    private function handleKeys(array $request): array
    {
        $pattern = $request['pattern'] ?? '*';
        $keys = [];
        foreach (array_keys($this->data) as $key) {
            if ($pattern !== '*' && !fnmatch($pattern, $key)) {
                continue;
            }
            if ($this->isExpired($key)) {
                continue;
            }
            $keys[] = $key;
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
