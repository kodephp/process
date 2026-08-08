<?php

declare(strict_types=1);

namespace Kode\Process\Protocol\Http2;

/**
 * HTTP/2 协议错误。
 *
 * `code` 直接携带 RFC 7540 §7 定义的错误码，会话层据此发送 GOAWAY 或 RST_STREAM。
 * `streamId` 为 0 表示连接级错误（必须 GOAWAY 关连接），非 0 表示流级错误
 * （只需 RST_STREAM 重置该流，连接可继续服务其它流）。
 */
final class Http2Exception extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $errorCode = Frame::ERROR_PROTOCOL,
        private readonly int $streamId = 0,
    ) {
        parent::__construct($message, $errorCode);
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function streamId(): int
    {
        return $this->streamId;
    }

    /** 是否为流级错误（只重置单条流，不必断开连接） */
    public function isStreamError(): bool
    {
        return $this->streamId !== 0;
    }

    public static function protocol(string $message, int $streamId = 0): self
    {
        return new self($message, Frame::ERROR_PROTOCOL, $streamId);
    }

    public static function compression(string $message): self
    {
        // HPACK 上下文一旦损坏就无法恢复，必定是连接级错误
        return new self($message, Frame::ERROR_COMPRESSION, 0);
    }

    public static function frameSize(string $message, int $streamId = 0): self
    {
        return new self($message, Frame::ERROR_FRAME_SIZE, $streamId);
    }

    public static function flowControl(string $message, int $streamId = 0): self
    {
        return new self($message, Frame::ERROR_FLOW_CONTROL, $streamId);
    }

    public static function streamClosed(string $message, int $streamId): self
    {
        return new self($message, Frame::ERROR_STREAM_CLOSED, $streamId);
    }
}
