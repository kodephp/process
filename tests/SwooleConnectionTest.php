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
}

final class SwooleFakeServer
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

final class SwooleFakeResponse
{
    /** @var list<int> */
    public array $statuses = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<string> */
    public array $writes = [];

    public bool $ended = false;

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
        $this->ended = true;
    }
}
