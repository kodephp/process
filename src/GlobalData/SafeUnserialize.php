<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

/**
 * 受控反序列化。
 *
 * 共享表 / 共享内存的存储区可被同机的其他进程写入，把任意字节交给无限制的
 * {@see unserialize()} 等于把 __wakeup / __destruct 链暴露给攻击者（对象注入）。
 * 因此默认禁止实例化任何类，损坏 / 被投毒的载荷一律降级为 null，而不是把
 * unserialize() 的 false 当成业务值返回。
 *
 * 两个后端（{@see SwooleTable} / {@see WorkermanTable}）共用本工具，避免实现漂移。
 */
final class SafeUnserialize
{
    /**
     * @param bool|list<class-string> $allowedClasses 默认 false：只还原标量 / 数组；
     *        确需还原对象时显式传类名白名单。
     */
    public static function value(string $payload, bool|array $allowedClasses = false): mixed
    {
        if ($payload === '') {
            return null;
        }

        $value = @unserialize($payload, ['allowed_classes' => $allowedClasses]);

        // 'b:0;' 是合法的 false，与解析失败的 false 必须区分
        return $value === false && $payload !== 'b:0;' ? null : $value;
    }
}
