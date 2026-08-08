<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Runtime\Driver\WorkermanConnection;
use PHPUnit\Framework\TestCase;

/**
 * WorkermanConnection 分块流式桥接单测（用测试替身模拟 TcpConnection）。
 */
final class WorkermanConnectionTest extends TestCase
{
    public function testBeginChunkedThenChunkSendsRawBytes(): void
    {
        $conn = new WorkermanConnection(new WorkermanFakeTcp());

        $conn->beginChunked(200, ['Content-Type' => 'text/plain']);
        $conn->chunk('foo');
        $conn->chunk('bar');
        $conn->endChunk();

        $expected = [
            [HttpProtocol::beginChunked(200, ['Content-Type' => 'text/plain']), true],
            [HttpProtocol::chunkFrame('foo'), true],
            [HttpProtocol::chunkFrame('bar'), true],
            [HttpProtocol::chunkEnd(), true],
        ];
        $this->assertSame($expected, $conn->native()->sent);
        $this->assertFalse($conn->isChunkStarted());
    }

    public function testChunkAutoStartsWithDefaultHeader(): void
    {
        $conn = new WorkermanConnection(new WorkermanFakeTcp());

        $conn->chunk('hello');
        $conn->endChunk();

        $expected = [
            [HttpProtocol::beginChunked(), true],
            [HttpProtocol::chunkFrame('hello'), true],
            [HttpProtocol::chunkEnd(), true],
        ];
        $this->assertSame($expected, $conn->native()->sent);
    }

    public function testEndChunkWithoutStartIsNoop(): void
    {
        $conn = new WorkermanConnection(new WorkermanFakeTcp());
        $this->assertFalse($conn->endChunk());
        $this->assertSame([], $conn->native()->sent);
    }

    public function testAutoGzipSendsCompressedRaw(): void
    {
        $conn = new WorkermanConnection(new WorkermanFakeTcp());
        $conn->setGzipAuto(true);

        $body = str_repeat('A', 5000);
        $ok   = $conn->send($body);

        $this->assertTrue($ok);
        [$wire, $raw] = $conn->native()->sent[0];
        $this->assertTrue($raw);
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertSame($body, @gzdecode($this->httpBody($wire)));
    }

    public function testNoAutoGzipWithoutFlag(): void
    {
        $conn = new WorkermanConnection(new WorkermanFakeTcp());

        $body = str_repeat('A', 5000);
        $conn->send($body);

        [$data, $raw] = $conn->native()->sent[0];
        $this->assertFalse($raw);
        $this->assertSame($body, $data);
    }

    public function testExplicitGzip(): void
    {
        $conn = new WorkermanConnection(new WorkermanFakeTcp());

        $body = str_repeat('B', 5000);
        $ok   = $conn->gzip($body, 200, ['Content-Type' => 'text/plain']);

        $this->assertTrue($ok);
        [$wire, $raw] = $conn->native()->sent[0];
        $this->assertTrue($raw);
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertStringContainsString("Content-Type: text/plain\r\n", $wire);
        $this->assertSame($body, @gzdecode($this->httpBody($wire)));
    }

    private function httpBody(string $wire): string
    {
        $pos = strpos($wire, "\r\n\r\n");
        return $pos === false ? '' : substr($wire, $pos + 4);
    }
}

final class WorkermanFakeTcp
{
    /** @var list<array{0:string,1:bool}> */
    public array $sent = [];

    public int $id = 1;

    public function send(string $data, bool $raw = false): bool
    {
        $this->sent[] = [$data, $raw];
        return true;
    }

    public function close(?string $data = null): void
    {
    }

    public function getStatus(bool $safe = true): int
    {
        return 1;
    }
}
