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
