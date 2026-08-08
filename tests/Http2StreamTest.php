<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Hpack;
use Kode\Process\Protocol\Http2\Http2Session;
use Kode\Process\Runtime\Driver\Http2Stream;
use Kode\Process\Runtime\Driver\NativeConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * HTTP/2 流视图测试。
 *
 * Http2Stream 是业务实际拿到的 `$conn`，它负责把业务写法翻译成 Session 调用。
 * 这里用「无构造器实例化」造一个父连接壳：其 http2Session 为 null，flushHttp2()
 * 会安全短路而不碰 socket，于是可以直接从 session 的输出缓冲里验证真实产出的帧。
 */
final class Http2StreamTest extends TestCase
{
    private Http2Session $session;

    private Http2Stream $stream;

    protected function setUp(): void
    {
        $this->session = new Http2Session();
        $this->session->feed(Frame::PREFACE);

        // 开一条流 1，让后续响应有落点
        $client = new Hpack();
        $this->session->feed(Frame::encode(
            Frame::TYPE_HEADERS,
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM,
            1,
            $client->encode([
                [':method', 'GET'],
                [':scheme', 'http'],
                [':path', '/'],
                [':authority', 'example.com'],
            ])
        ));
        $this->session->drain();

        $parent = (new ReflectionClass(NativeConnection::class))->newInstanceWithoutConstructor();

        $this->stream = new Http2Stream($parent, $this->session, 1);
    }

    /**
     * 取出本轮写出的响应头。
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sentHeaders(): array
    {
        $frame = Frame::decode($this->session->drain());
        self::assertNotNull($frame, '应当写出一个 HEADERS 帧');
        self::assertSame(Frame::TYPE_HEADERS, $frame['type']);

        return (new Hpack())->decode($frame['payload']);
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @return list<string>
     */
    private static function valuesOf(array $headers, string $name): array
    {
        $out = [];
        foreach ($headers as [$k, $v]) {
            if ($k === $name) {
                $out[] = $v;
            }
        }

        return $out;
    }

    public function testBeginChunkedKeepsDuplicateSetCookie(): void
    {
        $this->stream->beginChunked(200, ['set-cookie' => ['sid=a', 'csrf=b']]);

        $this->assertSame(['sid=a', 'csrf=b'], self::valuesOf($this->sentHeaders(), 'set-cookie'));
    }

    public function testBeginChunkedAddsDefaultContentType(): void
    {
        $this->stream->beginChunked(200, []);

        $this->assertSame(
            ['text/html; charset=utf-8'],
            self::valuesOf($this->sentHeaders(), 'content-type')
        );
    }

    public function testBeginChunkedDoesNotOverrideExplicitContentType(): void
    {
        $this->stream->beginChunked(200, ['Content-Type' => 'application/json']);

        $headers = $this->sentHeaders();
        $this->assertSame(['application/json'], self::valuesOf($headers, 'content-type'));
    }

    public function testGzipKeepsDuplicateSetCookieAndSetsEncoding(): void
    {
        $this->stream->gzip(str_repeat('hello world ', 100), 200, [
            ['set-cookie', 'sid=a'],
            ['set-cookie', 'csrf=b'],
        ]);

        $headers = $this->sentHeaders();

        $this->assertSame(['sid=a', 'csrf=b'], self::valuesOf($headers, 'set-cookie'));
        $this->assertSame(['gzip'], self::valuesOf($headers, 'content-encoding'));
        $this->assertNotSame([], self::valuesOf($headers, 'content-length'));
    }

    public function testStreamExposesItsIdentity(): void
    {
        $this->assertSame(1, $this->stream->streamId());
        $this->assertGreaterThan(0, $this->stream->id());
    }
}
