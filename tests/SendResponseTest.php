<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Http\Psr7Response;
use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\Driver\NativeConnection;
use Kode\Process\Runtime\Driver\SwooleConnection;
use Kode\Process\Runtime\Driver\WorkermanConnection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 响应桥接（sendResponse）单测：
 *  - Psr7Response 序列化器（状态行 / 原因短语 / 多值头 / Content-Length / 头部清洗 / gzip）
 *  - 三运行时 sendResponse（Native 字节回读、Swoole 模式替身、Workerman 字节、gzip 变体）
 *
 * 使用轻量手写 PSR-7 替身，不引入任何第三方 PSR-7 实现（符合最小依赖偏好）。
 */
final class SendResponseTest extends TestCase
{
    // ---------------------------------------------------- Psr7Response 序列化器

    public function testSerializeBasicStatusLineAndAutoContentLength(): void
    {
        $resp = (new FakeResponse())
            ->withStatus(201)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new FakeStream('{"ok":1}'));

        $wire = Psr7Response::toHttp11($resp);

        $this->assertStringStartsWith("HTTP/1.1 201 Created\r\n", $wire);
        $this->assertStringContainsString("Content-Type: application/json\r\n", $wire);
        $this->assertStringContainsString("Content-Length: 8\r\n", $wire, '缺失时应自动补 Content-Length');
        $this->assertSame('{"ok":1}', $this->bodyOf($wire));
    }

    public function testSerializePreservesCustomReasonPhrase(): void
    {
        $resp = (new FakeResponse())
            ->withStatus(200, 'Cool Beans')
            ->withBody(new FakeStream('x'));

        $wire = Psr7Response::toHttp11($resp);
        $this->assertStringStartsWith("HTTP/1.1 200 Cool Beans\r\n", $wire);
    }

    public function testSerializeUnknownStatusFallsBackToUnknownReason(): void
    {
        $resp = (new FakeResponse())
            ->withStatus(599)
            ->withBody(new FakeStream(''));

        $wire = Psr7Response::toHttp11($resp);
        $this->assertStringStartsWith("HTTP/1.1 599 Unknown\r\n", $wire, '未知状态码应回退到 Unknown');
    }

    public function testSerializeMultiValueHeaderEmitsMultipleLines(): void
    {
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withAddedHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2')
            ->withBody(new FakeStream('x'));

        $wire = Psr7Response::toHttp11($resp);

        $this->assertSame(2, substr_count($wire, "Set-Cookie:"), '多个 Set-Cookie 应逐条输出为独立头行');
        $this->assertStringContainsString("Set-Cookie: a=1\r\n", $wire);
        $this->assertStringContainsString("Set-Cookie: b=2\r\n", $wire);
    }

    public function testSerializeRespectsExplicitContentLength(): void
    {
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Length', '5') // 故意与真实体长度（3）不一致，序列化器应原样保留
            ->withBody(new FakeStream('abc'));

        $wire = Psr7Response::toHttp11($resp);
        $this->assertStringContainsString("Content-Length: 5\r\n", $wire, '已显式声明的 Content-Length 应保留，不再重算');
    }

    public function testSerializeStripsCrLfNulFromHeaderValue(): void
    {
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('X-Evil', "ok\r\nInjected: pwned\x00")
            ->withBody(new FakeStream('x'));

        $wire = Psr7Response::toHttp11($resp);

        // 真正的防护：不能因头值里的 CR/LF 凭空冒出一条独立响应头行
        $this->assertStringNotContainsString("\r\nInjected: pwned", $wire, 'CR/LF/NUL 必须被剔除，否则构成响应拆分');
        $this->assertStringContainsString("X-Evil: okInjected: pwned\r\n", $wire, 'CR/LF/NUL 被剥离后残余文本与原值拼接');
    }

    public function testSerializeGzipPath(): void
    {
        $body = str_repeat('X', 4000);
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(new FakeStream($body));

        $wire = Psr7Response::toHttp11($resp, true);

        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertStringNotContainsString("Content-Length: 4000\r\n", $wire, 'gzip 下应改写为压缩后长度');
        $this->assertSame($body, @gzdecode($this->bodyOf($wire)), '解压后须等于原始体');
    }

    public function testSerializeGzipFallsBackWhenAlreadyEncoded(): void
    {
        $body = str_repeat('Y', 4000);
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Encoding', 'br')
            ->withBody(new FakeStream($body));

        $wire = Psr7Response::toHttp11($resp, true);

        // 已自带 Content-Encoding 时不再二次压缩，原样保留 br
        $this->assertStringContainsString("Content-Encoding: br\r\n", $wire);
        $this->assertStringNotContainsString("Content-Encoding: gzip\r\n", $wire);
    }

    public function testBodySizeUsesKnownSize(): void
    {
        $resp = (new FakeResponse())->withBody(new FakeStream(str_repeat('z', 2048)));
        $this->assertSame(2048, Psr7Response::bodySize($resp));
    }

    // ---------------------------------------------------- Native 运行时

    private function makeNativeHttpConn(): NativeConnection
    {
        $sock = fopen('php://memory', 'r+');
        return new NativeConnection($sock, '127.0.0.1:1', HttpProtocol::class);
    }

    public function testNativeSendResponseWritesHttp11Bytes(): void
    {
        $conn = $this->makeNativeHttpConn();
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(new FakeStream('hello'));

        $this->assertTrue($conn->sendResponse($resp));

        $wire = $this->drain($conn);
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $wire);
        $this->assertStringContainsString("Content-Length: 5\r\n", $wire);
        $this->assertSame('hello', $this->bodyOf($wire));
    }

    public function testNativeSendResponseGzipWhenAutoAndLarge(): void
    {
        $conn = $this->makeNativeHttpConn();
        $conn->setGzipAuto(true);
        $body = str_repeat('A', 4000);
        $resp = (new FakeResponse())
            ->withStatus(200)
            ->withBody(new FakeStream($body));

        $this->assertTrue($conn->sendResponse($resp));

        $wire = $this->drain($conn);
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertSame($body, @gzdecode($this->bodyOf($wire)));
    }

    public function testNativeSendResponseNoGzipForSmallBodyEvenWhenAuto(): void
    {
        $conn = $this->makeNativeHttpConn();
        $conn->setGzipAuto(true);
        $resp = (new FakeResponse())->withBody(new FakeStream('hi'));

        $this->assertTrue($conn->sendResponse($resp));

        $wire = $this->drain($conn);
        $this->assertStringNotContainsString('Content-Encoding: gzip', $wire, '小响应不应被 gzip（未达阈值）');
    }

    public function testNativeSendResponseReturnsFalseWhenClosed(): void
    {
        $conn = $this->makeNativeHttpConn();
        $conn->close();
        $resp = (new FakeResponse())->withBody(new FakeStream('x'));

        $this->assertFalse($conn->sendResponse($resp));
    }

    // ---------------------------------------------------- Swoole 运行时

    public function testSwooleHttpModeSendResponseUsesNativeApi(): void
    {
        $resp = new PsrSwooleFakeResponse();
        $conn = new SwooleConnection(new PsrSwooleFakeServer(), 1, $resp);

        $psr = (new FakeResponse())
            ->withStatus(201)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new FakeStream('abcd'));

        $this->assertTrue($conn->sendResponse($psr));

        $this->assertSame([201], $resp->statuses);
        $this->assertSame('application/json', $resp->headers['Content-Type'] ?? null);
        $this->assertSame('4', $resp->headers['Content-Length'] ?? '');
        $this->assertTrue($resp->ended);
        $this->assertSame('abcd', $resp->endData);
        $this->assertFalse($conn->isAlive(), 'Swoole HTTP 模式响应一次性写出后 responded=true，isAlive() 应报告已结束');
    }

    public function testSwooleHttpModeMultiValueHeader(): void
    {
        $resp = new PsrSwooleFakeResponse();
        $conn = new SwooleConnection(new PsrSwooleFakeServer(), 1, $resp);

        $psr = (new FakeResponse())
            ->withStatus(200)
            ->withAddedHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2')
            ->withBody(new FakeStream('x'));

        $conn->sendResponse($psr);

        $cookieCalls = 0;
        foreach ($resp->headerCalls as [$name, $value]) {
            if (strtolower($name) === 'set-cookie') {
                $cookieCalls++;
            }
        }
        $this->assertSame(2, $cookieCalls, '多个 Set-Cookie 应逐条调用 header()');
    }

    public function testSwooleHttpModeGzip(): void
    {
        $resp = new PsrSwooleFakeResponse();
        $conn = new SwooleConnection(new PsrSwooleFakeServer(), 1, $resp);
        $conn->setGzipAuto(true);

        $body = str_repeat('B', 5000);
        $psr = (new FakeResponse())->withStatus(200)->withBody(new FakeStream($body));

        $conn->sendResponse($psr);

        $this->assertSame('gzip', $resp->headers['Content-Encoding'] ?? null);
        $this->assertSame($body, @gzdecode($resp->endData));
    }

    public function testSwooleHttpModeNoGzipWhenAlreadyEncoded(): void
    {
        $resp = new PsrSwooleFakeResponse();
        $conn = new SwooleConnection(new PsrSwooleFakeServer(), 1, $resp);
        $conn->setGzipAuto(true);

        $body = str_repeat('C', 5000);
        $psr = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Encoding', 'br')
            ->withBody(new FakeStream($body));

        $conn->sendResponse($psr);

        $this->assertArrayNotHasKey('Content-Encoding-gzip', $resp->headers);
        $this->assertSame('br', $resp->headers['Content-Encoding'] ?? null, '已编码的响应不应再被 gzip');
        $this->assertSame($body, $resp->endData);
    }

    public function testSwooleHttpModeSecondResponseRejected(): void
    {
        $resp = new PsrSwooleFakeResponse();
        $conn = new SwooleConnection(new PsrSwooleFakeServer(), 1, $resp);

        $this->assertTrue($conn->sendResponse((new FakeResponse())->withBody(new FakeStream('x'))));
        $this->assertFalse($conn->sendResponse((new FakeResponse())->withBody(new FakeStream('y'))), 'HTTP 响应只能写一次');
    }

    public function testSwooleRawModeSendResponseSerializesBytes(): void
    {
        $server = new PsrSwooleFakeServer();
        $conn = new SwooleConnection($server, 7, null);

        $psr = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(new FakeStream('raw'));

        $this->assertTrue($conn->sendResponse($psr));

        $this->assertNotEmpty($server->sent);
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $server->sent[0]);
        $this->assertStringContainsString('raw', $server->sent[0]);
    }

    // ---------------------------------------------------- Workerman 运行时

    public function testWorkermanSendResponseWritesBytes(): void
    {
        $peer = new PsrWorkermanFakeConn();
        $conn = new WorkermanConnection($peer);

        $psr = (new FakeResponse())
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(new FakeStream('wm'));

        $this->assertTrue($conn->sendResponse($psr));

        $this->assertNotEmpty($peer->sent);
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $peer->sent[0]);
        $this->assertStringContainsString("Content-Length: 2\r\n", $peer->sent[0]);
        $this->assertStringContainsString('wm', $peer->sent[0]);
    }

    public function testWorkermanSendResponseGzip(): void
    {
        $peer = new PsrWorkermanFakeConn();
        $conn = new WorkermanConnection($peer);
        $conn->setGzipAuto(true);

        $body = str_repeat('W', 4000);
        $psr = (new FakeResponse())->withStatus(200)->withBody(new FakeStream($body));

        $this->assertTrue($conn->sendResponse($psr));

        $wire = $peer->sent[0];
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertSame($body, @gzdecode($this->bodyOf($wire)));
    }

    // ---------------------------------------------------- 辅助

    private function drain(NativeConnection $conn): string
    {
        $sock = $conn->native();
        rewind($sock);
        $data = (string) stream_get_contents($sock);
        rewind($sock);
        return $data;
    }

    private function bodyOf(string $wire): string
    {
        $pos = strpos($wire, "\r\n\r\n");
        return $pos === false ? '' : substr($wire, $pos + 4);
    }
}

// ============================================================ PSR-7 替身

final class FakeStream implements StreamInterface
{
    public function __construct(private string $content = '')
    {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
        $this->content = '';
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return substr($this->content, 0, $length);
    }

    public function getContents(): string
    {
        return $this->content;
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}

final class FakeResponse implements ResponseInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    private string $body = '';

    private string $reason = '';

    public function __construct(
        private int $status = 200,
        private string $protocol = '1.1',
    ) {
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        $c = clone $this;
        $c->protocol = $version;
        return $c;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getHeader(string $name): array
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values;
            }
        }
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        $c = clone $this;
        $c->headers[$name] = is_array($value) ? $value : [$value];
        return $c;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        $c = clone $this;
        $list = is_array($value) ? $value : [$value];
        $c->headers[$name] = array_merge($c->headers[$name] ?? [], $list);
        return $c;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        $c = clone $this;
        foreach (array_keys($c->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                unset($c->headers[$key]);
            }
        }
        return $c;
    }

    public function getBody(): StreamInterface
    {
        return new FakeStream($this->body);
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $c = clone $this;
        $c->body = (string) $body;
        return $c;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $c = clone $this;
        $c->status = $code;
        $c->reason = $reasonPhrase;
        return $c;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }
}

final class PsrSwooleFakeServer
{
    /** @var list<string> */
    public array $sent = [];

    public function send(int $fd, string $data): bool
    {
        $this->sent[] = $data;
        return true;
    }

    public function exists(int $fd): bool
    {
        return true;
    }
}

final class PsrSwooleFakeResponse
{
    /** @var list<int> */
    public array $statuses = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<array{string, string}> */
    public array $headerCalls = [];

    public bool $ended = false;

    public string $endData = '';

    public function status(int $code): void
    {
        $this->statuses[] = $code;
    }

    public function header(string $name, string $value, bool $replace = true): void
    {
        $this->headerCalls[] = [$name, $value];
        if ($replace || !isset($this->headers[$name])) {
            $this->headers[$name] = $value;
        } else {
            $this->headers[$name] = $this->headers[$name] . ', ' . $value;
        }
    }

    public function write(string $data): void
    {
    }

    public function end(string $data = ''): void
    {
        $this->ended = true;
        $this->endData = $data;
    }
}

final class PsrWorkermanFakeConn
{
    /** @var list<string> */
    public array $sent = [];

    public function send(string $data, bool $raw = false): bool
    {
        $this->sent[] = $data;
        return true;
    }

    public function close(mixed $data = null): void
    {
    }

    public function getStatus(bool $brief = false)
    {
        return 1;
    }

    public function getRemoteAddress(): string
    {
        return '127.0.0.1:1';
    }

    public function getLocalAddress(): string
    {
        return '0.0.0.0:80';
    }

    public int $id = 1;
}
