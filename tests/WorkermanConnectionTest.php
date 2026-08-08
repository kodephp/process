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
