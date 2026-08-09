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
    /**
     * msg_receive 因接收缓冲区小于报文而失败时的 errno。
     *
     * PHP 未导出 MSG_E2BIG 常量，退回到 POSIX 的 E2BIG = 7。
     */
    private const int ERR_TOO_BIG = 7;

    private ?\SysvMessageQueue $queueId = null;

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
        // 0600：只允许队列属主读写，避免同主机上任意本地用户读取 / 抽干 IPC 消息。
        // 失败时 msg_get_queue 返回 false，不能直接赋给 ?\SysvMessageQueue 属性（会抛 TypeError），
        // 因此先接到局部变量再判断。
        $queue = @msg_get_queue($this->key, 0600);

        if ($queue === false) {
            throw IPCException::connectionFailed(
                IPCInterface::TYPE_MESSAGE_QUEUE,
                '无法创建消息队列'
            );
        }

        $this->queueId = $queue;

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

        $messageType = $targetPid > 0 ? $targetPid : $this->defaultType;

        // 先包信封再校验长度：接收端用 bufferSize 作为 msg_receive 的 maxsize，
        // 真正要放进队列的是信封而不是裸消息。若只校验裸消息，信封超限时会被投递进队列，
        // 接收端每次都以 E2BIG 失败且不出队，形成永远取不走的「毒丸」。
        $envelope = [
            'pid' => posix_getpid(),
            'data' => $message,
            'time' => microtime(true),
        ];

        $serialized = $this->serialize($envelope);
        $length = strlen($serialized);

        if ($length > $this->bufferSize) {
            throw IPCException::bufferOverflow($length, $this->bufferSize);
        }

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
                    'source_pid' => is_array($envelope) ? ($envelope['pid'] ?? 0) : 0,
                ]);

                return is_array($envelope) && array_key_exists('data', $envelope)
                    ? $envelope['data']
                    : $envelope;
            }

            if ($errorCode === self::ERR_TOO_BIG) {
                // 报文大于接收缓冲区：本次接收不会出队，若直接重试会原地空转把 CPU 打满。
                // 用 MSG_NOERROR 再收一次（截断并出队）把这条毒丸丢掉，队列得以继续推进。
                $this->discardOversizedMessage();
                continue;
            }

            if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                throw IPCException::timeout($timeout);
            }

            // 队列为空（阻塞模式下 msg_receive 已在内核挂起，这里主要覆盖被信号打断等情形），
            // 让出 CPU 而不是紧凑重试。
            usleep(1000);
        }
    }

    /**
     * 丢弃队首那条超出接收缓冲区的报文。
     *
     * MSG_NOERROR 会把报文截断后出队，从而打破「收不到又删不掉」的死循环。
     */
    private function discardOversizedMessage(): void
    {
        $discardType = 0;
        $discarded = '';
        $errorCode = 0;

        $removed = @msg_receive(
            $this->queueId,
            0,
            $discardType,
            $this->bufferSize,
            $discarded,
            false,
            MSG_NOERROR | MSG_IPC_NOWAIT,
            $errorCode
        );

        $this->logger->warning('消息队列丢弃超长报文', [
            'type' => $discardType,
            'buffer_size' => $this->bufferSize,
            'removed' => $removed,
        ]);
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
                // MSG_NOERROR：超长报文截断后照样出队，否则毒丸会把 flush() 卡在原地
                $result = @msg_receive(
                    $this->queueId,
                    0,
                    $type,
                    $this->bufferSize,
                    $message,
                    false,
                    MSG_NOERROR | MSG_IPC_NOWAIT,
                    $errorCode
                );

                if (!$result) {
                    break;
                }
            }
        }
    }

    /**
     * 关闭本进程的队列句柄。
     *
     * 只脱离句柄，不删除队列——System V 消息队列是全主机共享的内核对象，
     * 由 __destruct 触发的 msg_remove_queue 会把队列连同其他进程的在途消息一起销毁。
     * 需要真正回收内核对象时请显式调用 {@see destroy()}。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->queueId = null;
        $this->closed = true;
        $this->logger->debug('消息队列 IPC 已关闭');
    }

    /**
     * 销毁底层消息队列（对所有进程生效），并关闭本句柄。
     */
    public function destroy(): void
    {
        if ($this->queueId !== null) {
            @msg_remove_queue($this->queueId);
        }

        $this->close();
        $this->logger->debug('消息队列已销毁', ['key' => $this->key]);
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
            // allowed_classes => false：IPC 报文只承载数据，禁止实例化任意类，
            // 避免本地攻击者向队列注入报文触发反序列化利用链。
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
