<?php

declare(strict_types=1);

namespace Kode\Process\Contracts;

use Kode\Process\Exceptions\IPCException;

/**
 * IPC 通信接口
 * 
 * 定义进程间通信的标准方法
 */
interface IPCInterface
{
    public const TYPE_SOCKET = 'socket';
    public const TYPE_SHARED_MEMORY = 'shared_memory';
    public const TYPE_MESSAGE_QUEUE = 'message_queue';
    public const TYPE_PIPE = 'pipe';

    public function send(mixed $message, int $targetPid = 0): bool;

    public function receive(?float $timeout = null): mixed;

    public function broadcast(mixed $message): bool;

    public function sendTo(int $targetPid, mixed $message): bool;

    public function receiveFrom(int $sourcePid, ?float $timeout = null): mixed;

    public function getType(): string;

    public function getBufferSize(): int;

    public function setBufferSize(int $size): void;

    public function flush(): void;

    public function close(): void;

    public function isClosed(): bool;
}
