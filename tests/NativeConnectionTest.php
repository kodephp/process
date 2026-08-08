<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\Driver\NativeConnection;
use PHPUnit\Framework\TestCase;

/**
 * NativeConnection 单元测试，重点覆盖 WebSocket 分片重组状态机
 * （RFC 6455 §5.4）——该状态原本缺失，导致大消息被按帧碎片化派发。
 */
final class NativeConnectionTest extends TestCase
{
    private function makeConn(): NativeConnection
    {
        $sock = fopen('php://memory', 'r+');
        return new NativeConnection($sock, '127.0.0.1:1');
    }

    public function testFragmentLifecycleText(): void
    {
        $conn = $this->makeConn();
        $this->assertFalse($conn->isFragmenting(), '初始不应处于分片中');

        $conn->startFragment(0x1, 'Hello ');
        $this->assertTrue($conn->isFragmenting());
        $this->assertSame(6, $conn->fragmentSize());
        $this->assertSame(0x1, $conn->fragmentOpcode());

        $conn->appendFragment('wor');
        $conn->appendFragment('ld');

        $msg = $conn->finishFragment();
        $this->assertSame('message', $msg['type']);
        $this->assertSame(0x1, $msg['opcode']);
        $this->assertSame('Hello world', $msg['data']);
        $this->assertSame(1, $msg['fin']);
        $this->assertFalse($conn->isFragmenting(), 'finish 后应复位分片状态');
        $this->assertSame(0, $conn->fragmentSize());
    }

    public function testFragmentLifecycleBinaryPreservesOpcode(): void
    {
        $conn = $this->makeConn();
        $conn->startFragment(0x2, "\x00\x01");
        $conn->appendFragment("\x02");
        $msg = $conn->finishFragment();

        $this->assertSame(0x2, $msg['opcode'], '重组后必须保留原始 binary opcode');
        $this->assertSame("\x00\x01\x02", $msg['data']);
        $this->assertFalse($conn->isFragmenting());
    }

    public function testResetFragmentClearsState(): void
    {
        $conn = $this->makeConn();
        $conn->startFragment(0x1, 'partial');
        $conn->resetFragment();

        $this->assertFalse($conn->isFragmenting());
        $this->assertSame(0, $conn->fragmentSize());
        $this->assertSame(0, $conn->fragmentOpcode());
    }

    public function testEmptyFragmentDataReassemblesToEmptyString(): void
    {
        $conn = $this->makeConn();
        $conn->startFragment(0x1, '');
        $msg = $conn->finishFragment();

        $this->assertSame('', $msg['data']);
        $this->assertFalse($conn->isFragmenting());
    }

    // ----------------------------------------------------- HTTP 分块流式

    private function makeHttpConn(): NativeConnection
    {
        $sock = fopen('php://memory', 'r+');
        return new NativeConnection($sock, '127.0.0.1:1', \Kode\Process\Protocol\HttpProtocol::class);
    }

    public function testChunkLifecycleOnHttpConn(): void
    {
        $conn = $this->makeHttpConn();
        $this->assertFalse($conn->isChunkStarted());

        $this->assertTrue($conn->beginChunked(200, ['Content-Type' => 'text/plain']));
        $this->assertTrue($conn->isChunkStarted());

        $this->assertTrue($conn->chunk('foo'));
        $this->assertTrue($conn->chunk('bar'));

        $this->assertTrue($conn->endChunk());
        $this->assertFalse($conn->isChunkStarted(), 'endChunk 后应复位');
    }

    public function testChunkAutoStartsWithDefaultHeader(): void
    {
        $conn = $this->makeHttpConn();
        $this->assertTrue($conn->chunk('hello'));
        $this->assertTrue($conn->isChunkStarted());
        $this->assertTrue($conn->endChunk());
    }

    public function testChunkOnNonHttpConnFallsBackToSend(): void
    {
        // protocolClass 为 null（裸 TCP）：chunk 应降级为普通 send，不进入 chunked 模式
        $conn = $this->makeConn();
        $this->assertTrue($conn->chunk('raw-data'));
        $this->assertFalse($conn->isChunkStarted());
    }

    public function testCloseResetsChunkState(): void
    {
        $conn = $this->makeHttpConn();
        $conn->chunk('x');
        $this->assertTrue($conn->isChunkStarted());
        $conn->close();
        $this->assertFalse($conn->isChunkStarted());
    }
}
