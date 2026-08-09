<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\HttpProtocol;
use PHPUnit\Framework\TestCase;

/**
 * HttpProtocol 分块编码助手（beginChunked / chunkFrame / chunkEnd）与 gzip 压缩单测。
 */
final class HttpProtocolTest extends TestCase
{
    public function testBeginChunkedDefaults(): void
    {
        $head = HttpProtocol::beginChunked();
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $head);
        $this->assertStringContainsString("Transfer-Encoding: chunked\r\n", $head);
        $this->assertStringContainsString("Content-Type: text/html; charset=utf-8\r\n", $head);
        $this->assertStringEndsWith("\r\n\r\n", $head);
    }

    public function testBeginChunkedCustomStatusAndHeaders(): void
    {
        $head = HttpProtocol::beginChunked(404, ['X-Custom' => '1', 'Content-Type' => 'application/json']);
        $this->assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", $head);
        $this->assertStringContainsString("Transfer-Encoding: chunked\r\n", $head);
        // 自定义 Content-Type 不被默认覆盖
        $this->assertStringContainsString("Content-Type: application/json\r\n", $head);
        $this->assertStringContainsString("X-Custom: 1\r\n", $head);
    }

    public function testChunkFrameFormat(): void
    {
        $this->assertSame("5\r\nhello\r\n", HttpProtocol::chunkFrame('hello'));
        $this->assertSame("1\r\na\r\n", HttpProtocol::chunkFrame('a'));
    }

    public function testChunkFrameEmptyIsEmptyString(): void
    {
        $this->assertSame('', HttpProtocol::chunkFrame(''));
    }

    public function testChunkEnd(): void
    {
        $this->assertSame("0\r\n\r\n", HttpProtocol::chunkEnd());
    }

    public function testFullChunkedAssembly(): void
    {
        $wire = HttpProtocol::beginChunked(200, ['Content-Type' => 'text/plain'])
            . HttpProtocol::chunkFrame('foo')
            . HttpProtocol::chunkFrame('bar')
            . HttpProtocol::chunkEnd();

        $expected = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/plain\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "3\r\nfoo\r\n"
            . "3\r\nbar\r\n"
            . "0\r\n\r\n";

        $this->assertSame($expected, $wire);
    }

    // ----------------------------------------------------------- gzip

    public function testEncodeCompressedAddsHeaderAndCanRoundTrip(): void
    {
        $body = str_repeat('hello world ', 100); // > GZIP_MIN_SIZE
        $wire = HttpProtocol::encodeCompressed($body, -1);

        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $wire);

        // 取出压缩后的 Content-Length 与体，验证可 gzdecode 还原
        preg_match('/Content-Length: (\d+)\r\n/', $wire, $m);
        $len = (int)$m[1];
        $payload = substr($wire, -(strlen($wire) - strpos($wire, "\r\n\r\n") - 4));
        $this->assertSame($len, strlen($payload));
        $this->assertSame($body, @gzdecode($payload));
    }

    public function testEncodeCompressedRespectsStatusAndCustomHeaders(): void
    {
        $wire = HttpProtocol::encodeCompressed(
            ['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => str_repeat('x', 2048)]
        );
        $this->assertStringStartsWith("HTTP/1.1 201 Created\r\n", $wire);
        $this->assertStringContainsString("Content-Type: application/json\r\n", $wire);
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
    }

    public function testEncodeCompressedPassesThroughCompleteResponse(): void
    {
        $full = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi";
        $this->assertSame($full, HttpProtocol::encodeCompressed($full));
    }

    public function testAcceptsGzip(): void
    {
        $this->assertTrue(HttpProtocol::acceptsGzip('gzip'));
        $this->assertTrue(HttpProtocol::acceptsGzip('gzip, deflate'));
        $this->assertTrue(HttpProtocol::acceptsGzip('deflate, gzip'));
        $this->assertTrue(HttpProtocol::acceptsGzip('gzip;q=0.8'));
        $this->assertFalse(HttpProtocol::acceptsGzip('gzip;q=0'));
        $this->assertFalse(HttpProtocol::acceptsGzip('gzip;q=0.0'));
        $this->assertFalse(HttpProtocol::acceptsGzip('deflate'));
        $this->assertFalse(HttpProtocol::acceptsGzip(''));
    }

    // ------------------------------------------- input()：请求走私防护

    public function testInputAcceptsWellFormedRequests(): void
    {
        $get = "GET / HTTP/1.1\r\nHost: a\r\n\r\n";
        $this->assertSame(strlen($get), HttpProtocol::input($get));

        $post = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello";
        $this->assertSame(strlen($post), HttpProtocol::input($post));

        // 头值两侧的 OWS 合法，不影响取值
        $ows = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length:  5 \r\n\r\nhello";
        $this->assertSame(strlen($ows), HttpProtocol::input($ows));
    }

    public function testInputWaitsForIncompleteBody(): void
    {
        $partial = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhel";
        $this->assertSame(0, HttpProtocol::input($partial));
    }

    /**
     * CL.TE 走私：CL 为 0 时旧实现认为「无请求体」，把 chunked 体连同其后
     * 伪造的 `GET /admin` 一起留在缓冲区，下一轮循环就被当作独立请求处理。
     */
    public function testInputRejectsContentLengthWithTransferEncoding(): void
    {
        $raw = "POST / HTTP/1.1\r\nHost: a\r\n"
            . "Content-Length: 0\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "5\r\nhello\r\n0\r\n\r\n"
            . "GET /admin HTTP/1.1\r\nHost: a\r\n\r\n";

        $this->assertSame(-1, HttpProtocol::input($raw));
    }

    /** 本库不解析 chunked 请求体，单独出现 TE 同样会让体被当成后续请求 */
    public function testInputRejectsTransferEncodingAlone(): void
    {
        $raw = "POST / HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n";

        $this->assertSame(-1, HttpProtocol::input($raw));
    }

    public function testInputRejectsTransferEncodingRegardlessOfCase(): void
    {
        $raw = "POST / HTTP/1.1\r\nHost: a\r\ntRaNsFeR-eNcOdInG: chunked\r\n\r\n";

        $this->assertSame(-1, HttpProtocol::input($raw));
    }

    /** 旧实现用 (int) 强转，"abc" 静默变成 0，请求体被当作下一个请求 */
    public function testInputRejectsNonNumericContentLength(): void
    {
        foreach (['abc', '5abc', '0x10', '5 6', '', ' '] as $value) {
            $raw = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: {$value}\r\n\r\nhello";

            $this->assertSame(-1, HttpProtocol::input($raw), "Content-Length: '{$value}' 未被拒绝");
        }
    }

    public function testInputRejectsSignedContentLength(): void
    {
        foreach (['-5', '+5', '-0'] as $value) {
            $raw = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: {$value}\r\n\r\nhello";

            $this->assertSame(-1, HttpProtocol::input($raw), "Content-Length: '{$value}' 未被拒绝");
        }
    }

    /** 旧实现只取首个 CL，前后端取值不同就是走私 */
    public function testInputRejectsConflictingContentLengths(): void
    {
        $raw = "POST / HTTP/1.1\r\nHost: a\r\n"
            . "Content-Length: 5\r\n"
            . "Content-Length: 6\r\n"
            . "\r\nhelloX";

        $this->assertSame(-1, HttpProtocol::input($raw));
    }

    public function testInputAcceptsDuplicateIdenticalContentLengths(): void
    {
        $raw = "POST / HTTP/1.1\r\nHost: a\r\n"
            . "Content-Length: 5\r\n"
            . "Content-Length: 5\r\n"
            . "\r\nhello";

        $this->assertSame(strlen($raw), HttpProtocol::input($raw));
    }

    /** 请求体里出现的伪造头不参与判定，扫描范围必须限定在头块内 */
    public function testInputIgnoresHeaderLikeLinesInsideBody(): void
    {
        $body = "Transfer-Encoding: chunked\r\nContent-Length: 999";
        $raw = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;

        $this->assertSame(strlen($raw), HttpProtocol::input($raw));
    }

    // ------------------------------------------- 响应头 CRLF 注入防护

    public function testEncodeStripsCrlfFromHeaderValue(): void
    {
        $wire = HttpProtocol::encode([
            'status' => 302,
            'headers' => ['Location' => "/next\r\nSet-Cookie: admin=1"],
            'body' => '',
        ]);

        $this->assertStringNotContainsString("\r\nSet-Cookie:", $wire);
        $this->assertStringContainsString("Location: /nextSet-Cookie: admin=1\r\n", $wire);
    }

    public function testEncodeStripsCrlfFromHeaderName(): void
    {
        $wire = HttpProtocol::encode([
            'headers' => ["X-Safe\r\nX-Injected" => '1'],
            'body' => '',
        ]);

        $this->assertStringNotContainsString("\r\nX-Injected", $wire);
        $this->assertStringContainsString("X-SafeX-Injected: 1\r\n", $wire);
    }

    public function testEncodeStripsNulFromHeaders(): void
    {
        $wire = HttpProtocol::encode([
            'headers' => ['X-Trace' => "a\0b"],
            'body' => '',
        ]);

        $this->assertStringNotContainsString("\0", $wire);
    }

    /** 完整的响应拆分：注入者试图凭 CRLFCRLF 追加一整份伪造响应 */
    public function testEncodeBlocksResponseSplitting(): void
    {
        $wire = HttpProtocol::encode([
            'headers' => ['X-Echo' => "v\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nowned"],
            'body' => 'real',
        ]);

        // 注入串的字面内容留在头值里无害，关键是它不能形成新的行 / 新的报文
        $this->assertSame(0, substr_count($wire, "\r\nHTTP/1.1"), '不得出现第二条状态行');
        $this->assertSame(1, substr_count($wire, "\r\n\r\n"), '头体分隔符只能有一处');
        $this->assertStringEndsWith("\r\n\r\nreal", $wire);
    }

    public function testBeginChunkedStripsCrlfFromHeaders(): void
    {
        $head = HttpProtocol::beginChunked(200, ['X-Trace' => "abc\r\nX-Injected: 1"]);

        $this->assertStringNotContainsString("\r\nX-Injected:", $head);
        $this->assertStringEndsWith("\r\n\r\n", $head);
        $this->assertSame(1, substr_count($head, "\r\n\r\n"));
    }

    public function testEncodeCompressedStripsCrlfFromHeaders(): void
    {
        $wire = HttpProtocol::encodeCompressed([
            'headers' => ['X-Trace' => "abc\r\nX-Injected: 1"],
            'body' => str_repeat('a', 2048),
        ]);

        $this->assertStringNotContainsString("\r\nX-Injected:", $wire);
    }

    public function testHeaderLineLeavesCleanHeadersUntouched(): void
    {
        $this->assertSame("Content-Type: text/plain\r\n", HttpProtocol::headerLine('Content-Type', 'text/plain'));
    }
}
