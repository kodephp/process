<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

final class TcpProtocol implements ProtocolInterface
{
    public static function getName(): string
    {
        return 'tcp';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        return strlen($buffer);
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return serialize($data);
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $json = @json_decode($buffer, true);
        
        if ($json !== null) {
            return $json;
        }

        $unserialized = @unserialize($buffer);
        
        if ($unserialized !== false || $buffer === serialize(false)) {
            return $unserialized;
        }

        return $buffer;
    }
}
