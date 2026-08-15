<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Protocol\Http2\Http2Session;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP/2 单条流的连接视图。
 *
 * HTTP/2 一条 TCP 连接上并行跑多条流，而业务的 `on('message')` 契约是「一个连接
 * 对象 + 一个请求」。本类把**流**包装成 {@see ConnectionInterface}，于是同一份
 * handler 在 HTTP/1.1 与 HTTP/2 下写法完全一致：
 *
 * ```php
 * $server->on('message', function ($conn, $req) {
 *     $conn->send('hello');          // 1.1 → 报文；2 → HEADERS + DATA
 * });
 * ```
 *
 * `send()` 接受两种形态，与 HTTP/1.1 保持一致：
 *  - 以 `HTTP/` 开头的完整响应报文 → 解析后映射为 HEADERS + DATA
 *  - 其它字符串 → 视为响应体，自动补 200 与默认 Content-Type
 *
 * @internal 由 {@see NativeRuntime} 在收到完整请求时创建，响应结束即废弃
 */
final class Http2Stream implements ConnectionInterface
{
    private static int $seq = 0;

    private readonly int $connId;

    /** @var array<string, mixed> */
    private array $context = [];

    private bool $responded = false;

    private bool $headersSent = false;

    private bool $gzipAuto = false;

    private bool $closed = false;

    public function __construct(
        private readonly NativeConnection $parent,
        private readonly Http2Session $session,
        private readonly int $streamId,
    ) {
        $this->connId = ++self::$seq;
    }

    public function id(): int
    {
        return $this->connId;
    }

    /** 所属 HTTP/2 流 ID（奇数，客户端发起） */
    public function streamId(): int
    {
        return $this->streamId;
    }

    /** 承载本流的 TCP 连接 */
    public function connection(): NativeConnection
    {
        return $this->parent;
    }

    public function send(string $data, bool $raw = false): bool
    {
        if ($this->closed || $this->responded) {
            return false;
        }

        [$status, $headers, $body] = $this->normalize($data, $raw);

        if ($this->gzipAuto && strlen($body) >= HttpProtocol::GZIP_MIN_SIZE && !isset($headers['content-encoding'])) {
            $encoded = @gzencode($body);
            if ($encoded !== false && $encoded !== '') {
                $body                        = $encoded;
                $headers['content-encoding'] = 'gzip';
            }
        }

        // HTTP/2 用 DATA 帧边界表达长度，Content-Length 非必需但保留有助于客户端预分配
        if (!isset($headers['content-length'])) {
            $headers['content-length'] = (string) strlen($body);
        }

        $this->responded   = true;
        $this->headersSent = true;
        $this->session->respond($this->streamId, $status, $headers, $body);

        return $this->parent->flushHttp2();
    }

    /**
     * 把业务传入的字符串规整为 [状态码, 小写头表, 响应体]。
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function normalize(string $data, bool $raw): array
    {
        if (!$raw && str_starts_with($data, 'HTTP/')) {
            $parsed = HttpProtocol::parseResponse($data);

            return [$parsed['status'], $parsed['headers'], $parsed['body']];
        }

        return [200, ['content-type' => 'text/html; charset=utf-8'], $data];
    }

    /**
     * 头列表里没有 content-type 时补一个默认值。
     *
     * 之所以在「列表对」而不是关联数组上做，是为了不丢失同名多值头
     * （多个 Set-Cookie 是常见需求，关联数组会把它们塌缩成一条）。
     *
     * @param  list<array{0: string, 1: string}> $pairs
     * @return list<array{0: string, 1: string}>
     */
    private static function withDefaultContentType(array $pairs): array
    {
        foreach ($pairs as [$name]) {
            if ($name === 'content-type') {
                return $pairs;
            }
        }

        $pairs[] = ['content-type', 'text/html; charset=utf-8'];

        return $pairs;
    }

    // ------------------------------------------------------------ 流式响应

    public function chunk(string $data): bool
    {
        if ($this->closed) {
            return false;
        }
        if (!$this->headersSent) {
            $this->beginChunked();
        }
        $this->session->writeData($this->streamId, $data);

        return $this->parent->flushHttp2();
    }

    /**
     * HTTP/2 没有 chunked 传输编码——分块语义天然由 DATA 帧承载，
     * 因此这里只发响应头，后续 {@see chunk()} 直接追加 DATA。
     */
    public function beginChunked(int $status = 200, array $headers = []): bool
    {
        if ($this->closed || $this->headersSent) {
            return false;
        }

        // 用列表对形式传递，保留同名多值（如多个 Set-Cookie）
        $normalized = self::withDefaultContentType(Http2Session::normalizeHeaders($headers));

        $this->headersSent = true;
        $this->session->respondHeaders($this->streamId, $status, $normalized);

        return $this->parent->flushHttp2();
    }

    public function endChunk(): bool
    {
        if ($this->closed || !$this->headersSent || $this->responded) {
            return !$this->closed;
        }
        $this->responded = true;
        $this->session->writeData($this->streamId, '', true);

        return $this->parent->flushHttp2();
    }

    public function isChunkStarted(): bool
    {
        return $this->headersSent && !$this->responded;
    }

    public function gzip(string $data, int $status = 200, array $headers = []): bool
    {
        if ($this->closed || $this->responded) {
            return false;
        }

        $normalized = self::withDefaultContentType(Http2Session::normalizeHeaders($headers));

        $encoded = @gzencode($data);
        if ($encoded !== false && $encoded !== '') {
            $data         = $encoded;
            $normalized[] = ['content-encoding', 'gzip'];
        }
        $normalized[] = ['content-length', (string) strlen($data)];

        $this->responded   = true;
        $this->headersSent = true;
        $this->session->respond($this->streamId, $status, $normalized, $data);

        return $this->parent->flushHttp2();
    }

    public function setGzipAuto(bool $enabled): void
    {
        $this->gzipAuto = $enabled;
    }

    public function isGzipAuto(): bool
    {
        return $this->gzipAuto;
    }

    public function sendResponse(ResponseInterface $response, bool $autoGzip = true): bool
    {
        if ($this->closed || $this->responded) {
            return false;
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        // PSR-7 getHeaders() 返回 name => list<string>，normalizeHeaders 保留同名多值（如多个 Set-Cookie）
        $pairs = Http2Session::normalizeHeaders($response->getHeaders());

        $doGzip = $autoGzip
            && $this->gzipAuto
            && !$response->hasHeader('Content-Encoding')
            && strlen($body) >= HttpProtocol::GZIP_MIN_SIZE;

        if ($doGzip) {
            $encoded = @gzencode($body, -1);
            if ($encoded !== false && $encoded !== '') {
                $body    = $encoded;
                $pairs[] = ['content-encoding', 'gzip'];
            }
        }

        $pairs[] = ['content-length', (string) strlen($body)];

        $this->responded   = true;
        $this->headersSent = true;
        $this->session->respond($this->streamId, $status, $pairs, $body);

        return $this->parent->flushHttp2();
    }

    // ---------------------------------------------------------------- 生命周期

    /**
     * 结束本流。注意关的是**流**而非 TCP 连接——其它流不受影响，
     * 这正是 HTTP/2 与 HTTP/1.1 的关键差异。
     */
    public function close(?string $data = null): void
    {
        if ($this->closed) {
            return;
        }
        if ($data !== null && $data !== '') {
            $this->send($data);
        } elseif ($this->headersSent && !$this->responded) {
            $this->endChunk();
        } elseif (!$this->responded) {
            $this->session->resetStream($this->streamId);
            $this->parent->flushHttp2();
        }
        $this->closed = true;
    }

    public function remoteAddress(): string
    {
        return $this->parent->remoteAddress();
    }

    public function localAddress(): string
    {
        return $this->parent->localAddress();
    }

    public function isAlive(): bool
    {
        return !$this->closed && $this->parent->isAlive();
    }

    public function native(): mixed
    {
        return $this->parent->native();
    }

    public function setContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function getContext(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /** 是否已经写出过完整响应 */
    public function isResponded(): bool
    {
        return $this->responded;
    }
}
