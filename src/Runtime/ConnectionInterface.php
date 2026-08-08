<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

/**
 * 跨运行时统一的连接抽象。
 *
 * 两种运行时对"连接"的原生表示各不相同：
 *  - Swoole    → int $fd
 *  - Workerman → Workerman\Connection\TcpConnection 对象
 *
 * 本接口把它们收敛为同一套操作，使应用代码无需感知底层差异。
 */
interface ConnectionInterface
{
    /** 连接唯一标识（同一 worker 内唯一） */
    public function id(): int;

    /**
     * 发送数据。
     *
     * @param bool $raw true 表示跳过协议编码，直接写裸字节
     * @return bool 是否成功写入（或已进入发送缓冲）
     */
    public function send(string $data, bool $raw = false): bool;

    /**
     * 关闭连接。
     *
     * @param string|null $data 关闭前发送的最后一段数据
     */
    public function close(?string $data = null): void;

    /**
     * 流式发送一个数据块（HTTP 下为 Transfer-Encoding: chunked 分块）。
     *
     * 首个分块会自动发送响应头（默认 200 + text/html），后续分块直接追加。
     * 非 HTTP 连接（tcp / websocket / text 等）等价 {@see send()}，业务代码无需感知差异。
     * 运行时会在请求处理结束后自动补发终止块，无需手动 endChunk()。
     */
    public function chunk(string $data): bool;

    /**
     * 显式开始 chunked 响应，可自定义状态码与响应头（覆盖默认 200 / text/html）。
     *
     * 不调用本方法而直接 chunk() 时，使用默认头。已开始后重复调用无效。
     */
    public function beginChunked(int $status = 200, array $headers = []): bool;

    /**
     * 发送 chunked 终止块（0\r\n\r\n）并结束响应。
     *
     * 通常由运行时在 handler 返回后自动调用；亦可显式调用以提前结束（如 SSE 长流由业务控制）。
     */
    public function endChunk(): bool;

    /** 是否已处于 chunked 流式模式（首个分块已发出） */
    public function isChunkStarted(): bool;

    /** 对端地址，格式 ip:port */
    public function remoteAddress(): string;

    /** 本端地址，格式 ip:port */
    public function localAddress(): string;

    /** 连接是否仍然可用 */
    public function isAlive(): bool;

    /**
     * 获取底层原生连接对象，用于访问运行时特有能力。
     *
     * @return mixed Swoole 返回 int fd；Workerman 返回 TcpConnection
     */
    public function native(): mixed;

    /** 关联任意用户数据到该连接（如会话上下文） */
    public function setContext(string $key, mixed $value): void;

    public function getContext(string $key, mixed $default = null): mixed;
}
