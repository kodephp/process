<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Hpack;
use Kode\Process\Protocol\Http2\Http2Exception;
use PHPUnit\Framework\TestCase;

/**
 * HPACK（RFC 7541）头压缩测试。
 *
 * 编解码向量直接取自 RFC 7541 附录 C，逐字节比对可确认实现与标准一致，
 * 而不只是「自己编的自己能解」。
 */
final class HpackTest extends TestCase
{
    /** 十六进制字面量转二进制，便于直接抄写 RFC 里的向量 */
    private static function hex(string $hex): string
    {
        $bin = hex2bin(str_replace(' ', '', $hex));
        self::assertIsString($bin);

        return $bin;
    }

    // --------------------------------------------------------- Huffman

    /**
     * RFC 7541 附录 C.4 / C.6 中出现的 Huffman 串。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function huffmanVectors(): array
    {
        return [
            'www.example.com' => ['www.example.com', 'f1e3c2e5f23a6ba0ab90f4ff'],
            'no-cache'        => ['no-cache', 'a8eb10649cbf'],
            'custom-key'      => ['custom-key', '25a849e95ba97d7f'],
            'custom-value'    => ['custom-value', '25a849e95bb8e8b4bf'],
            'private'         => ['private', 'aec3771a4b'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('huffmanVectors')]
    public function testHuffmanEncodeMatchesRfcVector(string $plain, string $hex): void
    {
        $this->assertSame(self::hex($hex), Hpack::huffmanEncode($plain));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('huffmanVectors')]
    public function testHuffmanDecodeMatchesRfcVector(string $plain, string $hex): void
    {
        $this->assertSame($plain, Hpack::huffmanDecode(self::hex($hex)));
    }

    public function testHuffmanRoundTripOnBinarySafeInput(): void
    {
        // 覆盖全字节域，含需要 >8 位编码的高位字符
        $raw = '';
        for ($i = 0; $i < 256; $i++) {
            $raw .= chr($i);
        }

        $this->assertSame($raw, Hpack::huffmanDecode(Hpack::huffmanEncode($raw)));
    }

    public function testHuffmanHandlesEmptyString(): void
    {
        $this->assertSame('', Hpack::huffmanEncode(''));
        $this->assertSame('', Hpack::huffmanDecode(''));
    }

    // ---------------------------------------------------------- 解码

    public function testDecodeIndexedHeaderField(): void
    {
        // RFC 7541 C.2.4：0x82 → 静态表索引 2 → :method: GET
        $this->assertSame(
            [[':method', 'GET']],
            (new Hpack())->decode(self::hex('82'))
        );
    }

    public function testDecodeLiteralWithIncrementalIndexing(): void
    {
        // RFC 7541 C.2.1：custom-key: custom-header，且应进入动态表
        $hpack  = new Hpack();
        $result = $hpack->decode(self::hex('400a637573746f6d2d6b65790d637573746f6d2d686561646572'));

        $this->assertSame([['custom-key', 'custom-header']], $result);
        $this->assertSame(1, $hpack->dynamicCount(), '增量索引必须写入动态表');
        // 条目大小 = 名长 + 值长 + 32（RFC 7541 §4.1）
        $this->assertSame(10 + 13 + 32, $hpack->dynamicSize());
    }

    public function testDecodeLiteralWithoutIndexingLeavesTableEmpty(): void
    {
        // RFC 7541 C.2.2：:path: /sample/path，不索引
        $hpack  = new Hpack();
        $result = $hpack->decode(self::hex('040c2f73616d706c652f70617468'));

        $this->assertSame([[':path', '/sample/path']], $result);
        $this->assertSame(0, $hpack->dynamicCount(), '不索引字面量不得写入动态表');
    }

    public function testDecodeNeverIndexedLiteral(): void
    {
        // RFC 7541 C.2.3：password: secret，从不索引
        $hpack  = new Hpack();
        $result = $hpack->decode(self::hex('100870617373776f726406736563726574'));

        $this->assertSame([['password', 'secret']], $result);
        $this->assertSame(0, $hpack->dynamicCount());
    }

    public function testDecodeFullRequestWithHuffman(): void
    {
        // RFC 7541 C.4.1：首个请求头块（Huffman 编码的 authority）
        // 82 :method GET / 86 :scheme http / 84 :path / / 41 增量索引 :authority
        $hpack  = new Hpack();
        $result = $hpack->decode(self::hex('828684418cf1e3c2e5f23a6ba0ab90f4ff'));

        $this->assertSame([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/'],
            [':authority', 'www.example.com'],
        ], $result);

        // C.4.1 明确要求解码后动态表含 :authority，条目大小 10 + 15 + 32 = 57
        $this->assertSame(1, $hpack->dynamicCount());
        $this->assertSame(57, $hpack->dynamicSize());
    }

    public function testDecodePreservesDuplicateHeaders(): void
    {
        // 同名头必须保序保重复（cookie 拼接等语义由上层决定，压缩层不得去重）
        $hpack = new Hpack();
        $block = $hpack->encode([['accept', 'a'], ['accept', 'b']]);

        $this->assertSame([['accept', 'a'], ['accept', 'b']], $hpack->decode($block));
    }

    // ------------------------------------------------------- 解码错误

    public function testDecodeRejectsZeroIndex(): void
    {
        $this->expectException(Http2Exception::class);
        (new Hpack())->decode(self::hex('80')); // 索引 0 非法
    }

    public function testDecodeRejectsOutOfRangeIndex(): void
    {
        $this->expectException(Http2Exception::class);
        (new Hpack())->decode(self::hex('ff00')); // 远超静态表 + 空动态表
    }

    public function testDecodeRejectsOversizedTableUpdate(): void
    {
        $hpack = new Hpack(4096);

        $this->expectException(Http2Exception::class);
        // 001xxxxx 动态表大小更新，请求 8192 > 上限 4096
        $hpack->decode(self::hex('3fe13f'));
    }

    /**
     * 内联 readStringInline 后，字面量头名（nameIndex=0）读取的「整段缺失」分支
     * 必须仍抛字符串截断错误——锁定主循环内联实现与原方法行为一致。
     */
    public function testInlineStringReadRejectsTruncatedLiteralName(): void
    {
        $this->expectException(Http2Exception::class);
        // 0x40 增量索引 + nameIndex=0（字面量头名），但头名字符串字节完全缺失
        (new Hpack())->decode(self::hex('40'));
    }

    /**
     * 内联 readStringInline 后，字面量头名（nameIndex=0）读取的「声明长度越界」分支
     * 必须仍抛字符串长度越界错误。
     */
    public function testInlineStringReadRejectsLiteralNameLengthOverflow(): void
    {
        $this->expectException(Http2Exception::class);
        // 0x40 增量索引 + nameIndex=0，头名声明长度 3 但后续无字节
        (new Hpack())->decode(self::hex('4003'));
    }

    /**
     * 内联 readStringInline 后，字面量头名(nameIndex=0)与头值同时走内联读取，
     * 且覆盖 Huffman 与非 Huffman 两种字符串编码，确保四条内联路径行为不变。
     */
    public function testInlineStringReadRoundTripsLiteralNameAndValue(): void
    {
        $hpack  = new Hpack();
        $block  = $hpack->encode([['x-custom-name', 'x-custom-value']]);
        $this->assertSame([['x-custom-name', 'x-custom-value']], $hpack->decode($block));

        $plain  = new Hpack();
        $block2 = $plain->encode([['x-plain', 'short']]);
        $this->assertSame([['x-plain', 'short']], $plain->decode($block2));

        $never  = new Hpack();
        $block3 = $never->encode([['x-token', 'abc123']]);
        $this->assertSame([['x-token', 'abc123']], $never->decode($block3));
    }

    // ---------------------------------------------------------- 编码

    public function testEncodeUsesStaticTablePairIndex(): void
    {
        // :status 200 是静态表第 8 项，应压成单字节 0x88
        $this->assertSame(self::hex('88'), (new Hpack())->encode([[':status', '200']]));
    }

    public function testEncodeKeepsEncoderDynamicTableEmpty(): void
    {
        // 编码器刻意无状态：避免动态表同步风险，也让热路径零额外分配
        $hpack = new Hpack();
        $hpack->encode([
            [':status', '200'],
            ['content-type', 'text/plain'],
            ['server', 'kode'],
            ['set-cookie', 'sid=abc'],
        ]);

        $this->assertSame(0, $hpack->dynamicCount(), '编码器不应写入动态表');
        $this->assertSame(0, $hpack->dynamicSize());
    }

    public function testEncodeDecodeRoundTripOnTypicalResponse(): void
    {
        $headers = [
            [':status', '200'],
            ['content-type', 'application/json; charset=utf-8'],
            ['content-length', '1234'],
            ['server', 'kode-process'],
            ['set-cookie', 'sid=abc; Path=/; HttpOnly'],
            ['x-trace-id', '0f7a1c9e-1d2b-4f60-9b3a-5c7e8d9f0a1b'],
        ];

        $this->assertSame($headers, (new Hpack())->decode((new Hpack())->encode($headers)));
    }

    public function testEncodeHandlesEmptyValue(): void
    {
        $headers = [['x-empty', '']];

        $this->assertSame($headers, (new Hpack())->decode((new Hpack())->encode($headers)));
    }

    public function testEncodeHandlesLongValueCrossingIntegerPrefix(): void
    {
        // 值长 > 127 会触发 RFC 7541 §5.1 多字节变长整数编码
        $headers = [['x-long', str_repeat('k', 5000)]];

        $this->assertSame($headers, (new Hpack())->decode((new Hpack())->encode($headers)));
    }

    // ------------------------------------------------------- 动态表管理

    public function testShrinkingMaxSizeEvictsOldestEntries(): void
    {
        $hpack = new Hpack(4096);
        // 三条增量索引字面量，各占 32 + 名 + 值
        $hpack->decode(self::hex('400a637573746f6d2d6b65790d637573746f6d2d686561646572'));
        $this->assertSame(1, $hpack->dynamicCount());

        $hpack->setMaxDynamicSize(0);

        $this->assertSame(0, $hpack->dynamicCount(), '容量降为 0 必须驱逐全部条目');
        $this->assertSame(0, $hpack->dynamicSize());
        $this->assertSame(0, $hpack->maxDynamicSize());
    }

    public function testDynamicEntryIsAddressableAfterInsertion(): void
    {
        $hpack = new Hpack();
        // 先插入 custom-key: custom-header（动态表索引 62）
        $hpack->decode(self::hex('400a637573746f6d2d6b65790d637573746f6d2d686561646572'));

        // 0xbe = 索引 62，指向刚插入的动态表条目
        $this->assertSame(
            [['custom-key', 'custom-header']],
            $hpack->decode(self::hex('be'))
        );
    }

    // --------------------------------------------------- 字面量编码缓存

    /**
     * 缓存只省去重复计算，绝不改变线格式：命中缓存、跨实例、清空后重算必须
     * 产出逐字节一致的编码，且仍可被对端解码回原头。
     */
    public function testLiteralCacheProducesIdenticalWireFormat(): void
    {
        $headers = [
            [':status', '200'],
            ['content-type', 'application/json; charset=utf-8'],
            ['content-length', '1234'],
            ['cache-control', 'no-cache'],
            ['server', 'kode-process'],
            ['date', 'Mon, 08 Aug 2026 00:00:00 GMT'],
        ];

        Hpack::clearLiteralCache();
        $first = (new Hpack())->encode($headers);

        // 第二次编码命中缓存
        $second = (new Hpack())->encode($headers);
        $this->assertSame($first, $second, '缓存命中后线格式必须一致');

        // 不同实例、清空缓存后重新编码：仍须完全一致
        Hpack::clearLiteralCache();
        $third = (new Hpack())->encode($headers);
        $this->assertSame($first, $third, '缓存只影响计算量，不影响编码结果');

        $this->assertSame($headers, (new Hpack())->decode($first));
    }

    /**
     * 字面量缓存设有上限（LITERAL_CACHE_LIMIT），面对海量不同值时不得无限增长。
     */
    public function testLiteralCacheIsBounded(): void
    {
        Hpack::clearLiteralCache();

        for ($i = 0; $i < 5000; $i++) {
            (new Hpack())->encode([['x-uniq', 'value-' . $i]]);
        }

        $prop = (new \ReflectionClass(Hpack::class))->getProperty('literalCache');
        $prop->setAccessible(true);
        $this->assertLessThanOrEqual(
            1024,
            count($prop->getValue()),
            '字面量缓存不得超过上限'
        );

        Hpack::clearLiteralCache();
    }

    /**
     * Huffman 解码缓存必须完全透明：命中与否解出的明文一律一致。
     */
    public function testHuffmanCacheDecodesIdentically(): void
    {
        Hpack::clearHuffmanCache();

        $samples = [
            'www.example.com',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'application/json; charset=utf-8',
            'gzip, deflate, br',
            '/api/v1/users?page=2&size=20',
            '',
        ];

        foreach ($samples as $plain) {
            $encoded = Hpack::huffmanEncode($plain);

            $first = Hpack::huffmanDecode($encoded);   // 冷启动，实算
            $hit   = Hpack::huffmanDecode($encoded);   // 命中缓存

            $this->assertSame($plain, $first, 'Huffman 解码结果必须还原原文');
            $this->assertSame($first, $hit, '缓存命中不得改变解码结果');

            Hpack::clearHuffmanCache();
            $this->assertSame($plain, Hpack::huffmanDecode($encoded), '清空缓存后重算结果一致');
        }

        Hpack::clearHuffmanCache();
    }

    /**
     * 非法 Huffman 输入必须抛异常，且不得被写入缓存（否则攻击者可用畸形数据占位）。
     */
    public function testHuffmanCacheDoesNotStoreInvalidInput(): void
    {
        Hpack::clearHuffmanCache();

        // 尾部填充必须全 1，这里故意给 0 位填充
        $invalid = "\xff\xff\xff\xf0";

        for ($i = 0; $i < 2; $i++) {
            try {
                Hpack::huffmanDecode($invalid);
                $this->fail('非法 Huffman 输入必须抛 Http2Exception');
            } catch (Http2Exception) {
                // 预期
            }
        }

        $prop = (new \ReflectionClass(Hpack::class))->getProperty('huffmanCache');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue(), '非法输入不得进入缓存');

        Hpack::clearHuffmanCache();
    }

    /**
     * Huffman 解码缓存设有条目上限，面对海量不同值时不得无限增长。
     */
    public function testHuffmanCacheIsBounded(): void
    {
        Hpack::clearHuffmanCache();

        for ($i = 0; $i < 5000; $i++) {
            Hpack::huffmanDecode(Hpack::huffmanEncode('uniq-value-' . $i));
        }

        $prop = (new \ReflectionClass(Hpack::class))->getProperty('huffmanCache');
        $prop->setAccessible(true);
        $this->assertLessThanOrEqual(
            1024,
            count($prop->getValue()),
            'Huffman 解码缓存不得超过上限'
        );

        Hpack::clearHuffmanCache();
    }

    /**
     * 超长编码值不进缓存：这类值多为一次性大头，重复率低却占内存。
     */
    public function testHuffmanCacheSkipsOversizedInput(): void
    {
        Hpack::clearHuffmanCache();

        $big     = str_repeat('a', 4096);
        $encoded = Hpack::huffmanEncode($big);
        $this->assertGreaterThan(512, strlen($encoded), '样本编码后需超过缓存长度上限');

        $this->assertSame($big, Hpack::huffmanDecode($encoded));

        $prop = (new \ReflectionClass(Hpack::class))->getProperty('huffmanCache');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue(), '超长输入不得进入缓存');

        Hpack::clearHuffmanCache();
    }

    /**
     * 整头缓存命中与冷启动产出逐字节一致；清空后重算仍一致（纯函数缓存不影响线格式）。
     */
    public function testHeaderCacheProducesIdenticalWireFormat(): void
    {
        $headers = [
            [':status', '200'], ['content-type', 'text/html; charset=utf-8'],
            ['cache-control', 'no-cache'], ['server', 'kode/5.2.6'],
        ];

        Hpack::clearHeaderCache();

        $first = (new Hpack())->encode($headers);          // 冷启动，逐头实算并写入整头缓存
        $second = (new Hpack())->encode($headers);         // 命中整头缓存
        $this->assertSame($first, $second, '整头缓存命中与冷启动字节一致');

        Hpack::clearHeaderCache();
        $third = (new Hpack())->encode($headers);          // 清空后走静态表 + 字面量路径
        $this->assertSame($first, $third, '清空整头缓存后重算结果一致');
        $this->assertSame($headers, (new Hpack())->decode($first));

        Hpack::clearHeaderCache();
    }

    /**
     * 同名不同值的头不得相互污染：整头缓存以 (name, value) 为键，逐头独立。
     */
    public function testHeaderCacheDoesNotCollideOnSameName(): void
    {
        Hpack::clearHeaderCache();

        $a = (new Hpack())->encode([['x-dup', 'alpha']]);
        $b = (new Hpack())->encode([['x-dup', 'beta']]);
        $this->assertNotSame($a, $b, '同名不同值应编码为不同字节');
        $this->assertSame([['x-dup', 'alpha']], (new Hpack())->decode($a));
        $this->assertSame([['x-dup', 'beta']], (new Hpack())->decode($b));

        Hpack::clearHeaderCache();
    }

    /**
     * 整头缓存达到上限后停止写入（与 literalCache 对称），避免内存增长。
     */
    public function testHeaderCacheIsBounded(): void
    {
        Hpack::clearHeaderCache();

        for ($i = 0; $i < 5000; $i++) {
            (new Hpack())->encode([['x-uniq', 'value-' . $i]]);
        }

        $prop = (new \ReflectionClass(Hpack::class))->getProperty('headerCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        $entries = 0;
        foreach ($cache as $byValue) {
            $entries += count($byValue);
        }
        $this->assertLessThanOrEqual(1024, $entries, '整头缓存不得超过上限');

        Hpack::clearHeaderCache();
    }

    // ------------------------------------------- 安全：变长整数（RFC 7541 §5.1）

    /**
     * 变长整数的续接字节串没有位移上限时，$shift 会一路涨到 63 以上，
     * `($b & 0x7f) << $shift` 溢出成负数，$value 随之为负；负索引让
     * `STATIC_TABLE[$value]` 取到 null，解码器于是**不抛异常地**吐出一条
     * `[NULL, NULL]` 头部。这是静默数据损坏，比死循环更危险——上层拿到的
     * 是一条看似合法、内容却是 null 的头。修复后必须是受控的 COMPRESSION_ERROR。
     */
    public function testOverlongVarintThrowsInsteadOfSilentlyCorruptingHeader(): void
    {
        // 索引头字段前缀饱和（0xff）+ 41 个续接字节，足以把 $shift 推过 63
        $block = "\xff" . str_repeat("\xff", 40) . "\x00";

        try {
            $out = (new Hpack())->decode($block);
            self::fail(sprintf(
                '超长变长整数必须抛 Http2Exception，实际静默返回了 %d 条头部：%s',
                count($out),
                var_export($out, true)
            ));
        } catch (Http2Exception $e) {
            self::assertSame(Frame::ERROR_COMPRESSION, $e->errorCode());
            self::assertStringContainsString('变长整数过长', $e->getMessage());
        }
    }

    /**
     * 续接字节跑出头块末尾时必须判为截断。
     *
     * 修复前循环里直接 `ord($block[$i])` 读越界偏移：PHP 只发一条 Warning 并返回 ""，
     * `ord("")` 得 0，于是「高位为 0」让循环正常收尾，截断的头块被当成合法输入继续解析。
     */
    public function testTruncatedVarintContinuationThrows(): void
    {
        // 不索引字面量 → 头名字面量长度前缀饱和（0x7f），续接字节缺失
        $block = "\x00\x7f";

        $this->expectException(Http2Exception::class);
        $this->expectExceptionMessage('变长整数截断');
        (new Hpack())->decode($block);
    }

    /** 合法的多字节变长整数不得被上限误伤（RFC 7541 §5.1 的 1337 用例） */
    public function testLegitimateMultiByteVarintStillDecodes(): void
    {
        // 动态表大小更新到 1337：001 前缀 + 5 位饱和 + 续接 0x9a 0x0a
        $hpack = new Hpack(4096);
        $hpack->decode(self::hex('3f 9a 0a'));

        self::assertSame(1337, $hpack->maxDynamicSize());
    }

    // ----------------------------------------------- 安全：Huffman 边界

    /**
     * 畸形 Huffman 串必须抛 Http2Exception，绝不能抛 ArithmeticError。
     *
     * 根因：热路径 `while ($curBits >= 8)` 在 decodeLong() 返回 false 时 break，
     * 而最短长码为 10 位，故 $curBits 为 8 或 9 时必然 break；随后收尾循环按
     * 「剩余 < 8 位」的假设算 `1 << (8 - $curBits)`，$curBits = 9 即负位移。
     * ArithmeticError 不是 Http2Exception，会穿透会话层的 catch 直接打死 worker。
     * 仅 2 字节即可触发。
     */
    public function testTruncatedHuffmanCodeThrowsHttp2ExceptionNotArithmeticError(): void
    {
        try {
            Hpack::huffmanDecode("\x07\xfd");
            self::fail('截断的 Huffman 码字必须抛 Http2Exception');
        } catch (Http2Exception $e) {
            self::assertSame(Frame::ERROR_COMPRESSION, $e->errorCode());
        } catch (\ArithmeticError $e) {
            self::fail('抛出了 ArithmeticError（会打死 worker）而非 Http2Exception：' . $e->getMessage());
        }
    }

    /** 同样的畸形串走完整头块解码路径，也必须收敛为 Http2Exception */
    public function testTruncatedHuffmanInsideHeaderBlockDoesNotEscapeAsError(): void
    {
        // 不索引字面量：头名 "a"（原文），头值 Huffman 编码且内容为 07 fd
        $block = "\x00\x01a\x82\x07\xfd";

        $this->expectException(Http2Exception::class);
        (new Hpack())->decode($block);
    }

    /**
     * 两字节输入全穷举：任何畸形组合都不得逃逸出 Http2Exception 之外的错误。
     * 这是对「解码器只会抛 Http2Exception」这一契约的强断言。
     */
    public function testHuffmanDecoderNeverEscapesNonHttp2Exception(): void
    {
        $escaped = [];

        for ($a = 0; $a < 256; $a++) {
            for ($b = 0; $b < 256; $b++) {
                try {
                    Hpack::huffmanDecode(chr($a) . chr($b));
                } catch (Http2Exception) {
                    // 预期内
                } catch (\Throwable $e) {
                    $escaped[] = sprintf('%02x%02x => %s', $a, $b, get_class($e));
                }
            }
        }

        self::assertSame([], $escaped, '这些输入逃逸出了非 Http2Exception 的错误');
    }

    // --------------------------------------- 安全：解压炸弹（HPACK bomb）

    /**
     * 构造一个「解压炸弹」头块：先用增量索引往动态表塞一条大条目，
     * 再用大量单字节索引引用把它反复展开。
     */
    private static function bomb(int $valueSize, int $references): string
    {
        // 增量索引 + 新名字："x"，值为 $valueSize 字节
        $block = "\x40" . "\x01x" . self::literalLength($valueSize) . str_repeat('A', $valueSize);

        // 索引头字段，索引 62 = 动态表首项
        return $block . str_repeat("\xbe", $references);
    }

    /** 字符串字面量的长度前缀（7 位前缀、不使用 Huffman） */
    private static function literalLength(int $length): string
    {
        if ($length < 0x7f) {
            return chr($length);
        }

        $out     = "\x7f";
        $length -= 0x7f;
        while ($length >= 0x80) {
            $out    .= chr(($length & 0x7f) | 0x80);
            $length >>= 7;
        }

        return $out . chr($length);
    }

    /**
     * 超过 maxListSize 后解码器必须**停止向输出列表追加**，把内存钳住。
     *
     * 攻击面：62KB 全是索引字节的头块，每个字节展开成一条 4KB 的头，
     * 解压后列表可达数百 MB。这里用很小的上限触发同一条代码路径，不真的分配大内存。
     */
    public function testDecodeStopsAccumulatingOnceHeaderListLimitExceeded(): void
    {
        $references = 2000;
        $block      = self::bomb(1000, $references);

        $exceeded = false;
        $out      = (new Hpack(8192))->decode($block, 5000, $exceeded);

        self::assertTrue($exceeded, '超限必须置位 $exceeded');
        // 每条引用计 1(name) + 1000(value) + 32 = 1033 字节，5000 上限最多容下 4 条
        self::assertLessThanOrEqual(
            10,
            count($out),
            sprintf('超限后必须停止追加，实际累积了 %d 条（共发了 %d 条引用）', count($out), $references)
        );
    }

    /**
     * 关键：超限时**不能**中途放弃解码。
     *
     * HPACK 是连接级有状态编码，提前退出会漏掉后续的增量索引指令，让本端动态表与
     * 对端编码器永久失步，之后每个头块都会解错。所以必须解完整块、只是不再累积输出。
     */
    public function testOversizedHeaderListStillAdvancesHpackContext(): void
    {
        $hpack = new Hpack(8192);

        // 头块尾部再追加一条增量索引：只有真的解完整块，它才会进动态表
        $block  = self::bomb(1000, 500);
        $block .= "\x40" . "\x06second" . "\x02v2";

        $exceeded = false;
        $hpack->decode($block, 5000, $exceeded);

        self::assertTrue($exceeded);
        self::assertSame(2, $hpack->dynamicCount(), '超限也必须解完整块，动态表要收下两条');

        // 上下文完好：索引 62 应当解出最后插入的 second
        self::assertSame([['second', 'v2']], $hpack->decode("\xbe"));
    }

    /** 不传上限时行为与修复前完全一致，不得误伤正常解码 */
    public function testDecodeWithoutLimitIsUnaffected(): void
    {
        $out = (new Hpack(8192))->decode(self::bomb(100, 50));

        self::assertCount(51, $out, '未设上限时应完整返回全部头部');
        self::assertSame(['x', str_repeat('A', 100)], $out[50]);
    }
}
