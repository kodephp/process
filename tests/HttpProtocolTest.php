<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\HttpProtocol;
use PHPUnit\Framework\TestCase;

/**
 * HTTP/1.1 协议回归测试
 */
final class HttpProtocolTest extends TestCase
{
    public function testGetName(): void
    {
        $this->assertSame('http', HttpProtocol::getName());
    }

    // --- input() 分包 -----------------------------------------------------

    public function testInputReturnsZeroWhenHeaderIncomplete(): void
    {
        $this->assertSame(0, HttpProtocol::input("GET / HTTP/1.1\r\nHost: a"));
        $this->assertSame(0, HttpProtocol::input(''));
    }

    public function testInputReturnsHeaderLengthForBodylessRequest(): void
    {
        $request = "GET /path HTTP/1.1\r\nHost: example.com\r\n\r\n";

        $this->assertSame(strlen($request), HttpProtocol::input($request));
    }

    public function testInputAccountsForContentLength(): void
    {
        $body = '{"a":1}';
        $head = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: " . strlen($body) . "\r\n\r\n";

        $this->assertSame(strlen($head) + strlen($body), HttpProtocol::input($head . $body));
    }

    public function testInputWaitsForIncompleteBody(): void
    {
        $head = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 20\r\n\r\n";

        $this->assertSame(0, HttpProtocol::input($head . 'short'));
    }

    public function testInputIgnoresTrailingPipelinedData(): void
    {
        $body = 'abc';
        $head = "POST / HTTP/1.1\r\nContent-Length: 3\r\n\r\n";
        $expected = strlen($head) + 3;

        // 缓冲区里带着下一个请求的开头，input 只应返回当前报文长度
        $this->assertSame($expected, HttpProtocol::input($head . $body . "GET /next HTTP/1.1\r\n"));
    }

    public function testContentLengthHeaderIsCaseInsensitive(): void
    {
        $head = "POST / HTTP/1.1\r\ncontent-length: 4\r\n\r\n";

        $this->assertSame(strlen($head) + 4, HttpProtocol::input($head . 'abcd'));
    }

    /**
     * 回归：body 里出现的 content-length 字样不得被当成头部字段
     */
    public function testContentLengthInBodyIsIgnored(): void
    {
        $body = "\r\ncontent-length: 9999";
        $head = "POST / HTTP/1.1\r\nContent-Length: " . strlen($body) . "\r\n\r\n";

        $this->assertSame(strlen($head) + strlen($body), HttpProtocol::input($head . $body));
    }

    public function testInputRejectsOversizedBody(): void
    {
        $head = "POST / HTTP/1.1\r\nContent-Length: " . (HttpProtocol::MAX_LENGTH + 1) . "\r\n\r\n";

        $this->assertSame(-1, HttpProtocol::input($head));
    }

    public function testInputRejectsOversizedHeader(): void
    {
        $this->assertSame(-1, HttpProtocol::input(str_repeat('x', HttpProtocol::MAX_LENGTH + 1)));
    }

    // --- decode() ---------------------------------------------------------

    public function testDecodeRequestLine(): void
    {
        $request = HttpProtocol::decode("GET /users?id=7&tag=a HTTP/1.1\r\nHost: example.com\r\n\r\n");

        $this->assertSame('GET', $request['method']);
        $this->assertSame('/users?id=7&tag=a', $request['uri']);
        $this->assertSame('/users', $request['path']);
        $this->assertSame('HTTP/1.1', $request['protocol']);
        $this->assertSame(['id' => '7', 'tag' => 'a'], $request['query']);
        $this->assertSame($request['query'], $request['get']);
    }

    public function testDecodeHeaders(): void
    {
        $request = HttpProtocol::decode(
            "GET / HTTP/1.1\r\nHost: example.com\r\nX-Token:  abc123  \r\n\r\n"
        );

        $this->assertSame('example.com', $request['headers']['Host']);
        $this->assertSame('abc123', $request['headers']['X-Token']);
    }

    public function testDecodeEmptyPathDefaultsToRoot(): void
    {
        $request = HttpProtocol::decode("GET ?a=1 HTTP/1.1\r\n\r\n");

        $this->assertSame('/', $request['path']);
    }

    public function testDecodeFormUrlencodedBody(): void
    {
        $body = 'name=kode&age=3';
        $request = HttpProtocol::decode(
            "POST / HTTP/1.1\r\nContent-Type: application/x-www-form-urlencoded\r\n"
            . "Content-Length: " . strlen($body) . "\r\n\r\n" . $body
        );

        $this->assertSame(['name' => 'kode', 'age' => '3'], $request['post']);
        $this->assertSame($body, $request['body']);
    }

    public function testDecodeJsonBody(): void
    {
        $body = '{"name":"kode","n":3}';
        $request = HttpProtocol::decode(
            "POST / HTTP/1.1\r\nContent-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n\r\n" . $body
        );

        $this->assertSame(['name' => 'kode', 'n' => 3], $request['post']);
    }

    /**
     * 回归：非法 JSON 交由 json_validate 拦截，不得抛错或返回 null
     */
    public function testDecodeInvalidJsonBodyReturnsEmptyArray(): void
    {
        $body = '{"broken":';
        $request = HttpProtocol::decode(
            "POST / HTTP/1.1\r\nContent-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n\r\n" . $body
        );

        $this->assertSame([], $request['post']);
        $this->assertSame($body, $request['body']);
    }

    public function testDecodeJsonScalarBodyReturnsEmptyArray(): void
    {
        $request = HttpProtocol::decode(
            "POST / HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: 4\r\n\r\ntrue"
        );

        $this->assertSame([], $request['post']);
    }

    public function testDecodeUnknownContentTypeLeavesPostEmpty(): void
    {
        $request = HttpProtocol::decode(
            "POST / HTTP/1.1\r\nContent-Type: text/plain\r\nContent-Length: 5\r\n\r\nhello"
        );

        $this->assertSame([], $request['post']);
        $this->assertSame('hello', $request['body']);
    }

    // --- encode() ---------------------------------------------------------

    public function testEncodeBareStringWrapsAsResponse(): void
    {
        $response = HttpProtocol::encode('raw');

        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
        $this->assertStringContainsString("Content-Length: 3\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\nraw", $response);
    }

    public function testEncodeCompleteResponsePassesThrough(): void
    {
        $full = "HTTP/1.1 404 Not Found\r\nContent-Length: 4\r\n\r\nbody";

        $this->assertSame($full, HttpProtocol::encode($full));
    }

    public function testEncodeUnsupportedTypeReturnsEmptyString(): void
    {
        $this->assertSame('', HttpProtocol::encode(123));
    }

    public function testEncodeAddsContentLength(): void
    {
        $response = HttpProtocol::encode(['status' => 200, 'body' => 'hello']);

        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
        $this->assertStringContainsString("Content-Length: 5\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\nhello", $response);
    }

    public function testEncodeKeepsExplicitContentLength(): void
    {
        $response = HttpProtocol::encode([
            'status' => 200,
            'headers' => ['Content-Length' => 99],
            'body' => 'hello',
        ]);

        $this->assertStringContainsString('Content-Length: 99', $response);
        $this->assertStringNotContainsString('Content-Length: 5', $response);
    }

    public function testEncodeDefaultsToStatus200(): void
    {
        $this->assertStringStartsWith('HTTP/1.1 200 OK', HttpProtocol::encode([]));
    }

    public function testEncodeIsParseableByInput(): void
    {
        $response = HttpProtocol::encode(['status' => 404, 'body' => 'nope']);

        $this->assertSame(strlen($response), HttpProtocol::input($response));
    }

    // --- 状态码表 ---------------------------------------------------------

    public function testStatusTextCoversCommonCodes(): void
    {
        $expected = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            206 => 'Partial Content',
            308 => 'Permanent Redirect',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];

        foreach ($expected as $code => $text) {
            $this->assertSame($text, HttpProtocol::getStatusText($code));
        }
    }

    public function testStatusTextFallback(): void
    {
        $this->assertSame('Unknown', HttpProtocol::getStatusText(799));
    }
}
