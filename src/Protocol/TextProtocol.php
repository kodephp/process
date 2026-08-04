<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * 文本协议
 *
 * 以换行符作为消息边界，适合面向行的简单交互。
 */
final class TextProtocol implements ProtocolInterface
{
    private const string EOF = "\n";

    public const int MAX_LENGTH = 1048576;

    #[\Override]
    public static function getName(): string
    {
        return 'text';
    }

    #[\Override]
    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, self::EOF);

        if ($pos === false) {
            return strlen($buffer) > self::MAX_LENGTH ? -1 : 0;
        }

        return $pos + 1;
    }

    #[\Override]
    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return $data . self::EOF;
        }

        if (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . self::EOF;
        }

        return (string) $data . self::EOF;
    }

    #[\Override]
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $data = rtrim($buffer, self::EOF);

        if ($data === '') {
            return $data;
        }

        if (json_validate($data)) {
            $decoded = json_decode($data, true);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        return $data;
    }
}
