<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

final class TextProtocol implements ProtocolInterface
{
    private const EOF = "\n";
    private const MAX_LENGTH = 1048576;

    public static function getName(): string
    {
        return 'text';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, self::EOF);

        if ($pos === false) {
            if (strlen($buffer) > self::MAX_LENGTH) {
                return -1;
            }
            return 0;
        }

        return $pos + 1;
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return $data . self::EOF;
        }

        if (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE) . self::EOF;
        }

        return (string)$data . self::EOF;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $data = rtrim($buffer, self::EOF);

        $json = @json_decode($data, true);
        
        if ($json !== null) {
            return $json;
        }

        return $data;
    }
}
