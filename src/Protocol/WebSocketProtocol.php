<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

final class WebSocketProtocol implements ProtocolInterface
{
    private const OPCODE_CONTINUATION = 0x0;
    private const OPCODE_TEXT = 0x1;
    private const OPCODE_BINARY = 0x2;
    private const OPCODE_CLOSE = 0x8;
    private const OPCODE_PING = 0x9;
    private const OPCODE_PONG = 0xA;

    public static function getName(): string
    {
        return 'websocket';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        if (strlen($buffer) < 2) {
            return 0;
        }

        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);

        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if (strlen($buffer) < 4) {
                return 0;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if (strlen($buffer) < 10) {
                return 0;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($firstByte & 0x80) {
            $offset += 4;
        }

        return $offset + $payloadLen;
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return self::encodeFrame(self::OPCODE_TEXT, $data);
        }

        if (is_array($data)) {
            return self::encodeFrame(self::OPCODE_TEXT, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return '';
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $frame = self::parseFrame($buffer);

        if ($frame === null) {
            return null;
        }

        if ($frame['opcode'] === self::OPCODE_CLOSE) {
            return ['type' => 'close', 'data' => $frame['payload']];
        }

        if ($frame['opcode'] === self::OPCODE_PING) {
            return ['type' => 'ping', 'data' => $frame['payload']];
        }

        if ($frame['opcode'] === self::OPCODE_PONG) {
            return ['type' => 'pong', 'data' => $frame['payload']];
        }

        return [
            'type' => 'message',
            'opcode' => $frame['opcode'],
            'data' => $frame['payload'],
            'fin' => $frame['fin'],
        ];
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

        $frame .= $payload;

        return $frame;
    }

    private static function parseFrame(string $data): ?array
    {
        if (strlen($data) < 2) {
            return null;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $fin = ($firstByte >> 7) & 0x1;
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte >> 7) & 0x1;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if (strlen($data) < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if (strlen($data) < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        $mask = null;
        if ($masked) {
            if (strlen($data) < $offset + 4) {
                return null;
            }
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }

        if (strlen($data) < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($data, $offset, $payloadLen);

        if ($masked && $mask !== null) {
            $payload = self::applyMask($payload, $mask);
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'payload' => $payload,
            'masked' => $masked,
        ];
    }

    private static function applyMask(string $payload, string $mask): string
    {
        $result = '';
        $maskLen = strlen($mask);

        for ($i = 0; $i < strlen($payload); $i++) {
            $result .= $payload[$i] ^ $mask[$i % $maskLen];
        }

        return $result;
    }

    public static function encodeClose(int $status = 1000, string $reason = ''): string
    {
        $payload = pack('n', $status) . $reason;
        return self::encodeFrame(self::OPCODE_CLOSE, $payload);
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
