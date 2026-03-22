<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * 协议接口
 * 
 * 用于定义自定义协议的规范
 * 所有方法都是静态的，直接通过类名调用
 */
interface ProtocolInterface
{
    /**
     * 获取协议名称
     */
    public static function getName(): string;

    /**
     * 检查包完整性，返回包长度
     * 
     * @param string $buffer 输入缓冲区
     * @param mixed $connection 连接对象（可选）
     * @return int 包长度，0 表示需要更多数据，-1 表示协议错误
     */
    public static function input(string $buffer, mixed $connection = null): int;

    /**
     * 编码数据
     * 
     * @param mixed $data 要发送的数据
     * @param mixed $connection 连接对象（可选）
     * @return string 编码后的数据
     */
    public static function encode(mixed $data, mixed $connection = null): string;

    /**
     * 解码数据
     * 
     * @param string $buffer 接收到的完整包
     * @param mixed $connection 连接对象（可选）
     * @return mixed 解码后的数据
     */
    public static function decode(string $buffer, mixed $connection = null): mixed;
}
