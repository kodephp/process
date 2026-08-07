<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Fixtures;

use Kode\Process\Protocol\ProtocolInterface;

/**
 * 测试用自定义协议：换行分隔的文本帧。
 *
 * - input()：遇到 "\n" 即为一帧结束，返回含换行符的长度；超长无换行视为协议错误。
 * - decode()：去掉行尾换行，返回纯文本。
 * - encode()：发送时补回 "\n"，保持对端可读。
 *
 * 仅用于验证 Native 运行时「自定义协议一等公民」：Kode::serve('echo://..') 应能直接用它。
 */
final class EchoProtocol implements ProtocolInterface
{
    private const int MAX_LINE = 4096;

    public static function getName(): string
    {
        return 'echo';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, "\n");
        if ($pos !== false) {
            return $pos + 1;
        }

        // 一帧未收满：超长无换行判定为协议错误，避免无限缓冲
        return strlen($buffer) > self::MAX_LINE ? -1 : 0;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        return rtrim($buffer, "\r\n");
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        return (string)$data . "\n";
    }
}
