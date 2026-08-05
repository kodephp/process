<?php

declare(strict_types=1);

namespace Kode\Process\IPC;

use Kode\Process\Contracts\IPCInterface;
use Kode\Process\Exceptions\IPCException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Socket IPC 通信
 * 
 * 基于 Socket Pair 的进程间通信实现
 */
class SocketIPC implements IPCInterface
{
    private $masterSocket;

    private $workerSocket;

    private int $bufferSize = 65536;

    private bool $closed = false;

    private LoggerInterface $logger;

    private int $sourcePid = 0;

    private int $targetPid = 0;

    private string $buffer = '';

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->sourcePid = posix_getpid();
    }

    public static function createPair(): array
    {
        $sockets = [];

        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_SOCKET,
                socket_strerror(socket_last_error())
            );
        }

        $a = new self();
        $b = new self();
        $a->setSocket($sockets[0]);
        $b->setWorkerSocket($sockets[1]);

        return [$a, $b];
    }

    public function setSocket($socket): void
    {
        $this->masterSocket = $socket;
    }

    public function setWorkerSocket($socket): void
    {
        $this->workerSocket = $socket;
    }

    public function send(mixed $message, int $targetPid = 0): bool
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        $socket = $this->workerSocket ?? $this->masterSocket;

        if (!is_resource($socket) && !($socket instanceof \Socket)) {
            throw IPCException::connectionFailed(IPCInterface::TYPE_SOCKET, '套接字未初始化');
        }

        $serialized = $this->serialize($message);

        $length = strlen($serialized);

        if ($length > $this->bufferSize) {
            throw IPCException::bufferOverflow($length, $this->bufferSize);
        }

        $header = pack('N', $length);

        $result = @socket_write($socket, $header . $serialized);

        if ($result === false) {
            $error = socket_last_error($socket);
            throw IPCException::sendFailed($targetPid, socket_strerror($error));
        }

        $this->logger->debug('IPC 消息已发送', [
            'size' => $length,
            'target_pid' => $targetPid
        ]);

        return true;
    }

    public function receive(?float $timeout = null): mixed
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        $socket = $this->workerSocket ?? $this->masterSocket;

        if (!is_object($socket) && !is_resource($socket)) {
            throw IPCException::connectionFailed(IPCInterface::TYPE_SOCKET, '套接字未初始化');
        }

        $start = $timeout !== null ? microtime(true) : 0;

        while (true) {
            // 缓冲区中已有完整帧则直接解析，避免无谓的系统调用
            if (strlen($this->buffer) >= 4) {
                $len = unpack('N', substr($this->buffer, 0, 4))[1];
                if (strlen($this->buffer) - 4 >= $len) {
                    $body = substr($this->buffer, 4, $len);
                    $this->buffer = substr($this->buffer, 4 + $len);
                    $this->logger->debug('IPC 消息已接收', ['size' => $len]);
                    return $this->unserialize($body);
                }
            }

            if ($timeout !== null) {
                $read = [$socket];
                $write = $except = [];
                $sec = (int) $timeout;
                $usec = (int) (($timeout - $sec) * 1000000);
                $ready = @socket_select($read, $write, $except, $sec, $usec);
                if ($ready === false) {
                    throw IPCException::receiveFailed(socket_strerror(socket_last_error($socket)));
                }
                if ($ready === 0) {
                    throw IPCException::timeout($timeout);
                }
            }

            // 一次性尽量多读，减少系统调用次数（帧解析交给缓冲区）
            $chunk = @socket_read($socket, 65535, PHP_BINARY_READ);

            if ($chunk === false) {
                throw IPCException::receiveFailed(socket_strerror(socket_last_error($socket)));
            }

            if ($chunk === '') {
                return null; // 对端关闭
            }

            $this->buffer .= $chunk;
        }
    }

    public function broadcast(mixed $message): bool
    {
        return $this->send($message, 0);
    }

    public function sendTo(int $targetPid, mixed $message): bool
    {
        return $this->send($message, $targetPid);
    }

    public function receiveFrom(int $sourcePid, ?float $timeout = null): mixed
    {
        $this->sourcePid = $sourcePid;

        return $this->receive($timeout);
    }

    public function getType(): string
    {
        return IPCInterface::TYPE_SOCKET;
    }

    public function getBufferSize(): int
    {
        return $this->bufferSize;
    }

    public function setBufferSize(int $size): void
    {
        $this->bufferSize = $size;
    }

    public function flush(): void
    {
        $this->buffer = '';
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->masterSocket !== null) {
            @socket_close($this->masterSocket);
        }

        if ($this->workerSocket !== null) {
            @socket_close($this->workerSocket);
        }

        $this->closed = true;
        $this->logger->debug('IPC 通道已关闭');
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function serialize(mixed $data): string
    {
        try {
            return serialize($data);
        } catch (\Throwable $e) {
            throw IPCException::serializationFailed($data, $e->getMessage());
        }
    }

    private function unserialize(string $data): mixed
    {
        try {
            return unserialize($data);
        } catch (\Throwable $e) {
            throw IPCException::serializationFailed($data, $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
