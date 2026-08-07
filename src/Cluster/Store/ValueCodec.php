<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Store;

/**
 * 存储值编解码。
 *
 * 整数以裸十进制字符串落盘，这样 Redis 的 `INCRBY` 等原生原子自增指令可以直接作用其上；
 * 其余类型加 `\0K` 魔数前缀后 serialize，读取时按前缀区分，避免把用户的数字字符串
 * 误判成整数。
 *
 * @since 5.0.0
 */
trait ValueCodec
{
    /** 非整数值的序列化魔数前缀。 */
    private const MAGIC = "\0K";

    protected function encodeValue(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return self::MAGIC . serialize($value);
    }

    protected function decodeValue(mixed $raw): mixed
    {
        if ($raw === null || $raw === false) {
            return null;
        }

        $raw = (string) $raw;

        if (str_starts_with($raw, self::MAGIC)) {
            return unserialize(substr($raw, strlen(self::MAGIC)));
        }

        if ($raw !== '' && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return $raw;
    }

    /** 毫秒 TTL 转秒（向上取整，至少 1 秒），供只支持秒级 TTL 的后端使用。 */
    protected function ttlToSeconds(int $ttlMs): int
    {
        if ($ttlMs <= 0) {
            return 0;
        }

        return max(1, (int) ceil($ttlMs / 1000));
    }
}
