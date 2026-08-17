<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\Driver\SwooleConnection;
use PHPUnit\Framework\TestCase;

/**
 * SwooleConnection 分块流式桥接单测（不依赖真实 Swoole 服务，用测试替身）。
 */
final class SwooleConnectionTest extends TestCase
{
    public function testHttpModeStreamingUsesResponseWrite(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);

        $conn->beginChunked(201, ['Content-Type' => 'application/json']);
        $conn->chunk('ab');
        $conn->chunk('cd');
        $conn->endChunk();

        $this->assertSame([201], $resp->statuses);
        $this->assertSame('application/json', $resp->headers['Content-Type']);
        $this->assertSame(['ab', 'cd'], $resp->writes);
        $this->assertTrue($resp->ended);
        $this->assertFalse($conn->isChunkStarted());
    }

    public function testHttpModeChunkAutoStartsOnFirstChunk(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);

        $conn->chunk('x');
        $conn->endChunk();

        // 未显式 beginChunked 时，首个 chunk 即开启流式（Swoole 自动 chunked）
        $this->assertSame(['x'], $resp->writes);
        $this->assertTrue($resp->ended);
    }

    public function testRawModeSendsChunkedBytes(): void
    {
        $server = new SwooleFakeServer();
        $conn   = new SwooleConnection($server, 7, null);

        $conn->beginChunked(200, ['Content-Type' => 'text/plain']);
        $conn->chunk('foo');
        $conn->chunk('bar');
        $conn->endChunk();

        $expected = [
            HttpProtocol::beginChunked(200, ['Content-Type' => 'text/plain']),
            HttpProtocol::chunkFrame('foo'),
            HttpProtocol::chunkFrame('bar'),
            HttpProtocol::chunkEnd(),
        ];
        $this->assertSame($expected, $server->sent);
        $this->assertFalse($conn->isChunkStarted());
    }

    public function testEndChunkWithoutStartIsNoop(): void
    {
        $server = new SwooleFakeServer();
        $conn   = new SwooleConnection($server, 7, null);
        $this->assertFalse($conn->endChunk());
        $this->assertSame([], $server->sent);
    }

    public function testHttpModeAutoGzip(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);
        $conn->setGzipAuto(true);

        $body = str_repeat('A', 5000);
        $conn->send($body);

        $this->assertSame('gzip', $resp->headers['Content-Encoding'] ?? null);
        $this->assertSame((string)strlen($resp->endData), $resp->headers['Content-Length'] ?? '');
        $this->assertSame($body, @gzdecode($resp->endData));
        $this->assertTrue($resp->ended);
    }

    public function testHttpModeNoGzipWithoutFlag(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);

        $conn->send(str_repeat('A', 5000));

        $this->assertArrayNotHasKey('Content-Encoding', $resp->headers);
        $this->assertSame(str_repeat('A', 5000), $resp->endData);
    }

    public function testHttpModeExplicitGzip(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);

        $body = str_repeat('B', 5000);
        $conn->gzip($body, 200, ['Content-Type' => 'text/plain']);

        $this->assertSame('gzip', $resp->headers['Content-Encoding'] ?? null);
        $this->assertSame('text/plain', $resp->headers['Content-Type'] ?? null);
        $this->assertSame($body, @gzdecode($resp->endData));
    }

    public function testRawModeGzipFallsBackToSend(): void
    {
        $server = new SwooleFakeServer();
        $conn   = new SwooleConnection($server, 7, null);
        $this->assertTrue($conn->gzip('payload', 200, []));
        $this->assertSame(['payload'], $server->sent);
    }

    /**
     * F2 回归：当 Swoole 已回收该 fd（exists() 返回 false）时，所有写出路径都不得调用
     * response->end()，否则会踩踏 C 层已释放的 Response 对象 → worker 静默崩溃。
     * 闸门应使写出被安全跳过（不抛异常、不调用 end）。
     */
    public function testHttpModeWriteSkippedWhenFdRecycled(): void
    {
        $server = new SwooleFakeServer();
        $server->existsReturn = false; // 模拟 keep-alive 并发下 fd 已失效
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection($server, 1, $resp);

        // 各写出路径在 fd 失效时都不应真正 end()
        $conn->send('hello');
        $this->assertFalse($resp->ended, 'send 不得写出已回收响应');

        $conn->setGzipAuto(true);
        $conn->send(str_repeat('A', 5000));
        $this->assertFalse($resp->ended, '自动 gzip 不得写出已回收响应');

        $conn->gzip('payload', 200, []);
        $this->assertFalse($resp->ended, 'gzip 不得写出已回收响应');

        $conn->beginChunked();
        $conn->chunk('x');
        $conn->endChunk();
        $this->assertFalse($resp->ended, 'endChunk 不得写出已回收响应');

        $conn->close(); // 未 responded 时会尝试 end()
        $this->assertFalse($resp->ended, 'close 不得写出已回收响应');
    }

    /**
     * F2 配套：正常场景下 exists() 返回 true，响应仍需正常写出（闸门不应误伤正常路径）。
     */
    public function testHttpModeWriteProceedsWhenFdAlive(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);

        $conn->send('hi');
        $this->assertTrue($resp->ended, 'fd 存活时应正常写出');
        $this->assertSame('hi', $resp->endData);
    }

    /**
     * F2 防御性边界：reset() 应清空请求级响应状态。
     */
    public function testResetClearsRequestState(): void
    {
        $resp = new SwooleFakeResponse();
        $conn = new SwooleConnection(new SwooleFakeServer(), 1, $resp);
        $conn->beginChunked();
        $this->assertTrue($conn->isChunkStarted());

        $conn->reset();
        $this->assertFalse($conn->isChunkStarted());
        $this->assertTrue($conn->isAlive());
    }
}

final class SwooleFakeServer
{
    /** @var list<string> */
    public array $sent = [];

    /**
     * 控制 exists() 返回值，用于模拟 Swoole 已回收 fd 的场景（F2 闸门外）。
     * 默认 true（正常）；置 false 模拟 keep-alive 并发下 fd 已失效。
     */
    public bool $existsReturn = true;

    public function send(int $fd, string $data): bool
    {
        $this->sent[] = $data;
        return true;
    }

    public function exists(int $fd): bool
    {
        return $this->existsReturn;
    }
}

final class SwooleFakeResponse
{
    /** @var list<int> */
    public array $statuses = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<string> */
    public array $writes = [];

    public bool $ended = false;

    public string $endData = '';

    public function status(int $code): void
    {
        $this->statuses[] = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    public function end(string $data = ''): void
    {
        $this->ended   = true;
        $this->endData = $data;
    }
}
