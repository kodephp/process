<?php

declare(strict_types=1);

namespace Kode\Process\IPC;

use Kode\Process\Contracts\IPCInterface;
use Kode\Process\Exceptions\IPCException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 共享内存 IPC 通信
 * 
 * 基于共享内存的进程间通信实现
 */
class SharedMemoryIPC implements IPCInterface
{
    private ?int $shmId = null;

    private string $shmKey;

    private int $bufferSize = 1048576;

    private bool $closed = false;

    private LoggerInterface $logger;

    private int $projectId;

    private ?int $semId = null;

    private int $headerSize = 16;

    private int $readOffset = 0;

    private int $writeOffset = 0;

    public function __construct(?int $projectId = null, ?LoggerInterface $logger = null)
    {
        if (!extension_loaded('sysvshm')) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_SHARED_MEMORY,
                'sysvshm 扩展未加载'
            );
        }

        $this->logger = $logger ?? new NullLogger();
        $this->projectId = $projectId ?? ftok(__FILE__, 'a');
        $this->shmKey = $this->projectId;

        $this->initialize();
    }

    private function initialize(): void
    {
        $this->shmId = shm_attach($this->shmKey, $this->bufferSize, 0644);

        if ($this->shmId === false) {
            throw IPCException::sharedMemoryFailed('attach', '无法创建共享内存段');
        }

        if (extension_loaded('sysvsem')) {
            $this->semId = sem_get($this->shmKey, 1, 0644, 1);

            if ($this->semId === false) {
                $this->logger->warning('无法创建信号量，将使用无锁模式');
            }
        }

        $this->logger->debug('共享内存 IPC 已初始化', [
            'key' => $this->shmKey,
            'size' => $this->bufferSize
        ]);
    }

    private function lock(): bool
    {
        if ($this->semId === null) {
            return true;
        }

        return sem_acquire($this->semId);
    }

    private function unlock(): bool
    {
        if ($this->semId === null) {
            return true;
        }

        return sem_release($this->semId);
    }

    public function send(mixed $message, int $targetPid = 0): bool
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        if ($this->shmId === null) {
            throw IPCException::sharedMemoryFailed('send', '共享内存未初始化');
        }

        $serialized = $this->serialize($message);
        $length = strlen($serialized);

        if ($length + $this->headerSize > $this->bufferSize) {
            throw IPCException::bufferOverflow($length, $this->bufferSize - $this->headerSize);
        }

        $this->lock();

        try {
            $header = pack('NN', $length, $targetPid);

            $data = $header . $serialized;

            $written = shm_put_var($this->shmId, 1, $data);

            if (!$written) {
                throw IPCException::sendFailed($targetPid, '写入共享内存失败');
            }

            $this->logger->debug('共享内存消息已发送', [
                'size' => $length,
                'target_pid' => $targetPid
            ]);

            return true;
        } finally {
            $this->unlock();
        }
    }

    public function receive(?float $timeout = null): mixed
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        if ($this->shmId === null) {
            throw IPCException::sharedMemoryFailed('receive', '共享内存未初始化');
        }

        $startTime = microtime(true);

        while (true) {
            $this->lock();

            try {
                $data = shm_get_var($this->shmId, 1);

                if ($data === false) {
                    if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                        throw IPCException::timeout($timeout);
                    }

                    usleep(1000);
                    continue;
                }

                $header = substr($data, 0, $this->headerSize);
                $unpacked = unpack('Nlength/Npid', $header);

                $length = $unpacked['length'];
                $sourcePid = $unpacked['pid'];

                $serialized = substr($data, $this->headerSize, $length);

                shm_remove_var($this->shmId, 1);

                $message = $this->unserialize($serialized);

                $this->logger->debug('共享内存消息已接收', [
                    'size' => $length,
                    'source_pid' => $sourcePid
                ]);

                return $message;
            } finally {
                $this->unlock();
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
        return IPCInterface::TYPE_SHARED_MEMORY;
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
        if ($this->shmId !== null) {
            $this->lock();

            try {
                shm_remove_var($this->shmId, 1);
            } finally {
                $this->unlock();
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->shmId !== null) {
            shm_detach($this->shmId);
            $this->shmId = null;
        }

        if ($this->semId !== null) {
            sem_remove($this->semId);
            $this->semId = null;
        }

        $this->closed = true;
        $this->logger->debug('共享内存 IPC 已关闭');
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
