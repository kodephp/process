<?php

declare(strict_types=1);

namespace Kode\Process\IPC;

use Kode\Process\Contracts\IPCInterface;
use Kode\Process\Exceptions\IPCException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 消息队列 IPC 通信
 * 
 * 基于 System V 消息队列的进程间通信实现
 */
class MessageQueue implements IPCInterface
{
    private ?int $queueId = null;

    private int $key;

    private int $bufferSize = 65536;

    private bool $closed = false;

    private LoggerInterface $logger;

    private int $defaultType = 1;

    public function __construct(?int $key = null, ?LoggerInterface $logger = null)
    {
        if (!extension_loaded('sysvmsg')) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_MESSAGE_QUEUE,
                'sysvmsg 扩展未加载'
            );
        }

        $this->logger = $logger ?? new NullLogger();
        $this->key = $key ?? ftok(__FILE__, 'm');

        $this->initialize();
    }

    private function initialize(): void
    {
        $this->queueId = msg_get_queue($this->key, 0644);

        if ($this->queueId === false) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_MESSAGE_QUEUE,
                '无法创建消息队列'
            );
        }

        $this->logger->debug('消息队列 IPC 已初始化', ['key' => $this->key]);
    }

    public function send(mixed $message, int $targetPid = 0): bool
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        if ($this->queueId === null) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_MESSAGE_QUEUE,
                '消息队列未初始化'
            );
        }

        $serialized = $this->serialize($message);
        $length = strlen($serialized);

        if ($length > $this->bufferSize) {
            throw IPCException::bufferOverflow($length, $this->bufferSize);
        }

        $messageType = $targetPid > 0 ? $targetPid : $this->defaultType;

        $envelope = [
            'pid' => posix_getpid(),
            'data' => $message,
            'time' => microtime(true),
        ];

        $serialized = $this->serialize($envelope);

        $result = msg_send($this->queueId, $messageType, $serialized, false, false, $errorCode);

        if (!$result) {
            throw IPCException::sendFailed($targetPid, "错误码: {$errorCode}");
        }

        $this->logger->debug('消息队列消息已发送', [
            'type' => $messageType,
            'size' => strlen($serialized)
        ]);

        return true;
    }

    public function receive(?float $timeout = null): mixed
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        if ($this->queueId === null) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_MESSAGE_QUEUE,
                '消息队列未初始化'
            );
        }

        $flags = 0;

        if ($timeout !== null) {
            $flags = MSG_IPC_NOWAIT;
        }

        $startTime = microtime(true);

        while (true) {
            $receivedType = 0;
            $serialized = '';
            $errorCode = 0;

            $result = msg_receive(
                $this->queueId,
                0,
                $receivedType,
                $this->bufferSize,
                $serialized,
                false,
                $flags,
                $errorCode
            );

            if ($result) {
                $envelope = $this->unserialize($serialized);

                $this->logger->debug('消息队列消息已接收', [
                    'type' => $receivedType,
                    'source_pid' => $envelope['pid'] ?? 0
                ]);

                return $envelope['data'] ?? $envelope;
            }

            if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                throw IPCException::timeout($timeout);
            }

            if ($flags & MSG_IPC_NOWAIT) {
                usleep(1000);
            }
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
        return $this->receive($timeout);
    }

    public function getType(): string
    {
        return IPCInterface::TYPE_MESSAGE_QUEUE;
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
        if ($this->queueId !== null) {
            while (true) {
                $result = msg_receive(
                    $this->queueId,
                    0,
                    $type,
                    $this->bufferSize,
                    $message,
                    false,
                    MSG_IPC_NOWAIT,
                    $errorCode
                );

                if (!$result) {
                    break;
                }
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->queueId !== null) {
            msg_remove_queue($this->queueId);
            $this->queueId = null;
        }

        $this->closed = true;
        $this->logger->debug('消息队列 IPC 已关闭');
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getStats(): array
    {
        if ($this->queueId === null) {
            return [];
        }

        $stats = msg_stat_queue($this->queueId);

        return [
            'msg_qbytes' => $stats['msg_qbytes'] ?? 0,
            'msg_qnum' => $stats['msg_qnum'] ?? 0,
            'msg_lspid' => $stats['msg_lspid'] ?? 0,
            'msg_lrpid' => $stats['msg_lrpid'] ?? 0,
            'msg_stime' => $stats['msg_stime'] ?? 0,
            'msg_rtime' => $stats['msg_rtime'] ?? 0,
            'msg_ctime' => $stats['msg_ctime'] ?? 0,
        ];
    }

    public function getQueueSize(): int
    {
        if ($this->queueId === null) {
            return 0;
        }

        $stats = msg_stat_queue($this->queueId);

        return $stats['msg_qnum'] ?? 0;
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
