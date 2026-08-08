<?php

declare(strict_types=1);

namespace Kode\Process\Runtime\Driver;

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Http2Session;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Reactor\LoopInterface;
use Kode\Process\Runtime\ConnectionInterface;

/**
 * 自研（Native）运行时的连接对象。
 *
 * 基于纯 PHP 流套接字（stream_socket），不依赖任何扩展，但具备生产级连接语义：
 *  - **异步发送缓冲**：一次 fwrite 写不完时挂到事件循环的可写监听上续写，
 *    绝不阻塞 worker（对齐 Workerman TcpConnection 的 sendBuffer 行为）。
 *  - **背压保护**：缓冲超过 maxSendBuffer 触发 bufferFull，避免慢客户端打爆内存。
 *  - **延迟关闭**：closeAfterFlush() 把缓冲写净后再断开，适配 HTTP `Connection: close`。
 *  - **UDP 无连接**：同一抽象承载 UDP 报文，send() 自动走 sendto。
 *  - **活跃时间**：供运行时的空闲连接心跳回收使用。
 *
 * 编解码复用本包 Protocol 系统，因此 send() 的入参语义与 Swoole / Workerman
 * 运行时完全一致（HTTP 传响应数组、WebSocket / Text 传字符串等）。
 *
 * @internal 由 {@see NativeRuntime} 创建
 */
final class NativeConnection implements ConnectionInterface
{
    /** 默认发送缓冲上限（字节），超过即视为对端消费不过来 */
    public const int DEFAULT_MAX_SEND_BUFFER = 8388608;

    private static int $seq = 0;

    /** @var array<string, mixed> */
    private array $context = [];

    private string $recvBuffer = '';

    private string $sendBuffer = '';

    private bool $handshakeDone = false;

    private bool $sslReady = false;

    private bool $closed = false;

    private bool $closeAfterFlush = false;

    private bool $writeWatched = false;

    private bool $bufferOverflow = false;

    // HTTP chunked 流式状态（仅 http 连接使用）
    private bool $httpChunkStarted = false;

    private bool $httpChunkEnded = false;

    // HTTP 自动 gzip 压缩标记（运行时依据 Accept-Encoding 设置）
    private bool $gzipAuto = false;

    // WebSocket 分片重组缓冲（RFC 6455 §5.4），仅 websocket 连接使用
    private string $wsFragmentBuffer = '';

    private int $wsFragmentOpcode = 0;

    private bool $wsFragmenting = false;

    /** HTTP/2 会话状态机，非 null 表示本连接已升级到 h2c */
    private ?Http2Session $http2Session = null;

    private readonly int $connId;

    /**
     * 共享粗时钟（秒，带小数）。
     *
     * `lastActiveAt` 只服务于「空闲连接回收」，判定粒度是心跳周期（默认数十秒），
     * 却要在每次收发上写入——按每请求两次 `microtime()` 计，纯属把系统调用摊到热路径。
     * 这里改由运行时心跳统一推进：刷新时钟与检查空闲发生在同一个定时器里，
     * 二者天然同步，回收语义不受影响，热路径只剩一次静态属性读取。
     *
     * 值为 0.0 表示尚未有运行时接管（例如连接被单独构造用于测试），
     * 此时退回 `microtime()` 保证语义正确。
     */
    private static float $clock = 0.0;

    private float $lastActiveAt;

    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    /**
     * @param resource    $socket        TCP/Unix 为连接套接字；UDP 为服务端套接字
     * @param string      $peerName      对端地址
     * @param string|null $protocolClass 协议类，null 表示裸字节
     * @param string|null $udpPeer       非 null 表示 UDP 报文连接，值为对端地址
     */
    public function __construct(
        private readonly mixed $socket,
        private readonly string $peerName = '',
        private readonly ?string $protocolClass = null,
        private ?LoopInterface $loop = null,
        private readonly ?string $udpPeer = null,
        private readonly int $maxSendBuffer = self::DEFAULT_MAX_SEND_BUFFER,
    ) {
        $this->connId      = ++self::$seq;
        $this->lastActiveAt = self::$clock !== 0.0 ? self::$clock : microtime(true);
        $this->sslReady    = true;
    }

    /**
     * 由运行时心跳推进共享时钟，随后即可用同一时刻做空闲判定。
     *
     * 传 0.0 可重置为「无运行时接管」，让连接退回自行取时间。
     */
    public static function tickClock(float $now): void
    {
        self::$clock = $now;
    }

    public function id(): int
    {
        return $this->connId;
    }

    public function send(string $data, bool $raw = false): bool
    {
        if ($this->closed) {
            return false;
        }

        $bytes = $raw || $this->protocolClass === null
            ? $data
            : ($this->protocolClass)::encode($data, $this);

        // HTTP 自动 gzip：仅在运行时标记、未分块、响应体达阈值时压缩
        if (
            !$raw
            && $this->protocolClass === HttpProtocol::class
            && $this->gzipAuto
            && !$this->httpChunkStarted
            && $this->bodySize($data) >= HttpProtocol::GZIP_MIN_SIZE
        ) {
            $compressed = HttpProtocol::encodeCompressed($data);
            if ($compressed !== '') {
                $bytes = $compressed;
            }
        }

        return $this->write($bytes);
    }

    /**
     * 估算待发送响应体的字节数（字符串取长度；数组取 body 段）。
     */
    private function bodySize(mixed $data): int
    {
        if (is_string($data)) {
            return strlen($data);
        }
        if (is_array($data)) {
            return strlen((string)($data['body'] ?? ''));
        }
        return 0;
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
        if ($this->closed) {
            return false;
        }

        // 非 HTTP 连接：降级为普通发送，保持跨运行时语义一致
        if ($this->protocolClass !== HttpProtocol::class) {
            return $this->send($data);
        }

        return $this->write(
            HttpProtocol::encodeCompressed(['status' => $status, 'headers' => $headers, 'body' => $data])
        );
    }

    /** 写裸字节，跳过协议编码（WebSocket 握手响应、SSL 前置等场景） */
    public function sendRaw(string $data): bool
    {
        return $this->closed ? false : $this->write($data);
    }

    // ------------------------------------------------------- HTTP 分块流式

    public function isChunkStarted(): bool
    {
        return $this->httpChunkStarted;
    }

    public function beginChunked(int $status = 200, array $headers = []): bool
    {
        if ($this->closed || $this->httpChunkStarted) {
            return false;
        }
        if (!$this->write(HttpProtocol::beginChunked($status, $headers))) {
            return false;
        }
        $this->httpChunkStarted = true;
        return true;
    }

    public function chunk(string $data): bool
    {
        if ($this->closed) {
            return false;
        }

        // 非 HTTP 连接：降级为普通发送，保持跨运行时语义一致
        if ($this->protocolClass !== HttpProtocol::class) {
            return $this->send($data);
        }

        if (!$this->httpChunkStarted) {
            if (!$this->write(HttpProtocol::beginChunked())) {
                return false;
            }
            $this->httpChunkStarted = true;
        }

        $frame = HttpProtocol::chunkFrame($data);
        if ($frame === '') {
            return true; // 空块不发送，但流式已开启
        }

        return $this->write($frame);
    }

    public function endChunk(): bool
    {
        if (!$this->httpChunkStarted || $this->httpChunkEnded) {
            return !$this->closed;
        }
        if (!$this->write(HttpProtocol::chunkEnd())) {
            return false;
        }
        $this->httpChunkEnded   = true;
        $this->httpChunkStarted = false;
        return true;
    }

    /**
     * 写入并在必要时挂起可写监听续写。
     */
    private function write(string $bytes): bool
    {
        if ($bytes === '' || !is_resource($this->socket)) {
            return false;
        }

        $this->lastActiveAt = self::$clock ?: microtime(true);

        if ($this->udpPeer !== null) {
            $n = @stream_socket_sendto($this->socket, $bytes, 0, $this->udpPeer);
            if ($n > 0) {
                $this->bytesWritten += $n;
            }
            return $n !== false && $n > 0;
        }

        // 已有积压：直接追加，顺序由可写回调保证
        if ($this->sendBuffer !== '') {
            $this->sendBuffer .= $bytes;
            return $this->guardBuffer();
        }

        $n = @fwrite($this->socket, $bytes);
        if ($n === false) {
            $this->close();
            return false;
        }
        $this->bytesWritten += $n;

        if ($n < strlen($bytes)) {
            $this->sendBuffer = substr($bytes, $n);
            $this->watchWritable();
            return $this->guardBuffer();
        }

        return true;
    }

    /** 缓冲超限保护：超过上限直接断开，防止慢客户端拖垮进程 */
    private function guardBuffer(): bool
    {
        if (strlen($this->sendBuffer) <= $this->maxSendBuffer) {
            return true;
        }
        $this->bufferOverflow = true;
        $this->close();
        return false;
    }

    /**
     * 事件循环可写回调：把积压缓冲继续写出，写净后取消监听。
     *
     * @return bool 缓冲是否已清空
     */
    public function flush(): bool
    {
        if ($this->sendBuffer === '' || !is_resource($this->socket)) {
            $this->unwatchWritable();
            return true;
        }

        $n = @fwrite($this->socket, $this->sendBuffer);
        if ($n === false) {
            $this->close();
            return true;
        }
        $this->bytesWritten += $n;
        $this->sendBuffer   = substr($this->sendBuffer, $n);

        if ($this->sendBuffer === '') {
            $this->unwatchWritable();
            if ($this->closeAfterFlush) {
                $this->close();
            }
            return true;
        }

        return false;
    }

    private function watchWritable(): void
    {
        if ($this->writeWatched || $this->loop === null || !is_resource($this->socket)) {
            return;
        }
        $this->writeWatched = true;
        $this->loop->onWritable($this->socket, function (): void {
            $this->flush();
        });
    }

    private function unwatchWritable(): void
    {
        if (!$this->writeWatched || $this->loop === null) {
            return;
        }
        $this->writeWatched = false;
        if (is_resource($this->socket)) {
            $this->loop->offWritable($this->socket);
        }
    }

    /** 缓冲写净后再关闭（HTTP `Connection: close` 语义） */
    public function closeAfterFlush(): void
    {
        if ($this->sendBuffer === '') {
            $this->close();
            return;
        }
        $this->closeAfterFlush = true;
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
        $this->unwatchWritable();
        $this->httpChunkStarted = false;
        $this->httpChunkEnded   = false;
        $this->gzipAuto         = false;

        // UDP 复用服务端套接字，不能在单个报文结束时关闭
        if ($this->udpPeer === null && is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function remoteAddress(): string
    {
        if ($this->udpPeer !== null) {
            return $this->udpPeer;
        }
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
        if ($this->closed || !is_resource($this->socket)) {
            return false;
        }
        return $this->udpPeer !== null || !@feof($this->socket);
    }

    /**
     * 连接是否已被本端关闭——纯状态位判断，不触碰套接字。
     *
     * 与 `isAlive()` 的区别在于不做 `feof()` 探测：那是一次真实的流操作，
     * 在每请求都要问一次的热路径上代价可观（实测约 0.24µs/次），而在
     * 「handler 执行完，问一句业务是否主动关掉了连接」这种场景里，
     * 对端是否已发 FIN 并不影响后续处理——读到 EOF 时读回调自会收尾。
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function native(): mixed
    {
        return $this->socket;
    }

    public function protocolClass(): ?string
    {
        return $this->protocolClass;
    }

    public function isUdp(): bool
    {
        return $this->udpPeer !== null;
    }

    public function bindLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    // -------------------------------------------------------- HTTP/2 会话

    /**
     * 绑定 HTTP/2 会话（h2c prior-knowledge 或 Upgrade 成功后由运行时调用）。
     *
     * 绑定后本连接进入多路复用模式：字节流交给会话状态机解析，
     * 业务响应通过 {@see Http2Stream} 回写，再由 {@see flushHttp2()} 落到套接字。
     */
    public function attachHttp2(Http2Session $session): void
    {
        $this->http2Session = $session;
    }

    /** 已绑定的 HTTP/2 会话，未启用 h2 时为 null */
    public function http2Session(): ?Http2Session
    {
        return $this->http2Session;
    }

    /** 本连接是否运行在 HTTP/2 之上 */
    public function isHttp2(): bool
    {
        return $this->http2Session !== null;
    }

    /**
     * 把会话待发字节写入套接字。
     *
     * 会话只负责生成帧，IO 全部收敛到这里：一次写不完自动挂可写监听续写，
     * 因此 HEADERS/DATA 的产生与发送解耦，慢客户端不会阻塞 worker。
     */
    public function flushHttp2(): bool
    {
        if ($this->closed || $this->http2Session === null) {
            return false;
        }

        $bytes = $this->http2Session->drain();
        if ($bytes === '') {
            return true;
        }

        return $this->write($bytes);
    }

    /**
     * 优雅关闭本连接。
     *
     * HTTP/2 连接会先发 GOAWAY 再关 TCP，让对端收到「服务端即将离开」的信号，
     * 可以干净地停止发起新流、等待在途流完成，而不是被 RST 硬切断——
     * 否则正在进行的多路复用请求会全部失败，且 `bin/kode restart` 会中断在途连接。
     * 普通（HTTP/1.1）连接等价于直接 {@see close()}。
     */
    public function gracefulClose(): void
    {
        if ($this->http2Session !== null && !$this->http2Session->isClosed()) {
            $this->http2Session->goaway(Frame::ERROR_NO_ERROR);
            $this->flushHttp2();
        }
        $this->close();
    }

    // ---------------------------------------------------------- 接收缓冲

    public function appendBuffer(string $data): void
    {
        // keep-alive 下每轮请求结束都会把缓冲清空，此时直接接管字符串即可，
        // `.=` 会为「空串 + 新串」再分配一次并整体拷贝，纯属浪费。
        if ($this->recvBuffer === '') {
            $this->recvBuffer = $data;
        } else {
            $this->recvBuffer .= $data;
        }

        $this->bytesRead   += strlen($data);
        $this->lastActiveAt = self::$clock ?: microtime(true);
    }

    public function getBuffer(): string
    {
        return $this->recvBuffer;
    }

    public function setBuffer(string $buffer): void
    {
        $this->recvBuffer = $buffer;
    }

    public function clearBuffer(): void
    {
        $this->recvBuffer = '';
    }

    public function hasFullHttpRequest(): bool
    {
        return str_contains($this->recvBuffer, "\r\n\r\n");
    }

    // ---------------------------------------------------- 握手 / SSL 状态

    public function isHandshakeDone(): bool
    {
        return $this->handshakeDone;
    }

    public function setHandshakeDone(): void
    {
        $this->handshakeDone = true;
    }

    public function isSslReady(): bool
    {
        return $this->sslReady;
    }

    public function setSslPending(): void
    {
        $this->sslReady = false;
    }

    public function setSslReady(): void
    {
        $this->sslReady = true;
    }

    // ----------------------------------------------- WebSocket 分片重组

    public function isFragmenting(): bool
    {
        return $this->wsFragmenting;
    }

    public function startFragment(int $opcode, string $data): void
    {
        $this->wsFragmenting    = true;
        $this->wsFragmentOpcode = $opcode;
        $this->wsFragmentBuffer = $data;
    }

    public function appendFragment(string $data): void
    {
        $this->wsFragmentBuffer .= $data;
    }

    public function fragmentSize(): int
    {
        return strlen($this->wsFragmentBuffer);
    }

    public function fragmentOpcode(): int
    {
        return $this->wsFragmentOpcode;
    }

    /**
     * 结束重组：返回完整消息数组（type=message、opcode=原始、data=拼接、fin=1）并复位状态。
     *
     * @return array{type: string, opcode: int, data: string, fin: int}
     */
    public function finishFragment(): array
    {
        $message = [
            'type'   => 'message',
            'opcode' => $this->wsFragmentOpcode,
            'data'   => $this->wsFragmentBuffer,
            'fin'    => 1,
        ];
        $this->resetFragment();
        return $message;
    }

    public function resetFragment(): void
    {
        $this->wsFragmenting    = false;
        $this->wsFragmentOpcode = 0;
        $this->wsFragmentBuffer = '';
    }

    // ------------------------------------------------------------ 运行期

    public function lastActiveAt(): float
    {
        return $this->lastActiveAt;
    }

    public function touch(): void
    {
        $this->lastActiveAt = self::$clock ?: microtime(true);
    }

    public function pendingBytes(): int
    {
        return strlen($this->sendBuffer);
    }

    public function isBufferOverflow(): bool
    {
        return $this->bufferOverflow;
    }

    /** @return array{id:int, remote:string, read:int, written:int, pending:int, idle:float} */
    public function stats(): array
    {
        return [
            'id'      => $this->connId,
            'remote'  => $this->remoteAddress(),
            'read'    => $this->bytesRead,
            'written' => $this->bytesWritten,
            'pending' => strlen($this->sendBuffer),
            'idle'    => round(microtime(true) - $this->lastActiveAt, 3),
        ];
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
