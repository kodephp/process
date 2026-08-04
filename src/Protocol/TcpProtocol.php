<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * 原始 TCP 协议
 *
 * 不做分包，收到多少交付多少。解码时依次尝试 JSON、序列化，均不匹配则原样返回。
 */
final class TcpProtocol implements ProtocolInterface
{
    #[\Override]
    public static function getName(): string
    {
        return 'tcp';
    }

    #[\Override]
    public static function input(string $buffer, mixed $connection = null): int
    {
        return strlen($buffer);
    }

    #[\Override]
    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return serialize($data);
    }

    #[\Override]
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        if ($buffer === '') {
            return $buffer;
        }

        // json_validate() 先行判定，避免用 @ 抑制符掩盖真实解析错误
        if (json_validate($buffer)) {
            $decoded = json_decode($buffer, true);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        if (self::looksLikeSerialized($buffer)) {
            $unserialized = @unserialize($buffer, ['allowed_classes' => false]);

            if ($unserialized !== false || $buffer === 'b:0;') {
                return $unserialized;
            }
        }

        return $buffer;
    }

    /**
     * 粗筛序列化字符串，避免对任意文本调用 unserialize()
     */
    private static function looksLikeSerialized(string $buffer): bool
    {
        if (strlen($buffer) < 3) {
            return false;
        }

        return match ($buffer[0]) {
            'a', 'O', 's', 'i', 'd', 'b' => $buffer[1] === ':',
            'N' => $buffer === 'N;',
            default => false,
        };
    }
}
