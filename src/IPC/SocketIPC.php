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

    private array $pendingMessages = [];

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

        return [
            new self(),
            new self(),
        ];
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

        if (!is_resource($socket) && !($socket instanceof \Socket)) {
            throw IPCException::connectionFailed(IPCInterface::TYPE_SOCKET, '套接字未初始化');
        }

        if ($timeout !== null) {
            $read = [$socket];
            $write = $except = [];

            $result = @socket_select($read, $write, $except, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));

            if ($result === false) {
                throw IPCException::receiveFailed(socket_strerror(socket_last_error()));
            }

            if ($result === 0) {
                throw IPCException::timeout($timeout);
            }
        }

        $header = @socket_read($socket, 4, PHP_BINARY_READ);

        if ($header === false || strlen($header) < 4) {
            return null;
        }

        $length = unpack('N', $header)[1];

        if ($length > $this->bufferSize) {
            throw IPCException::bufferOverflow($length, $this->bufferSize);
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @socket_read($socket, min($remaining, 8192), PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                throw IPCException::receiveFailed('连接已关闭');
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $message = $this->unserialize($data);

        $this->logger->debug('IPC 消息已接收', ['size' => $length]);

        return $message;
    }

    public function broadcast(mixed $message): bool
    {
        $serialized = $this->serialize($message);

        foreach ($this->pendingMessages as $pid => &$queue) {
            $queue[] = $serialized;
        }

        $this->logger->debug('IPC 消息已广播', ['count' => count($this->pendingMessages)]);

        return true;
    }

    public function sendTo(int $targetPid, mixed $message): bool
    {
        $this->targetPid = $targetPid;

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
        $this->pendingMessages = [];
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
