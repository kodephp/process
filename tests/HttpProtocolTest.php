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
}
