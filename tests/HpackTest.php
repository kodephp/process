<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

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
}
