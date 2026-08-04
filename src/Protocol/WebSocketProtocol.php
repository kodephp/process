<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * WebSocket 协议实现（RFC 6455）
 *
 * 帧结构：
 *   byte0: FIN(1) RSV1-3(3) OPCODE(4)
 *   byte1: MASK(1) PAYLOAD_LEN(7)
 *   [扩展长度 2 或 8 字节] [掩码键 4 字节] [载荷]
 */
final class WebSocketProtocol implements ProtocolInterface
{
    public const int OPCODE_CONTINUATION = 0x0;
    public const int OPCODE_TEXT = 0x1;
    public const int OPCODE_BINARY = 0x2;
    public const int OPCODE_CLOSE = 0x8;
    public const int OPCODE_PING = 0x9;
    public const int OPCODE_PONG = 0xA;

    /** RFC 6455 定义的握手固定 GUID */
    private const string HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** 单帧载荷上限，防止伪造的 64 位长度字段耗尽内存 */
    public const int MAX_PAYLOAD_LENGTH = 10485760;

    #[\Override]
    public static function getName(): string
    {
        return 'websocket';
    }

    /**
     * 计算完整帧长度
     *
     * @return int 0=数据不完整需继续收；-1=非法帧应断开；>0=完整帧字节数
     */
    #[\Override]
    public static function input(string $buffer, mixed $connection = null): int
    {
        $bufferLen = strlen($buffer);

        if ($bufferLen < 2) {
            return 0;
        }

        $secondByte = ord($buffer[1]);

        // 掩码标志位位于第二字节最高位，而非第一字节（第一字节最高位是 FIN）
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($bufferLen < 4) {
                return 0;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($bufferLen < 10) {
                return 0;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;

            // 64 位长度字段最高位必须为 0，且不得超出上限
            if ($payloadLen < 0) {
                return -1;
            }
        }

        if ($payloadLen > self::MAX_PAYLOAD_LENGTH) {
            return -1;
        }

        if ($masked) {
            $offset += 4;
        }

        return $offset + $payloadLen;
    }

    #[\Override]
    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return self::encodeFrame(self::OPCODE_TEXT, $data);
        }

        if (is_array($data)) {
            return self::encodeFrame(
                self::OPCODE_TEXT,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return '';
    }

    #[\Override]
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $frame = self::parseFrame($buffer);

        if ($frame === null) {
            return null;
        }

        return match ($frame['opcode']) {
            self::OPCODE_CLOSE => ['type' => 'close', 'data' => $frame['payload']],
            self::OPCODE_PING => ['type' => 'ping', 'data' => $frame['payload']],
            self::OPCODE_PONG => ['type' => 'pong', 'data' => $frame['payload']],
            default => [
                'type' => 'message',
                'opcode' => $frame['opcode'],
                'data' => $frame['payload'],
                'fin' => $frame['fin'],
            ],
        };
    }

    /**
     * 判断缓冲区是否为 WebSocket 握手请求
     */
    public static function isHandshakeRequest(string $buffer): bool
    {
        if (!str_starts_with($buffer, 'GET ')) {
            return false;
        }

        return stripos($buffer, 'Sec-WebSocket-Key:') !== false;
    }

    /**
     * 生成握手响应报文
     *
     * @param string $buffer 客户端握手请求原文
     * @param array<string, string> $extraHeaders 附加响应头
     * @return string|null 完整的 101 响应；请求非法时返回 null
     */
    public static function handshake(string $buffer, array $extraHeaders = []): ?string
    {
        $key = self::extractHeader($buffer, 'Sec-WebSocket-Key');

        if ($key === null || $key === '') {
            return null;
        }

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => self::acceptKey($key),
            ...$extraHeaders,
        ];

        $response = "HTTP/1.1 101 Switching Protocols\r\n";

        foreach ($headers as $name => $value) {
            $response .= "{$name}: {$value}\r\n";
        }

        return $response . "\r\n";
    }

    /**
     * 按 RFC 6455 计算 Sec-WebSocket-Accept
     */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::HANDSHAKE_GUID, true));
    }

    private static function extractHeader(string $buffer, string $name): ?string
    {
        $pattern = '/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi';

        if (preg_match($pattern, $buffer, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private static function encodeFrame(int $opcode, string $payload): string
    {
        $frame = chr(0x80 | $opcode);
        $len = strlen($payload);

        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 65535) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }

        return $frame . $payload;
    }

    /**
     * @return array{fin: int, opcode: int, payload: string, masked: bool}|null
     */
    private static function parseFrame(string $data): ?array
    {
        $dataLen = strlen($data);

        if ($dataLen < 2) {
            return null;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $fin = ($firstByte >> 7) & 0x1;
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($dataLen < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($dataLen < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        if ($payloadLen < 0 || $payloadLen > self::MAX_PAYLOAD_LENGTH) {
            return null;
        }

        $mask = '';

        if ($masked) {
            if ($dataLen < $offset + 4) {
                return null;
            }
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }

        if ($dataLen < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($data, $offset, $payloadLen);

        if ($masked) {
            $payload = self::applyMask($payload, $mask);
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'payload' => $payload,
            'masked' => $masked,
        ];
    }

    /**
     * 载荷掩码运算
     *
     * 使用字符串整体按位异或替代逐字节循环。PHP 的 `^` 作用于两个字符串时
     * 按字节并行运算，结果长度取较短者，因此把 4 字节掩码重复到不短于载荷即可。
     */
    private static function applyMask(string $payload, string $mask): string
    {
        $len = strlen($payload);

        if ($len === 0) {
            return '';
        }

        return $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
    }

    public static function encodeBinary(string $payload): string
    {
        return self::encodeFrame(self::OPCODE_BINARY, $payload);
    }

    public static function encodeClose(int $status = 1000, string $reason = ''): string
    {
        return self::encodeFrame(self::OPCODE_CLOSE, pack('n', $status) . $reason);
    }

    public static function encodePing(string $data = ''): string
    {
        return self::encodeFrame(self::OPCODE_PING, $data);
    }

    public static function encodePong(string $data = ''): string
    {
        return self::encodeFrame(self::OPCODE_PONG, $data);
    }
}
