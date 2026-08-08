<?php

declare(strict_types=1);

namespace Kode\Process\Protocol\Http2;

/**
 * HTTP/2 帧编解码（RFC 7540 §4、§6）。
 *
 * 帧格式（9 字节定长头 + 变长负载）：
 * ```
 *  +-----------------------------------------------+
 *  |                 Length (24)                   |
 *  +---------------+---------------+---------------+
 *  |   Type (8)    |   Flags (8)   |
 *  +-+-------------+---------------+-------------------------------+
 *  |R|                 Stream Identifier (31)                      |
 *  +=+=============================================================+
 *  |                   Frame Payload (0...)                      ...
 *  +---------------------------------------------------------------+
 * ```
 *
 * 本类只做「一帧」的字节级编解码与常量定义，不含任何连接状态；
 * 流状态机、流控与 HPACK 上下文由 {@see Http2Session} 负责。
 */
final class Frame
{
    /** 帧头固定长度 */
    public const int HEADER_SIZE = 9;

    /** 连接前奏（RFC 7540 §3.5） */
    public const string PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    public const int PREFACE_SIZE = 24;

    // ------------------------------------------------------------ 帧类型
    public const int TYPE_DATA          = 0x0;
    public const int TYPE_HEADERS       = 0x1;
    public const int TYPE_PRIORITY      = 0x2;
    public const int TYPE_RST_STREAM    = 0x3;
    public const int TYPE_SETTINGS      = 0x4;
    public const int TYPE_PUSH_PROMISE  = 0x5;
    public const int TYPE_PING          = 0x6;
    public const int TYPE_GOAWAY        = 0x7;
    public const int TYPE_WINDOW_UPDATE = 0x8;
    public const int TYPE_CONTINUATION  = 0x9;

    // -------------------------------------------------------------- 标志
    public const int FLAG_END_STREAM  = 0x1;
    public const int FLAG_ACK         = 0x1;
    public const int FLAG_END_HEADERS = 0x4;
    public const int FLAG_PADDED      = 0x8;
    public const int FLAG_PRIORITY    = 0x20;

    // ------------------------------------------------------------ 设置项
    public const int SETTINGS_HEADER_TABLE_SIZE      = 0x1;
    public const int SETTINGS_ENABLE_PUSH            = 0x2;
    public const int SETTINGS_MAX_CONCURRENT_STREAMS = 0x3;
    public const int SETTINGS_INITIAL_WINDOW_SIZE    = 0x4;
    public const int SETTINGS_MAX_FRAME_SIZE         = 0x5;
    public const int SETTINGS_MAX_HEADER_LIST_SIZE   = 0x6;

    // ------------------------------------------------------------ 错误码
    public const int ERROR_NO_ERROR            = 0x0;
    public const int ERROR_PROTOCOL            = 0x1;
    public const int ERROR_INTERNAL            = 0x2;
    public const int ERROR_FLOW_CONTROL        = 0x3;
    public const int ERROR_SETTINGS_TIMEOUT    = 0x4;
    public const int ERROR_STREAM_CLOSED       = 0x5;
    public const int ERROR_FRAME_SIZE          = 0x6;
    public const int ERROR_REFUSED_STREAM      = 0x7;
    public const int ERROR_CANCEL              = 0x8;
    public const int ERROR_COMPRESSION         = 0x9;
    public const int ERROR_CONNECT             = 0xA;
    public const int ERROR_ENHANCE_YOUR_CALM   = 0xB;
    public const int ERROR_INADEQUATE_SECURITY = 0xC;
    public const int ERROR_HTTP_1_1_REQUIRED   = 0xD;

    /** 协议规定的最小 / 最大可协商帧尺寸（RFC 7540 §4.2） */
    public const int MIN_MAX_FRAME_SIZE = 16384;
    public const int MAX_MAX_FRAME_SIZE = 16777215;

    /** 默认初始流控窗口（RFC 7540 §6.9.2） */
    public const int DEFAULT_WINDOW_SIZE = 65535;

    /** 流控窗口上限，超过即 FLOW_CONTROL_ERROR */
    public const int MAX_WINDOW_SIZE = 2147483647;

    /**
     * 编码一帧。
     *
     * @param int $type     帧类型（TYPE_*）
     * @param int $flags    标志位（FLAG_* 的按位或）
     * @param int $streamId 流 ID，连接级帧为 0
     */
    public static function encode(int $type, int $flags, int $streamId, string $payload = ''): string
    {
        $length = strlen($payload);

        // 24 位长度手工拼装：pack('N') 是 32 位，需截掉最高字节。
        // 经验证 pack('N', …) 在本构建下优于 4×chr 算术拼接，故流 ID 仍走 pack。
        return chr(($length >> 16) & 0xFF)
            . chr(($length >> 8) & 0xFF)
            . chr($length & 0xFF)
            . chr($type)
            . chr($flags)
            . pack('N', $streamId & 0x7FFFFFFF)
            . $payload;
    }

    /**
     * 从缓冲区解出一帧。
     *
     * @param int $maxFrameSize 本端通告的 SETTINGS_MAX_FRAME_SIZE，超出即 FRAME_SIZE_ERROR
     * @return array{type: int, flags: int, stream: int, payload: string, size: int}|null
     *         null = 数据不足，需要继续收包
     * @throws Http2Exception 帧长度超过协商上限
     */
    public static function decode(string $buffer, int $offset = 0, int $maxFrameSize = self::MIN_MAX_FRAME_SIZE): ?array
    {
        if (strlen($buffer) - $offset < self::HEADER_SIZE) {
            return null;
        }

        $length = (ord($buffer[$offset]) << 16)
            | (ord($buffer[$offset + 1]) << 8)
            | ord($buffer[$offset + 2]);

        if ($length > $maxFrameSize) {
            throw Http2Exception::frameSize('帧长度 ' . $length . ' 超过 SETTINGS_MAX_FRAME_SIZE');
        }

        $total = self::HEADER_SIZE + $length;
        if (strlen($buffer) - $offset < $total) {
            return null;
        }

        $p = $offset + 5;
        $stream = ((ord($buffer[$p]) << 24)
            | (ord($buffer[$p + 1]) << 16)
            | (ord($buffer[$p + 2]) << 8)
            | ord($buffer[$p + 3])) & 0x7FFFFFFF;

        return [
            'type'    => ord($buffer[$offset + 3]),
            'flags'   => ord($buffer[$offset + 4]),
            'stream'  => $stream,
            'payload' => $length === 0 ? '' : substr($buffer, $offset + self::HEADER_SIZE, $length),
            'size'    => $total,
        ];
    }

    /**
     * 剥离 PADDED 填充（DATA / HEADERS 通用，RFC 7540 §6.1、§6.2）。
     *
     * @throws Http2Exception 填充长度大于剩余负载
     */
    public static function stripPadding(string $payload, int $streamId): string
    {
        if ($payload === '') {
            throw Http2Exception::protocol('PADDED 帧缺少填充长度字节', $streamId);
        }

        $padLength = ord($payload[0]);
        $rest      = strlen($payload) - 1;

        if ($padLength > $rest) {
            throw Http2Exception::protocol('填充长度超过负载', $streamId);
        }

        return substr($payload, 1, $rest - $padLength);
    }

    /**
     * 构造一个完整的 SETTINGS 帧（9 字节帧头 + 6N 字节负载）。
     *
     * 注意与 {@see decodeSettings()} 并非对称关系：本方法返回**整帧**，
     * 可直接写入发送缓冲；而 decodeSettings() 只吃 {@see decode()} 取出的
     * **纯负载**。round-trip 写法为 decodeSettings(decode(settings($x))['payload'])。
     *
     * @param array<int, int> $settings 设置项 ID => 值
     */
    public static function settings(array $settings): string
    {
        $payload = '';
        foreach ($settings as $id => $value) {
            $payload .= pack('nN', $id, $value);
        }

        return self::encode(self::TYPE_SETTINGS, 0, 0, $payload);
    }

    /**
     * 解析 SETTINGS 帧的**纯负载**（不含 9 字节帧头，见 {@see settings()}）。
     *
     * @return array<int, int>
     * @throws Http2Exception 负载长度不是 6 的整数倍
     */
    public static function decodeSettings(string $payload): array
    {
        $len = strlen($payload);
        if ($len % 6 !== 0) {
            throw Http2Exception::frameSize('SETTINGS 负载长度必须为 6 的倍数');
        }

        $out = [];
        for ($i = 0; $i < $len; $i += 6) {
            // 直接用 ord 拼装，避免 unpack 的格式串解析、substr 切片与每次返回的数组分配。
            $id    = (ord($payload[$i]) << 8) | ord($payload[$i + 1]);
            $value = ((ord($payload[$i + 2]) << 24)
                    | (ord($payload[$i + 3]) << 16)
                    | (ord($payload[$i + 4]) << 8)
                    | ord($payload[$i + 5])) & 0x7FFFFFFF;
            $out[$id] = $value;
        }

        return $out;
    }

    /** SETTINGS ACK（空负载 + ACK 标志） */
    public static function settingsAck(): string
    {
        return self::encode(self::TYPE_SETTINGS, self::FLAG_ACK, 0);
    }

    /** RST_STREAM（4 字节错误码） */
    public static function rstStream(int $streamId, int $errorCode): string
    {
        return self::encode(self::TYPE_RST_STREAM, 0, $streamId, pack('N', $errorCode));
    }

    /** GOAWAY（最后处理的流 ID + 错误码 + 可选调试信息） */
    public static function goaway(int $lastStreamId, int $errorCode, string $debug = ''): string
    {
        return self::encode(
            self::TYPE_GOAWAY,
            0,
            0,
            pack('NN', $lastStreamId & 0x7FFFFFFF, $errorCode) . $debug
        );
    }

    /** WINDOW_UPDATE（4 字节窗口增量） */
    public static function windowUpdate(int $streamId, int $increment): string
    {
        return self::encode(self::TYPE_WINDOW_UPDATE, 0, $streamId, pack('N', $increment & 0x7FFFFFFF));
    }

    /** PING ACK：原样回送 8 字节负载 */
    public static function pingAck(string $payload): string
    {
        return self::encode(self::TYPE_PING, self::FLAG_ACK, 0, $payload);
    }

    /** 帧类型的可读名称（日志与异常信息用） */
    public static function typeName(int $type): string
    {
        return match ($type) {
            self::TYPE_DATA          => 'DATA',
            self::TYPE_HEADERS       => 'HEADERS',
            self::TYPE_PRIORITY      => 'PRIORITY',
            self::TYPE_RST_STREAM    => 'RST_STREAM',
            self::TYPE_SETTINGS      => 'SETTINGS',
            self::TYPE_PUSH_PROMISE  => 'PUSH_PROMISE',
            self::TYPE_PING          => 'PING',
            self::TYPE_GOAWAY        => 'GOAWAY',
            self::TYPE_WINDOW_UPDATE => 'WINDOW_UPDATE',
            self::TYPE_CONTINUATION  => 'CONTINUATION',
            default                  => 'UNKNOWN(0x' . dechex($type) . ')',
        };
    }
}
