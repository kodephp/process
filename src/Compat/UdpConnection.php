<?php

declare(strict_types=1);

namespace Kode\Process\Compat;

/**
 * UDP 连接类
 * 
 * 用于 UDP 协议的连接管理
 */
final class UdpConnection
{
    private $socket;
    private string $remoteIp;
    private int $remotePort;
    private ?Worker $worker;

    public function __construct($socket, string $remoteIp, int $remotePort, ?Worker $worker = null)
    {
        $this->socket = $socket;
        $this->remoteIp = $remoteIp;
        $this->remotePort = $remotePort;
        $this->worker = $worker;
    }

    /**
     * 发送数据
     */
    public function send(mixed $data): bool
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        $result = socket_sendto(
            $this->socket,
            (string)$data,
            strlen((string)$data),
            0,
            $this->remoteIp,
            $this->remotePort
        );

        return $result !== false;
    }

    /**
     * 获取远程 IP
     */
    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }

    /**
     * 获取远程端口
     */
    public function getRemotePort(): int
    {
        return $this->remotePort;
    }

    /**
     * 获取远程地址
     */
    public function getRemoteAddress(): string
    {
        return "{$this->remoteIp}:{$this->remotePort}";
    }

    /**
     * 获取 Worker 实例
     */
    public function getWorker(): ?Worker
    {
        return $this->worker;
    }

    /**
     * UDP 无连接，close 不做任何操作
     */
    public function close(): void
    {
        // UDP 是无连接的，不需要关闭
    }
}
