<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * 长度前缀协议
 * 
 * 首部 4 字节网络字节序标记包长度
 * 适用于二进制数据传输，支持 JSON 和序列化数据
 */
final class LengthPrefix implements ProtocolInterface
{
    private const HEAD_LEN = 4;
    private const MAX_SIZE = 10485760;

    public static function getName(): string
    {
        return 'length-prefix';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return 0;
        }

        $data = unpack('Nlen', $buffer);

        if ($data === false || $data['len'] < self::HEAD_LEN || $data['len'] > self::MAX_SIZE) {
            return -1;
        }

        return strlen($buffer) < $data['len'] ? 0 : $data['len'];
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        $body = match (true) {
            is_array($data) => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            is_string($data) => $data,
            default => serialize($data)
        };

        return pack('N', self::HEAD_LEN + strlen($body)) . $body;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $body = substr($buffer, self::HEAD_LEN);

        if (empty($body)) {
            return null;
        }

        $json = json_decode($body, true);

        if ($json !== null) {
            return $json;
        }

        $unserialized = @unserialize($body);

        return $unserialized !== false ? $unserialized : $body;
    }
}
