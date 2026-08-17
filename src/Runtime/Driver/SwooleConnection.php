<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Http\Psr7Response;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;

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

    private bool $chunkStarted = false;

    private bool $gzipAuto = false;

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

    /**
     * 重置请求级响应状态。
     *
     * 每个 HTTP 请求由 SwooleRuntime 创建**全新** SwooleConnection，故严格说无需重置；
     * 保留此方法作为防御性边界——若未来复用连接对象，可在请求入口调用，避免跨请求残留的
     * $responded / $chunkStarted 守卫误判。（见 {@see SwooleRuntime::onRequest()}）
     */
    public function reset(): void
    {
        $this->responded    = false;
        $this->chunkStarted = false;
    }

    public function send(string $data, bool $raw = false): bool
    {
        if ($this->response !== null) {
            if ($this->responded) {
                return false; // HTTP 响应只能写一次
            }
            // 自动 gzip：请求接受压缩且响应体达阈值
            if ($this->gzipAuto && is_string($data) && strlen($data) >= HttpProtocol::GZIP_MIN_SIZE) {
                $gz = @gzencode($data, -1);
                if ($gz !== false && $gz !== '') {
                    $this->response->header('Content-Encoding', 'gzip');
                    $this->response->header('Content-Length', (string)strlen($gz));
                    $this->responded = true;
                    $this->endResponse($gz);
                    return true;
                }
            }
            $this->responded = true;
            $this->endResponse($data);
            return true;
        }

        // WebSocket 帧
        if (!$raw && method_exists($this->server, 'isEstablished') && $this->server->isEstablished($this->fd)) {
            return (bool)$this->server->push($this->fd, $data);
        }

        return (bool)$this->server->send($this->fd, $data);
    }

    public function sendResponse(ResponseInterface $response, bool $autoGzip = true): bool
    {
        if ($this->response !== null) {
            if ($this->responded) {
                return false; // HTTP 响应只能写一次
            }

            $this->response->status($response->getStatusCode());

            $gzip = $autoGzip && $this->gzipAuto && !$response->hasHeader('Content-Encoding');
            $body = (string) $response->getBody();

            foreach ($response->getHeaders() as $name => $values) {
                $lower = strtolower((string) $name);
                // gzip 下这两个头由本方法重写，原始声明跳过
                if ($gzip && ($lower === 'content-length' || $lower === 'content-encoding')) {
                    continue;
                }
                // 同名多值头（如多个 Set-Cookie）逐条写出：首个 replace=true 设值，后续 append
                foreach ($values as $i => $value) {
                    $this->response->header((string) $name, (string) $value, $i === 0);
                }
            }

            if ($gzip && strlen($body) >= HttpProtocol::GZIP_MIN_SIZE) {
                $gz = @gzencode($body, -1);
                if ($gz !== false && $gz !== '') {
                    $this->response->header('Content-Encoding', 'gzip');
                    $this->response->header('Content-Length', (string) strlen($gz));
                    $this->responded = true;
                    $this->endResponse($gz);
                    return true;
                }
            }

            // 显式补 Content-Length，便于下游（如 gzip 探测）依赖确定长度
            if (!$response->hasHeader('Content-Length') && !$response->hasHeader('content-length')) {
                $this->response->header('Content-Length', (string) strlen($body));
            }

            $this->responded = true;
            $this->endResponse($body);
            return true;
        }

        // 非 HTTP 模式（裸 TCP / WebSocket）：降级为序列化裸字节发送
        return $this->send(Psr7Response::toHttp11($response), true);
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
        if ($this->response !== null) {
            $gz = @gzencode($data, -1);
            if ($gz === false || $gz === '') {
                return false;
            }
            $this->response->status($status);
            foreach ($headers as $name => $value) {
                $this->response->header($name, $value);
            }
            $this->response->header('Content-Encoding', 'gzip');
            $this->response->header('Content-Length', (string)strlen($gz));
            $this->responded = true;
            $this->endResponse($gz);
            return true;
        }

        // 非 HTTP 模式（裸 TCP / WebSocket）：降级为普通发送
        return $this->send($data);
    }

    public function isChunkStarted(): bool
    {
        return $this->chunkStarted;
    }

    public function beginChunked(int $status = 200, array $headers = []): bool
    {
        if ($this->response !== null) {
            $this->response->status($status);
            foreach ($headers as $name => $value) {
                $this->response->header($name, $value);
            }
            $this->chunkStarted = true;
            return true;
        }

        // 非 HTTP 模式（裸 TCP / WebSocket）：降级为裸 chunked 字节
        if ($this->chunkStarted) {
            return false;
        }
        if (!$this->send(HttpProtocol::beginChunked($status, $headers), true)) {
            return false;
        }
        $this->chunkStarted = true;
        return true;
    }

    public function chunk(string $data): bool
    {
        if ($this->response !== null) {
            // Swoole HTTP 模式：response->write() 自动启用 chunked
            if (!$this->chunkStarted) {
                $this->chunkStarted = true;
            }
            $this->response->write($data);
            return true;
        }

        // 非 HTTP 模式：裸 chunked 字节
        if (!$this->chunkStarted) {
            if (!$this->send(HttpProtocol::beginChunked(), true)) {
                return false;
            }
            $this->chunkStarted = true;
        }
        $frame = HttpProtocol::chunkFrame($data);
        if ($frame === '') {
            return true;
        }
        return $this->send($frame, true);
    }

    public function endChunk(): bool
    {
        if ($this->response !== null) {
            if (!$this->chunkStarted) {
                return false;
            }
            $this->endResponse();
            $this->responded   = true;
            $this->chunkStarted = false;
            return true;
        }

        if (!$this->chunkStarted) {
            return false;
        }
        if (!$this->send(HttpProtocol::chunkEnd(), true)) {
            return false;
        }
        $this->chunkStarted = false;
        return true;
    }

    public function close(?string $data = null): void
    {
        if ($data !== null && $data !== '') {
            $this->send($data);
        }
        if ($this->response !== null) {
            if (!$this->responded) {
                $this->responded = true;
                $this->endResponse();
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

    /**
     * 安全写出 HTTP 响应并终段连接。
     *
     * Swoole 在 keep-alive / 并发场景下可能已回收底层 fd 对应的 C 层 Response 对象；
     * 此时若仍对其调用 end() 会触发 C 层崩溃（表现为 worker 静默退出、连接被拒）。
     * 故在每次 end() 前用 server->exists($fd) 做最后一道闸门：fd 已不存在即跳过写出，
     * 绝不踩踏已释放对象。无法判定（server 无 exists 方法）时退回原行为，不引入回归。
     *
     * @param string $data 响应体；空串等价于 end() 终段空响应
     */
    private function endResponse(string $data = ''): void
    {
        if (method_exists($this->server, 'exists') && !$this->server->exists($this->fd)) {
            return; // fd 已被 Swoole 回收，跳过写出，避免 C 层崩溃
        }
        $this->response->end($data);
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
