<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * 二进制文件传输协议
 * 
 * 支持文件名和文件数据的二进制传输
 * 格式: [4字节总长度][1字节文件名长度][文件名][文件数据]
 */
final class BinaryFile implements ProtocolInterface
{
    private const HEAD_LEN = 5;
    private const MAX_SIZE = 104857600;

    public static function getName(): string
    {
        return 'binary-file';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return 0;
        }

        $data = unpack('Nlen/Cname_len', $buffer);

        if ($data === false || $data['len'] < self::HEAD_LEN || $data['len'] > self::MAX_SIZE) {
            return -1;
        }

        return strlen($buffer) < $data['len'] ? 0 : $data['len'];
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (!is_array($data)) {
            return (string)$data;
        }

        $name = (string)($data['name'] ?? $data['file_name'] ?? '');
        $content = (string)($data['data'] ?? $data['content'] ?? '');
        $totalLen = self::HEAD_LEN + strlen($name) + strlen($content);

        return pack('N', $totalLen) . pack('C', strlen($name)) . $name . $content;
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return null;
        }

        $data = unpack('Nlen/Cname_len', $buffer);

        if ($data === false) {
            return null;
        }

        $nameLen = $data['name_len'];

        if (strlen($buffer) < self::HEAD_LEN + $nameLen) {
            return null;
        }

        return [
            'name' => substr($buffer, self::HEAD_LEN, $nameLen),
            'data' => substr($buffer, self::HEAD_LEN + $nameLen)
        ];
    }
}
