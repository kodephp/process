<?php

declare(strict_types=1);

namespace Kode\Process\IPC;

use Kode\Process\Contracts\IPCInterface;
use Kode\Process\Exceptions\IPCException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 共享内存 IPC（有界环形队列）
 *
 * 基于 System V 共享内存（shm_attach）+ 信号量实现的多生产者 / 多消费者队列：
 *  - 每个槽位是一个独立的共享内存变量，消息以环形方式推进 head/tail；
 *  - O(1) 入队 / 出队，无单槽位覆盖问题，也避免了“持锁睡眠”导致的发送方被饿死；
 *  - 适合同主机多进程间的高吞吐消息传递。
 *
 * 与 SocketIPC（Unix 域套接字）相比，数据直接落在共享内存，少一次内核套接字缓冲拷贝，
 * 在中小消息场景下吞吐更高、延迟更低。
 */
class SharedMemoryIPC implements IPCInterface
{
    private const string MAGIC = 'GDQ1';
    private const int HEADER_VAR = 1;
    private const int FIRST_SLOT = 2;
    private const int SLOT_OVERHEAD = 1024;
    private const int MAX_SEGMENT = 4 * 1024 * 1024;

    private \SysvSharedMemory $shm;
    private ?\SysvSemaphore $sem;
    private int $key;
    private int $bufferSize;
    private int $slotSize;
    private int $capacity;
    private bool $closed = false;
    private LoggerInterface $logger;

    public function __construct(
        ?int $projectId = null,
        ?LoggerInterface $logger = null,
        int $bufferSize = 2 * 1024 * 1024,
        int $slotSize = 4096
    ) {
        if (!extension_loaded('sysvshm')) {
            throw IPCException::connectionFailed(IPCInterface::TYPE_SHARED_MEMORY, 'sysvshm 扩展未加载');
        }
        if (!extension_loaded('sysvsem')) {
            throw IPCException::connectionFailed(IPCInterface::TYPE_SHARED_MEMORY, 'sysvsem 扩展未加载');
        }

        $this->logger = $logger ?? new NullLogger();
        $this->bufferSize = min($bufferSize, self::MAX_SEGMENT);
        $this->slotSize = $slotSize;

        $avail = $this->bufferSize - 8192;
        $this->capacity = max(1, intdiv($avail, $this->slotSize + self::SLOT_OVERHEAD));
        $shmSize = $this->capacity * ($this->slotSize + self::SLOT_OVERHEAD) + 8192;

        $this->key = $projectId ?? ftok(__FILE__, 'a');

        // 0600：仅属主可读写，避免同主机上任意本地用户读取 / 抽干共享内存队列
        $shm = @shm_attach($this->key, $shmSize, 0600);
        if ($shm === false) {
            throw IPCException::sharedMemoryFailed('attach', '无法创建共享内存段（可能超出系统 shmmax 限制）');
        }
        $this->shm = $shm;

        $semKey = ($this->key & 0x7FFFFFFF) ^ 0x6B1D8A31;
        $sem = @sem_get($semKey, 1, 0600, true);
        if ($sem === false) {
            $this->logger->warning('无法创建信号量，将使用无锁模式（不保证并发安全）');
            $this->sem = null;
        } else {
            $this->sem = $sem;
        }

        $this->initializeHeader();

        $this->logger->debug('共享内存 IPC 已初始化', [
            'key' => $this->key,
            'size' => $shmSize,
            'capacity' => $this->capacity,
            'slot_size' => $this->slotSize,
        ]);
    }

    private function initializeHeader(): void
    {
        $header = @shm_get_var($this->shm, self::HEADER_VAR);
        if (is_array($header) && ($header['magic'] ?? '') === self::MAGIC) {
            // 既有段：采用其容量 / 槽位大小，避免布局不一致
            $this->capacity = $header['capacity'];
            $this->slotSize = $header['slotSize'];
            return;
        }
        $this->lock();
        try {
            $header = @shm_get_var($this->shm, self::HEADER_VAR);
            if (is_array($header) && ($header['magic'] ?? '') === self::MAGIC) {
                $this->capacity = $header['capacity'];
                $this->slotSize = $header['slotSize'];
                return;
            }
            $this->writeHeader(0, 0, 0);
        } finally {
            $this->unlock();
        }
    }

    private function readHeader(): array
    {
        $header = @shm_get_var($this->shm, self::HEADER_VAR);
        if (!is_array($header)) {
            return ['head' => 0, 'tail' => 0, 'count' => 0, 'capacity' => $this->capacity, 'slotSize' => $this->slotSize];
        }
        return $header;
    }

    private function writeHeader(int $head, int $tail, int $count): void
    {
        shm_put_var($this->shm, self::HEADER_VAR, [
            'magic' => self::MAGIC,
            'head' => $head,
            'tail' => $tail,
            'count' => $count,
            'capacity' => $this->capacity,
            'slotSize' => $this->slotSize,
        ]);
    }

    private function lock(): void
    {
        if ($this->sem !== null) {
            @sem_acquire($this->sem);
        }
    }

    private function unlock(): void
    {
        if ($this->sem !== null) {
            @sem_release($this->sem);
        }
    }

    private function slotVar(int $index): int
    {
        return self::FIRST_SLOT + $index;
    }

    public function send(mixed $message, int $targetPid = 0): bool
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        $serialized = $this->serialize($message);
        $len = strlen($serialized);
        if ($len > $this->slotSize) {
            throw IPCException::bufferOverflow($len, $this->slotSize);
        }

        $this->lock();
        try {
            $header = $this->readHeader();
            if ($header['count'] >= $header['capacity']) {
                return false; // 队列已满
            }
            // 共享内存段耗尽时 shm_put_var 会失败。此前忽略返回值，照样推进 tail/count，
            // 消费者随后会从一个根本没写进去的槽位读到 false。必须先确认写入成功再推进游标。
            if (@shm_put_var($this->shm, $this->slotVar($header['tail']), $serialized) === false) {
                $this->logger->warning('共享内存段已满，消息未入队', [
                    'size' => $len,
                    'slot' => $header['tail'],
                ]);
                return false;
            }
            $tail = ($header['tail'] + 1) % $header['capacity'];
            $this->writeHeader($header['head'], $tail, $header['count'] + 1);
        } finally {
            $this->unlock();
        }

        $this->logger->debug('共享内存消息已发送', ['size' => $len, 'target_pid' => $targetPid]);
        return true;
    }

    public function receive(?float $timeout = null): mixed
    {
        if ($this->closed) {
            throw IPCException::channelClosed();
        }

        $start = $timeout !== null ? microtime(true) : 0;

        while (true) {
            $this->lock();
            try {
                $header = $this->readHeader();
                if ($header['count'] > 0) {
                    $serialized = @shm_get_var($this->shm, $this->slotVar($header['head']));
                    @shm_remove_var($this->shm, $this->slotVar($header['head']));
                    $head = ($header['head'] + 1) % $header['capacity'];
                    $this->writeHeader($head, $header['tail'], $header['count'] - 1);
                    $message = $this->unserialize((string) $serialized);
                    $this->logger->debug('共享内存消息已接收', ['size' => strlen((string) $serialized)]);
                    return $message;
                }
            } finally {
                $this->unlock();
            }

            if ($timeout !== null) {
                if (microtime(true) - $start >= $timeout) {
                    throw IPCException::timeout($timeout);
                }
                usleep(200);
            } else {
                usleep(200);
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
        $this->bufferSize = min($size, self::MAX_SEGMENT);
        $avail = $this->bufferSize - 8192;
        $this->capacity = max(1, intdiv($avail, $this->slotSize + self::SLOT_OVERHEAD));
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function flush(): void
    {
        $this->lock();
        try {
            $header = $this->readHeader();
            for ($i = 0; $i < $header['capacity']; $i++) {
                @shm_remove_var($this->shm, $this->slotVar($i));
            }
            $this->writeHeader(0, 0, 0);
        } finally {
            $this->unlock();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        shm_detach($this->shm);
        $this->closed = true;
        $this->logger->debug('共享内存 IPC 已关闭');
    }

    /**
     * 销毁底层共享内存段（最后一个进程脱离后被回收）。
     */
    public function destroy(): void
    {
        if (!$this->closed) {
            @shm_remove($this->shm);
            $this->close();
        }
        if ($this->sem !== null) {
            @sem_remove($this->sem);
            $this->sem = null;
        }
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
            // allowed_classes => false：IPC 报文只承载数据，禁止实例化任意类，
            // 避免本地攻击者写入共享内存段触发反序列化利用链。
            return @unserialize($data, ['allowed_classes' => false]);
        } catch (\Throwable $e) {
            throw IPCException::serializationFailed($data, $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
