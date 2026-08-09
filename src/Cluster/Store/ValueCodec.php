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

    /**
     * 反序列化白名单。默认 false：只还原标量 / 数组，任何对象一律降级为
     * `__PHP_Incomplete_Class` 或 null，杜绝对象注入。
     *
     * 集群存储（RedisStore / GlobalDataStore）是**网络可达**的：能连上 Redis / GlobalData
     * 的人可以把任意字节写进共享键，若此处用无限制的 `unserialize()`，等于把
     * `__wakeup` / `__destruct` 链直接暴露给攻击者。确需还原对象时显式调
     * {@see self::setAllowedClasses()} 声明白名单。
     *
     * 注意：此处用**实例属性**而非 trait 静态属性——trait 的静态成员在使用类与 trait
     * 名下是两份独立存储，静态写法会让「设置」与「读取」各走各的（见 ValueCodecTest）。
     *
     * @var bool|list<class-string>
     */
    private bool|array $allowedClasses = false;

    /**
     * 限制反序列化时允许还原的类（与 LengthPrefix / 共享表同一套安全策略）。
     *
     * @param bool|list<class-string> $allowedClasses true=全部允许，false=全部禁止，数组=白名单
     */
    public function setAllowedClasses(bool|array $allowedClasses): void
    {
        $this->allowedClasses = $allowedClasses;
    }

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
            $payload = substr($raw, strlen(self::MAGIC));
            // 与 SafeUnserialize 同语义：'b:0;' 是合法的 false，与解析失败的 false 必须区分
            $value = @unserialize($payload, ['allowed_classes' => $this->allowedClasses]);

            return $value === false && $payload !== 'b:0;' ? null : $value;
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
