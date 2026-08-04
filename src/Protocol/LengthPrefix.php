<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * 长度前缀协议
 *
 * 首部 4 字节网络字节序标记包长度（含首部本身），适用于二进制数据传输。
 *
 * 安全提示：encode() 会对非数组、非字符串的值调用 serialize()，因此 decode()
 * 保留了对象还原能力。若该协议直接面向不受信任的网络输入，请改用 TcpProtocol，
 * 或通过 setAllowedClasses() 限制可还原的类。
 */
final class LengthPrefix implements ProtocolInterface
{
    private const int HEAD_LEN = 4;

    public const int MAX_SIZE = 10485760;

    /** @var bool|list<class-string> */
    private static bool|array $allowedClasses = true;

    /**
     * 限制反序列化时允许还原的类
     *
     * @param bool|list<class-string> $allowedClasses true=全部允许，false=全部禁止，数组=白名单
     */
    public static function setAllowedClasses(bool|array $allowedClasses): void
    {
        self::$allowedClasses = $allowedClasses;
    }

    #[\Override]
    public static function getName(): string
    {
        return 'length-prefix';
    }

    #[\Override]
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

    #[\Override]
    public static function encode(mixed $data, mixed $connection = null): string
    {
        $body = match (true) {
            is_string($data) => $data,
            is_array($data) => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => serialize($data),
        };

        return pack('N', self::HEAD_LEN + strlen($body)) . $body;
    }

    #[\Override]
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $body = substr($buffer, self::HEAD_LEN);

        if ($body === '') {
            return null;
        }

        if (json_validate($body)) {
            $decoded = json_decode($body, true);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        if (self::looksLikeSerialized($body)) {
            $unserialized = @unserialize($body, ['allowed_classes' => self::$allowedClasses]);

            if ($unserialized !== false || $body === 'b:0;') {
                return $unserialized;
            }
        }

        return $body;
    }

    private static function looksLikeSerialized(string $body): bool
    {
        if (strlen($body) < 3) {
            return $body === 'N;';
        }

        return match ($body[0]) {
            'a', 'O', 's', 'i', 'd', 'b' => $body[1] === ':',
            'N' => $body === 'N;',
            default => false,
        };
    }
}
