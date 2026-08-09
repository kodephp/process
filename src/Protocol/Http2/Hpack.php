<?php

declare(strict_types=1);

namespace Kode\Process\Protocol\Http2;

/**
 * HPACK 头部压缩（RFC 7541）。
 *
 * 一个 HTTP/2 连接的两个方向各持有一份独立的 HPACK 上下文（编码器 / 解码器各一个
 * 动态表），因此本类是**有状态**的：每条连接必须各自 new，不可跨连接复用。
 *
 * 实现范围：
 *  - 静态表 61 项（附录 A）、动态表（先进先出，按 RFC 7541 §4.1 计算条目开销 32 字节）
 *  - 整数变长编码（§5.1）、字符串字面量（§5.2，支持 Huffman）
 *  - 索引头字段（§6.1）、增量索引 / 不索引 / 从不索引字面量（§6.2）、动态表大小更新（§6.3）
 *  - Huffman 编解码（附录 B，表数据由 RFC 原文生成，Kraft 和校验为 1）
 *
 * 解码器对畸形输入一律抛 {@see Http2Exception}，由会话层转为 COMPRESSION_ERROR
 * 并关闭连接——HPACK 上下文一旦损坏，后续所有头块都不可信。
 */
final class Hpack
{
    /** 动态表条目的固定开销（RFC 7541 §4.1） */
    private const int ENTRY_OVERHEAD = 32;

    /**
     * 变长整数（RFC 7541 §5.1）续接字节允许的最大位移。
     *
     * 允许 shift ∈ {0,7,14,21,28}，即最多 5 个续接字节，可表示到约 3.4e10——
     * 远超本实现任何合法整数（头块上限 64KB、动态表索引至多数千），却仍安全落在
     * int64 内，不会溢出。超过即判非法：$shift 无上限时攻击者可用一串 0xFF 让
     * $value 溢出为负，进而使 STATIC_TABLE[负数] 取到 null 而静默产出 [NULL, NULL]。
     */
    private const int MAX_VARINT_SHIFT = 28;

    /**
     * 静态表（RFC 7541 附录 A），索引 1..61。
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public const array STATIC_TABLE = [
        1 => [':authority', ''],
        2 => [':method', 'GET'],
        3 => [':method', 'POST'],
        4 => [':path', '/'],
        5 => [':path', '/index.html'],
        6 => [':scheme', 'http'],
        7 => [':scheme', 'https'],
        8 => [':status', '200'],
        9 => [':status', '204'],
        10 => [':status', '206'],
        11 => [':status', '304'],
        12 => [':status', '400'],
        13 => [':status', '404'],
        14 => [':status', '500'],
        15 => ['accept-charset', ''],
        16 => ['accept-encoding', 'gzip, deflate'],
        17 => ['accept-language', ''],
        18 => ['accept-ranges', ''],
        19 => ['accept', ''],
        20 => ['access-control-allow-origin', ''],
        21 => ['age', ''],
        22 => ['allow', ''],
        23 => ['authorization', ''],
        24 => ['cache-control', ''],
        25 => ['content-disposition', ''],
        26 => ['content-encoding', ''],
        27 => ['content-language', ''],
        28 => ['content-length', ''],
        29 => ['content-location', ''],
        30 => ['content-range', ''],
        31 => ['content-type', ''],
        32 => ['cookie', ''],
        33 => ['date', ''],
        34 => ['etag', ''],
        35 => ['expect', ''],
        36 => ['expires', ''],
        37 => ['from', ''],
        38 => ['host', ''],
        39 => ['if-match', ''],
        40 => ['if-modified-since', ''],
        41 => ['if-none-match', ''],
        42 => ['if-range', ''],
        43 => ['if-unmodified-since', ''],
        44 => ['last-modified', ''],
        45 => ['link', ''],
        46 => ['location', ''],
        47 => ['max-forwards', ''],
        48 => ['proxy-authenticate', ''],
        49 => ['proxy-authorization', ''],
        50 => ['range', ''],
        51 => ['referer', ''],
        52 => ['refresh', ''],
        53 => ['retry-after', ''],
        54 => ['server', ''],
        55 => ['set-cookie', ''],
        56 => ['strict-transport-security', ''],
        57 => ['transfer-encoding', ''],
        58 => ['user-agent', ''],
        59 => ['vary', ''],
        60 => ['via', ''],
        61 => ['www-authenticate', ''],
    ];

    /**
     * Huffman 码字（RFC 7541 附录 B），下标 = 符号（0..255，256=EOS）。
     *
     * @var array<int, int>
     */
    private const array HUFFMAN_CODES = [
        0x00001ff8, 0x007fffd8, 0x0fffffe2, 0x0fffffe3, 0x0fffffe4, 0x0fffffe5,
        0x0fffffe6, 0x0fffffe7, 0x0fffffe8, 0x00ffffea, 0x3ffffffc, 0x0fffffe9,
        0x0fffffea, 0x3ffffffd, 0x0fffffeb, 0x0fffffec, 0x0fffffed, 0x0fffffee,
        0x0fffffef, 0x0ffffff0, 0x0ffffff1, 0x0ffffff2, 0x3ffffffe, 0x0ffffff3,
        0x0ffffff4, 0x0ffffff5, 0x0ffffff6, 0x0ffffff7, 0x0ffffff8, 0x0ffffff9,
        0x0ffffffa, 0x0ffffffb, 0x00000014, 0x000003f8, 0x000003f9, 0x00000ffa,
        0x00001ff9, 0x00000015, 0x000000f8, 0x000007fa, 0x000003fa, 0x000003fb,
        0x000000f9, 0x000007fb, 0x000000fa, 0x00000016, 0x00000017, 0x00000018,
        0x00000000, 0x00000001, 0x00000002, 0x00000019, 0x0000001a, 0x0000001b,
        0x0000001c, 0x0000001d, 0x0000001e, 0x0000001f, 0x0000005c, 0x000000fb,
        0x00007ffc, 0x00000020, 0x00000ffb, 0x000003fc, 0x00001ffa, 0x00000021,
        0x0000005d, 0x0000005e, 0x0000005f, 0x00000060, 0x00000061, 0x00000062,
        0x00000063, 0x00000064, 0x00000065, 0x00000066, 0x00000067, 0x00000068,
        0x00000069, 0x0000006a, 0x0000006b, 0x0000006c, 0x0000006d, 0x0000006e,
        0x0000006f, 0x00000070, 0x00000071, 0x00000072, 0x000000fc, 0x00000073,
        0x000000fd, 0x00001ffb, 0x0007fff0, 0x00001ffc, 0x00003ffc, 0x00000022,
        0x00007ffd, 0x00000003, 0x00000023, 0x00000004, 0x00000024, 0x00000005,
        0x00000025, 0x00000026, 0x00000027, 0x00000006, 0x00000074, 0x00000075,
        0x00000028, 0x00000029, 0x0000002a, 0x00000007, 0x0000002b, 0x00000076,
        0x0000002c, 0x00000008, 0x00000009, 0x0000002d, 0x00000077, 0x00000078,
        0x00000079, 0x0000007a, 0x0000007b, 0x00007ffe, 0x000007fc, 0x00003ffd,
        0x00001ffd, 0x0ffffffc, 0x000fffe6, 0x003fffd2, 0x000fffe7, 0x000fffe8,
        0x003fffd3, 0x003fffd4, 0x003fffd5, 0x007fffd9, 0x003fffd6, 0x007fffda,
        0x007fffdb, 0x007fffdc, 0x007fffdd, 0x007fffde, 0x00ffffeb, 0x007fffdf,
        0x00ffffec, 0x00ffffed, 0x003fffd7, 0x007fffe0, 0x00ffffee, 0x007fffe1,
        0x007fffe2, 0x007fffe3, 0x007fffe4, 0x001fffdc, 0x003fffd8, 0x007fffe5,
        0x003fffd9, 0x007fffe6, 0x007fffe7, 0x00ffffef, 0x003fffda, 0x001fffdd,
        0x000fffe9, 0x003fffdb, 0x003fffdc, 0x007fffe8, 0x007fffe9, 0x001fffde,
        0x007fffea, 0x003fffdd, 0x003fffde, 0x00fffff0, 0x001fffdf, 0x003fffdf,
        0x007fffeb, 0x007fffec, 0x001fffe0, 0x001fffe1, 0x003fffe0, 0x001fffe2,
        0x007fffed, 0x003fffe1, 0x007fffee, 0x007fffef, 0x000fffea, 0x003fffe2,
        0x003fffe3, 0x003fffe4, 0x007ffff0, 0x003fffe5, 0x003fffe6, 0x007ffff1,
        0x03ffffe0, 0x03ffffe1, 0x000fffeb, 0x0007fff1, 0x003fffe7, 0x007ffff2,
        0x003fffe8, 0x01ffffec, 0x03ffffe2, 0x03ffffe3, 0x03ffffe4, 0x07ffffde,
        0x07ffffdf, 0x03ffffe5, 0x00fffff1, 0x01ffffed, 0x0007fff2, 0x001fffe3,
        0x03ffffe6, 0x07ffffe0, 0x07ffffe1, 0x03ffffe7, 0x07ffffe2, 0x00fffff2,
        0x001fffe4, 0x001fffe5, 0x03ffffe8, 0x03ffffe9, 0x0ffffffd, 0x07ffffe3,
        0x07ffffe4, 0x07ffffe5, 0x000fffec, 0x00fffff3, 0x000fffed, 0x001fffe6,
        0x003fffe9, 0x001fffe7, 0x001fffe8, 0x007ffff3, 0x003fffea, 0x003fffeb,
        0x01ffffee, 0x01ffffef, 0x00fffff4, 0x00fffff5, 0x03ffffea, 0x007ffff4,
        0x03ffffeb, 0x07ffffe6, 0x03ffffec, 0x03ffffed, 0x07ffffe7, 0x07ffffe8,
        0x07ffffe9, 0x07ffffea, 0x07ffffeb, 0x0ffffffe, 0x07ffffec, 0x07ffffed,
        0x07ffffee, 0x07ffffef, 0x07fffff0, 0x03ffffee, 0x3fffffff,
    ];

    /**
     * Huffman 码长（位），下标与 {@see HUFFMAN_CODES} 对齐。
     *
     * @var array<int, int>
     */
    private const array HUFFMAN_BITS = [
        13, 23, 28, 28, 28, 28, 28, 28, 28, 24, 30, 28, 28, 30, 28, 28, 28, 28, 28, 28, 28, 28, 30, 28,
        28, 28, 28, 28, 28, 28, 28, 28,  6, 10, 10, 12, 13,  6,  8, 11, 10, 10,  8, 11,  8,  6,  6,  6,
         5,  5,  5,  6,  6,  6,  6,  6,  6,  6,  7,  8, 15,  6, 12, 10, 13,  6,  7,  7,  7,  7,  7,  7,
         7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  7,  8,  7,  8, 13, 19, 13, 14,  6,
        15,  5,  6,  5,  6,  5,  6,  6,  6,  5,  7,  7,  6,  6,  6,  5,  6,  7,  6,  5,  5,  6,  7,  7,
         7,  7,  7, 15, 11, 14, 13, 28, 20, 22, 20, 20, 22, 22, 22, 23, 22, 23, 23, 23, 23, 23, 24, 23,
        24, 24, 22, 23, 24, 23, 23, 23, 23, 21, 22, 23, 22, 23, 23, 24, 22, 21, 20, 22, 22, 23, 23, 21,
        23, 22, 22, 24, 21, 22, 23, 23, 21, 21, 22, 21, 23, 22, 23, 23, 20, 22, 22, 22, 23, 22, 22, 23,
        26, 26, 20, 19, 22, 23, 22, 25, 26, 26, 26, 27, 27, 26, 24, 25, 19, 21, 26, 27, 27, 26, 27, 24,
        21, 21, 26, 26, 28, 27, 27, 27, 20, 24, 20, 21, 22, 21, 21, 23, 22, 22, 25, 25, 24, 24, 26, 23,
        26, 27, 26, 26, 27, 27, 27, 27, 27, 28, 27, 27, 27, 27, 27, 26, 30,
    ];

    /** @var array<int, array{0: int, 1: int}>|null 8 位窗口快速解码表：byte => [symbol, bits] */
    private static ?array $huffFast = null;

    /** @var array<int, array<int, int>>|null 长码查找：bits => [code => symbol] */
    private static ?array $huffLong = null;

    /** @var array<string, int>|null "name\0value" => 静态表索引 */
    private static ?array $staticPairIndex = null;

    /** @var array<string, int>|null name => 最小静态表索引 */
    private static ?array $staticNameIndex = null;

    /**
     * 字面量（头名 / 头值）已编码结果的缓存：键为原始字符串，值为
     * `writeInteger(长度,7,前缀) . 负载` 的最终字节。
     *
     * HPACK 字面量编码是纯函数（只依赖该字符串本身，与动态表状态无关），而真实服务
     * 端响应头组合高度固定，因此同一值会被反复编码。缓存后只在每个进程内首次遇到
     * 某值时计算一次 Huffman，其后直接复用——线格式完全一致，仅省去重复计算。
     * 上限 {@see LITERAL_CACHE_LIMIT} 防止动态值（如每次不同的日期）无限膨胀内存。
     *
     * @var array<string, string>
     */
    private static array $literalCache = [];

    /** 已缓存条目数（达到上限后停止写入，避免抖动与内存增长） */
    private static int $literalCacheCount = 0;

    private const int LITERAL_CACHE_LIMIT = 1024;

    /**
     * 整头（name + value）已编码结果缓存：name => value => 完整编码字节。
     *
     * 与 {@see $literalCache}（仅缓存值）相比，这里缓存「整头」——一次命中即可直接
     * 拼回字节，省去静态表查对、整数编码、字面量编码与多次字符串拼接。稳态下响应头
     * 组合高度固定，命中率极高，且线格式完全不变。上限 {@see HEADER_CACHE_LIMIT}。
     *
     * @var array<string, array<string, string>>
     */
    private static array $headerCache = [];

    /** 已缓存整头数（达到上限后停止写入） */
    private static int $headerCacheCount = 0;

    private const int HEADER_CACHE_LIMIT = 1024;

    /**
     * Huffman 解码结果缓存：键为已编码字节串，值为解码后的原始字符串。
     *
     * 与 {@see $literalCache}（明文 → 编码字节）严格对称，方向相反：Huffman 解码同样
     * 是纯函数，只依赖输入字节本身，与动态表状态无关。真实请求头中的字面量
     * （user-agent / accept / accept-language / cookie 等）在稳态下高度重复，
     * 且客户端对同一值总是产出同一段 Huffman 字节，因此命中率很高。
     *
     * 只缓存解码成功的结果：抛异常的非法输入不会写入，攻击者无法用畸形数据占位。
     * 超长输入（> {@see HUFFMAN_CACHE_MAX_INPUT}）不缓存——这类值多为一次性大头，
     * 重复率低却占内存。
     *
     * @var array<string, string>
     */
    private static array $huffmanCache = [];

    /** 已缓存条目数（达到上限后停止写入） */
    private static int $huffmanCacheCount = 0;

    private const int HUFFMAN_CACHE_LIMIT = 1024;

    /** 单条可缓存的最大编码长度（字节） */
    private const int HUFFMAN_CACHE_MAX_INPUT = 512;

    /**
     * 动态表：下标 0 为最新插入项（对应 HPACK 索引 62）。
     *
     * @var list<array{0: string, 1: string, 2: int}> [name, value, size]
     */
    private array $dynamic = [];

    private int $dynamicSize = 0;

    private int $maxDynamicSize;

    /** 对端通过 SETTINGS_HEADER_TABLE_SIZE 允许的上限（编码方向用） */
    private int $capacityLimit;

    public function __construct(int $maxDynamicSize = 4096)
    {
        $this->maxDynamicSize = $maxDynamicSize;
        $this->capacityLimit  = $maxDynamicSize;
        self::bootTables();
    }

    /** 当前动态表占用字节数（含每条 32 字节固定开销） */
    public function dynamicSize(): int
    {
        return $this->dynamicSize;
    }

    /** 当前动态表条目数 */
    public function dynamicCount(): int
    {
        return count($this->dynamic);
    }

    /**
     * 清空字面量编码缓存（主要用于测试隔离与基准冷启动）。
     * 不影响任何已建立的 HPACK 上下文，仅丢弃可重新计算的缓存。
     */
    public static function clearLiteralCache(): void
    {
        self::$literalCache       = [];
        self::$literalCacheCount  = 0;
    }

    /**
     * 清空 Huffman 解码缓存（主要用于测试隔离与基准冷启动）。
     * 与 {@see clearLiteralCache} 对称，仅丢弃可重新计算的缓存。
     */
    public static function clearHuffmanCache(): void
    {
        self::$huffmanCache      = [];
        self::$huffmanCacheCount = 0;
    }

    /**
     * 清空整头编码缓存（测试隔离用）。仅丢弃可重新计算的缓存，不影响上下文。
     */
    public static function clearHeaderCache(): void
    {
        self::$headerCache      = [];
        self::$headerCacheCount = 0;
    }

    public function maxDynamicSize(): int
    {
        return $this->maxDynamicSize;
    }

    /**
     * 应用对端 SETTINGS_HEADER_TABLE_SIZE：调整容量上限并按需驱逐。
     */
    public function setMaxDynamicSize(int $size): void
    {
        $this->maxDynamicSize = max(0, $size);
        $this->capacityLimit  = $this->maxDynamicSize;
        $this->evict();
    }

    // ------------------------------------------------------------- 解码

    /**
     * 续读变长整数的后续字节（RFC 7541 §5.1）。
     *
     * 仅在前缀位全 1（`$value === $mask`）时调用——前缀未饱和的快路径依旧完全内联在
     * {@see decode} 里，不经过本方法，因此热路径的调用次数不变。
     *
     * 两道硬边界，缺一即为畸形输入：
     *  - **越界**：续接字节必须真实存在。原先直接 `ord($block[$i])` 读越界偏移，PHP 只
     *    发一条 Warning 并返回 ""，`ord("")` 得 0，于是循环「正常」结束，截断的头块被
     *    当作合法输入继续解析。
     *  - **位移上限**：见 {@see MAX_VARINT_SHIFT}。
     *
     * @param int $value 前缀饱和值（即 $mask），续接字节在其之上累加
     * @throws Http2Exception 截断或超长
     */
    private static function readVarintTail(string $block, int &$i, int $len, int $value): int
    {
        $shift = 0;

        do {
            if ($i >= $len) {
                throw Http2Exception::compression('HPACK 变长整数截断');
            }
            if ($shift > self::MAX_VARINT_SHIFT) {
                throw Http2Exception::compression('HPACK 变长整数过长');
            }
            $b      = ord($block[$i]);
            $i++;
            $value += ($b & 0x7f) << $shift;
            $shift += 7;
        } while (($b & 0x80) !== 0);

        return $value;
    }

    /**
     * 解码一个完整头块（HEADERS + 所有 CONTINUATION 的拼接结果）。
     *
     * **解压炸弹防护**：$maxListSize > 0 时，边解码边按 RFC 7541 §4.1 累计
     * 「解压后头列表体积」（name + value + 32），一旦超限就**不再向 $out 追加**，
     * 并置 $exceeded = true。攻击者可用 62KB 全是动态表索引的头块，让每个索引
     * 字节展开成一条 4KB 的头（62000 × 4KB ≈ 241MB）——爆的是输出列表，动态表
     * 本身另有 maxDynamicSize 封顶。停止追加后内存即被钳住。
     *
     * 注意这里**继续解完整个头块**而不是中途抛异常：HPACK 是连接级有状态编码，
     * 提前退出会漏掉后续的增量索引指令，使本端动态表与对端永久失步，之后所有
     * 头块都会解错。解完再由调用方按流级 RST_STREAM 拒绝，连接得以存活。
     *
     * @param int  $maxListSize 解压后头列表上限（0 = 不限）
     * @param bool $exceeded    出参：是否超限（超限时 $out 为截断结果）
     * @return list<array{0: string, 1: string}> 有序的 [name, value] 列表（保留重复头）
     * @throws Http2Exception 头块畸形 / 索引越界 / Huffman 非法
     */
    public function decode(string $block, int $maxListSize = 0, bool &$exceeded = false): array
    {
        $out = [];
        $len = strlen($block);
        $i   = 0;

        $listSize = 0;
        $limit    = $maxListSize > 0 ? $maxListSize : PHP_INT_MAX;

        while ($i < $len) {
            $byte = ord($block[$i]);

            // 6.1 索引头字段：1xxxxxxx（内联 readInteger(7) 与取表，消除方法分发开销）
            if (($byte & 0x80) !== 0) {
                $mask  = 0x7f;
                $value = $byte & $mask;
                $i++;
                if ($value === $mask) {
                    $value = self::readVarintTail($block, $i, $len, $value);
                }
                if ($value === 0) {
                    throw Http2Exception::compression('HPACK 索引 0 非法');
                }
                if ($value <= 61) {
                    $entry = self::STATIC_TABLE[$value];
                } else {
                    $entry = $this->dynamic[$value - 62] ?? null;
                    if ($entry === null) {
                        throw Http2Exception::compression('HPACK 索引越界：' . $value);
                    }
                }
                $listSize += strlen($entry[0]) + strlen($entry[1]) + self::ENTRY_OVERHEAD;
                if ($listSize <= $limit) {
                    $out[] = [$entry[0], $entry[1]];
                }
                continue;
            }

            // 6.3 动态表大小更新：001xxxxx（内联 readInteger(5)）
            if (($byte & 0xE0) === 0x20) {
                $mask  = 0x1f;
                $value = $byte & $mask;
                $i++;
                if ($value === $mask) {
                    $value = self::readVarintTail($block, $i, $len, $value);
                }
                if ($value > $this->capacityLimit) {
                    throw Http2Exception::compression('HPACK 动态表大小更新超过上限');
                }
                $this->maxDynamicSize = $value;
                $this->evict();
                continue;
            }

            // 6.2.1 增量索引字面量：01xxxxxxx（内联 readInteger(6) + readStringInline）
            if (($byte & 0xC0) === 0x40) {
                $mask  = 0x3f;
                $value = $byte & $mask;
                $i++;
                if ($value === $mask) {
                    $value = self::readVarintTail($block, $i, $len, $value);
                }
                $nameIndex = $value;
                if ($nameIndex === 0) {
                    // 内联 readStringInline：读头名字面量（保留边界校验 + Huffman 缓存调用）
                    if ($i >= $len) {
                        throw Http2Exception::compression('HPACK 字符串截断');
                    }
                    $huffman = (ord($block[$i]) & 0x80) !== 0;
                    $strlen  = ord($block[$i]) & 0x7f;
                    $i++;
                    if ($strlen === 0x7f) {
                        $strlen = self::readVarintTail($block, $i, $len, $strlen);
                    }
                    if ($i + $strlen > $len) {
                        throw Http2Exception::compression('HPACK 字符串长度越界');
                    }
                    $raw  = substr($block, $i, $strlen);
                    $i   += $strlen;
                    $name = $huffman ? self::huffmanDecode($raw) : $raw;
                } else {
                    $name = $nameIndex <= 61
                        ? self::STATIC_TABLE[$nameIndex][0]
                        : ($this->dynamic[$nameIndex - 62][0] ?? throw Http2Exception::compression('HPACK 索引越界：' . $nameIndex));
                }
                // 内联 readStringInline：读头值字面量
                if ($i >= $len) {
                    throw Http2Exception::compression('HPACK 字符串截断');
                }
                $huffman = (ord($block[$i]) & 0x80) !== 0;
                $strlen  = ord($block[$i]) & 0x7f;
                $i++;
                if ($strlen === 0x7f) {
                    $strlen = self::readVarintTail($block, $i, $len, $strlen);
                }
                if ($i + $strlen > $len) {
                    throw Http2Exception::compression('HPACK 字符串长度越界');
                }
                $raw      = substr($block, $i, $strlen);
                $i       += $strlen;
                $valueStr = $huffman ? self::huffmanDecode($raw) : $raw;
                // push() 必须无条件执行：动态表是连接级状态，漏一条即与对端失步
                $this->push($name, $valueStr);
                $listSize += strlen($name) + strlen($valueStr) + self::ENTRY_OVERHEAD;
                if ($listSize <= $limit) {
                    $out[] = [$name, $valueStr];
                }
                continue;
            }

            // 6.2.2 不索引 0000xxxx / 6.2.3 从不索引 0001xxxx（内联 readInteger(4) + readStringInline）
            $mask  = 0x0f;
            $value = $byte & $mask;
            $i++;
            if ($value === $mask) {
                $value = self::readVarintTail($block, $i, $len, $value);
            }
            $nameIndex = $value;
            if ($nameIndex === 0) {
                // 内联 readStringInline：读头名字面量
                if ($i >= $len) {
                    throw Http2Exception::compression('HPACK 字符串截断');
                }
                $huffman = (ord($block[$i]) & 0x80) !== 0;
                $strlen  = ord($block[$i]) & 0x7f;
                $i++;
                if ($strlen === 0x7f) {
                    $strlen = self::readVarintTail($block, $i, $len, $strlen);
                }
                if ($i + $strlen > $len) {
                    throw Http2Exception::compression('HPACK 字符串长度越界');
                }
                $raw  = substr($block, $i, $strlen);
                $i   += $strlen;
                $name = $huffman ? self::huffmanDecode($raw) : $raw;
            } else {
                $name = $nameIndex <= 61
                    ? self::STATIC_TABLE[$nameIndex][0]
                    : ($this->dynamic[$nameIndex - 62][0] ?? throw Http2Exception::compression('HPACK 索引越界：' . $nameIndex));
            }
            // 内联 readStringInline：读头值字面量
            if ($i >= $len) {
                throw Http2Exception::compression('HPACK 字符串截断');
            }
            $huffman = (ord($block[$i]) & 0x80) !== 0;
            $strlen  = ord($block[$i]) & 0x7f;
            $i++;
            if ($strlen === 0x7f) {
                $strlen = self::readVarintTail($block, $i, $len, $strlen);
            }
            if ($i + $strlen > $len) {
                throw Http2Exception::compression('HPACK 字符串长度越界');
            }
            $raw      = substr($block, $i, $strlen);
            $i       += $strlen;
            $valueStr = $huffman ? self::huffmanDecode($raw) : $raw;

            $listSize += strlen($name) + strlen($valueStr) + self::ENTRY_OVERHEAD;
            if ($listSize <= $limit) {
                $out[] = [$name, $valueStr];
            }
        }

        $exceeded = $listSize > $limit;

        return $out;
    }

    // ------------------------------------------------------------- 编码

    /**
     * 编码一组响应头。
     *
     * 优先级：静态表整对命中 → 静态表名命中 + 字面值 → 全字面量。除 `set-cookie`
     * 与 `authorization` 外（RFC 7541 §7.1 建议从不索引）均走「不索引字面量」，
     * 从而让编码器动态表保持为空——服务端响应头组合高度固定，索引收益有限，
     * 而无状态编码可完全规避动态表同步风险，也让热路径没有额外分配。
     *
     * @param list<array{0: string, 1: string}> $headers 头名必须已是小写
     */
    public function encode(array $headers): string
    {
        $out = '';

        foreach ($headers as [$name, $value]) {
            // 整头缓存命中：直接拼接已编码字节，省去查表 / 整数编码 / 字面量编码 / 拼接链。
            // 键为 (name, value)，稳态下响应头组合高度固定，命中率极高；线格式逐字节不变。
            $hc = self::$headerCache[$name][$value] ?? null;
            if ($hc !== null) {
                $out .= $hc;
                continue;
            }

            // 静态表整对命中（§6.1）：1xxxxxxx
            $pair = self::$staticPairIndex[$name][$value] ?? null;
            if ($pair !== null) {
                $hc = $this->writeInteger($pair, 7, 0x80);
                $this->storeHeader($name, $value, $hc);
                $out .= $hc;
                continue;
            }

            $neverIndex = $name === 'set-cookie' || $name === 'authorization';
            $prefix     = $neverIndex ? 0x10 : 0x00;

            $nameIndex = self::$staticNameIndex[$name] ?? null;
            if ($nameIndex !== null) {
                $hc = $this->writeInteger($nameIndex, 4, $prefix) . $this->writeString($value);
            } else {
                $hc = chr($prefix) . $this->writeString($name) . $this->writeString($value);
            }
            $this->storeHeader($name, $value, $hc);
            $out .= $hc;
        }

        return $out;
    }

    /**
     * 写入整头缓存（达到上限后停止，避免内存增长）。
     */
    private function storeHeader(string $name, string $value, string $hc): void
    {
        if (self::$headerCacheCount < self::HEADER_CACHE_LIMIT) {
            self::$headerCache[$name][$value] = $hc;
            self::$headerCacheCount++;
        }
    }

    private function writeInteger(int $value, int $prefixBits, int $flags): string
    {
        $mask = (1 << $prefixBits) - 1;

        if ($value < $mask) {
            return chr($flags | $value);
        }

        $out    = chr($flags | $mask);
        $value -= $mask;

        while ($value >= 0x80) {
            $out   .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return $out . chr($value);
    }

    /**
     * 字符串字面量编码：Huffman 更短时用 Huffman，否则原样（RFC 7541 §5.2）。
     *
     * 结果按原始值缓存（见 {@see Hpack::$literalCache}）：同值第二次起直接命中，
     * 省去 Huffman 逐字符编码与长度比较。线格式与无缓存时逐字节相同。
     */
    private function writeString(string $value): string
    {
        if ($value === '') {
            return "\x00";
        }

        if (isset(self::$literalCache[$value])) {
            return self::$literalCache[$value];
        }

        $huffman = self::huffmanEncode($value);
        $out     = strlen($huffman) < strlen($value)
            ? $this->writeInteger(strlen($huffman), 7, 0x80) . $huffman
            : $this->writeInteger(strlen($value), 7, 0x00) . $value;

        if (self::$literalCacheCount < self::LITERAL_CACHE_LIMIT) {
            self::$literalCache[$value] = $out;
            self::$literalCacheCount++;
        }

        return $out;
    }

    // -------------------------------------------------------- 动态表维护

    private function push(string $name, string $value): void
    {
        $size = strlen($name) + strlen($value) + self::ENTRY_OVERHEAD;

        // 单条超过整表容量：清空且不插入（RFC 7541 §4.4）
        if ($size > $this->maxDynamicSize) {
            $this->dynamic     = [];
            $this->dynamicSize = 0;
            return;
        }

        array_unshift($this->dynamic, [$name, $value, $size]);
        $this->dynamicSize += $size;
        $this->evict();
    }

    private function evict(): void
    {
        while ($this->dynamicSize > $this->maxDynamicSize && $this->dynamic !== []) {
            $entry              = array_pop($this->dynamic);
            $this->dynamicSize -= $entry[2];
        }
        if ($this->dynamic === []) {
            $this->dynamicSize = 0;
        }
    }

    // ---------------------------------------------------------- Huffman

    /**
     * Huffman 解码（RFC 7541 附录 B）。
     *
     * 热路径（剩余位数 ≥ 8）走 8 位窗口查表一次命中；长码前缀（8 位窗口不在快表中）
     * 交给 {@see decodeLong}；末尾不足 8 位的填充必须全为 1，否则视为非法。
     * 高频字符（小写字母 / 数字 / 常用符号）码长 ≤ 8 位，全程命中快表。
     */
    public static function huffmanDecode(string $data): string
    {
        // 纯函数缓存：同一段编码字节恒解出同一明文（见 self::$huffmanCache）
        if (isset(self::$huffmanCache[$data])) {
            return self::$huffmanCache[$data];
        }

        self::bootTables();
        $fast    = self::$huffFast;
        $out     = '';
        $cur     = 0;
        $curBits = 0;
        $len     = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $cur      = ($cur << 8) | ord($data[$i]);
            $curBits += 8;

            // 热路径：curBits ≥ 8 时窗口必为 8 位，无需右侧补 1，命中码长必 ≤ curBits
            while ($curBits >= 8) {
                $window = ($cur >> ($curBits - 8)) & 0xFF;
                $hit    = $fast[$window];

                if ($hit !== null) {
                    $out     .= chr($hit[0]);
                    $curBits -= $hit[1];
                    $cur     &= (1 << $curBits) - 1;
                    continue;
                }

                if (!self::decodeLong($cur, $curBits, $out)) {
                    break;
                }
            }
        }

        // 主循环已吃完所有字节却仍剩 ≥ 8 位：这些位无法构成任何码字，属码字截断。
        // 必须在进入收尾循环**之前**拦下：收尾循环按「剩余 < 8 位」设计，$curBits ≥ 9 时
        // (8 - $curBits) 为负，`1 << 负数` 抛 ArithmeticError——它不是 Http2Exception，
        // 会穿透会话层的 catch 直接打死 worker（2 字节 "\x07\xfd" 即可触发）。
        //
        // 之所以能带着 ≥ 8 位走到这里：热路径 while ($curBits >= 8) 在 decodeLong()
        // 返回 false 时 break，而最短长码为 10 位，故 $curBits 为 8 或 9 时必然 break。
        if ($curBits >= 8) {
            throw Http2Exception::compression('Huffman 码字截断');
        }

        // 收尾：剩余 < 8 位，构造补 1 的 8 位窗口（与 RFC 尾部填充一致）
        while ($curBits > 0) {
            $window = (($cur << (8 - $curBits)) | ((1 << (8 - $curBits)) - 1)) & 0xFF;
            $hit    = $fast[$window];

            if ($hit !== null && $hit[1] <= $curBits) {
                $out     .= chr($hit[0]);
                $curBits -= $hit[1];
                $cur     &= (1 << $curBits) - 1;
                continue;
            }

            if (!self::decodeLong($cur, $curBits, $out)) {
                break;
            }
        }

        if ($curBits > 0) {
            if ($curBits > 7) {
                throw Http2Exception::compression('Huffman 码字截断');
            }
            if ($cur !== (1 << $curBits) - 1) {
                throw Http2Exception::compression('Huffman 填充非法');
            }
        }

        // 只有走到这里（未抛异常）才是合法输入，才值得缓存
        if (self::$huffmanCacheCount < self::HUFFMAN_CACHE_LIMIT
            && $len <= self::HUFFMAN_CACHE_MAX_INPUT
        ) {
            self::$huffmanCache[$data] = $out;
            self::$huffmanCacheCount++;
        }

        return $out;
    }

    /**
     * 尝试从当前位缓冲解出一个码长 > 8 的符号。
     *
     * @return bool false 表示位数不足，需要读入更多字节
     */
    private static function decodeLong(int &$cur, int &$curBits, string &$out): bool
    {
        foreach (self::$huffLong as $bits => $map) {
            if ($curBits < $bits) {
                return false;
            }
            $code = $cur >> ($curBits - $bits);
            if (isset($map[$code])) {
                if ($map[$code] === 256) {
                    throw Http2Exception::compression('Huffman 串内出现 EOS');
                }
                $out     .= chr($map[$code]);
                $curBits -= $bits;
                $cur     &= (1 << $curBits) - 1;
                return true;
            }
        }

        throw Http2Exception::compression('Huffman 码字无法解析');
    }

    /** Huffman 编码（RFC 7541 附录 B），尾部用 1 填充到字节边界 */
    public static function huffmanEncode(string $data): string
    {
        $out     = '';
        $cur     = 0;
        $curBits = 0;
        $len     = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $sym      = ord($data[$i]);
            $cur      = ($cur << self::HUFFMAN_BITS[$sym]) | self::HUFFMAN_CODES[$sym];
            $curBits += self::HUFFMAN_BITS[$sym];

            while ($curBits >= 8) {
                $out     .= chr(($cur >> ($curBits - 8)) & 0xFF);
                $curBits -= 8;
            }
            $cur &= (1 << $curBits) - 1;
        }

        if ($curBits > 0) {
            $out .= chr((($cur << (8 - $curBits)) | ((1 << (8 - $curBits)) - 1)) & 0xFF);
        }

        return $out;
    }

    /** 惰性构建查找表（进程内只构建一次） */
    private static function bootTables(): void
    {
        if (self::$huffFast !== null) {
            return;
        }

        $fast = array_fill(0, 256, null);
        $long = [];

        foreach (self::HUFFMAN_BITS as $sym => $bits) {
            $code = self::HUFFMAN_CODES[$sym];

            if ($bits <= 8) {
                $base  = $code << (8 - $bits);
                $count = 1 << (8 - $bits);
                for ($k = 0; $k < $count; $k++) {
                    $fast[$base + $k] = [$sym, $bits];
                }
                continue;
            }

            $long[$bits][$code] = $sym;
        }

        ksort($long);
        self::$huffFast = $fast;
        self::$huffLong = $long;

        $pairs = [];
        $names = [];
        foreach (self::STATIC_TABLE as $index => [$name, $value]) {
            $pairs[$name][$value] = $index;
            if (!isset($names[$name])) {
                $names[$name] = $index;
            }
        }
        self::$staticPairIndex = $pairs;
        self::$staticNameIndex = $names;
    }
}
