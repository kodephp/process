<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\HttpProtocol;
use PHPUnit\Framework\TestCase;

/**
 * HttpProtocol 分块编码助手（beginChunked / chunkFrame / chunkEnd）单测。
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
}
