<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\Http2\Http2Session;
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

    /**
     * keep-alive 复用：同一条连接上连续两个 chunked 响应，每个都必须带终止块
     * `0\r\n\r\n`。缺陷表现为第二个响应缺终止块（httpChunkEnded 未在响应间复位），
     * 客户端会一直等待而挂起。
     */
    public function testKeepAliveSecondChunkedResponseEmitsTerminator(): void
    {
        $conn = $this->makeHttpConn();

        // 第一个流式响应
        $conn->chunk('first');
        $conn->endChunk();

        // 同一 keep-alive 连接上的第二个流式响应
        $conn->chunk('second');
        $conn->endChunk();

        $wire = $this->drain($conn);
        $this->assertSame(
            2,
            substr_count($wire, "0\r\n\r\n"),
            '两个 chunked 响应都应发送终止块，否则第二个响应客户端会挂起'
        );

        // 两个响应各自有独立的 beginChunked 头
        $this->assertSame(2, substr_count($wire, "Transfer-Encoding: chunked\r\n"));
    }

    // ----------------------------------------------------- HTTP gzip 压缩

    public function testGzipAutoCompressesOnSend(): void
    {
        $conn = $this->makeHttpConn();
        $conn->setGzipAuto(true);

        $body = str_repeat('X', 4000);
        $conn->send($body);

        $wire = $this->drain($conn);
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertSame($body, @gzdecode($this->httpBody($wire)));
    }

    public function testGzipSkippedWhenFlagOff(): void
    {
        $conn = $this->makeHttpConn();
        $conn->send(str_repeat('X', 4000));

        $wire = $this->drain($conn);
        $this->assertStringNotContainsString('Content-Encoding: gzip', $wire);
    }

    public function testGzipSkippedForSmallBodyEvenWhenAuto(): void
    {
        $conn = $this->makeHttpConn();
        $conn->setGzipAuto(true);
        $conn->send('hi');

        $wire = $this->drain($conn);
        $this->assertStringNotContainsString('Content-Encoding: gzip', $wire);
    }

    public function testGzipExplicitApi(): void
    {
        $conn = $this->makeHttpConn();
        $body = str_repeat('Y', 4000);
        $conn->gzip($body, 200, ['Content-Type' => 'text/plain']);

        $wire = $this->drain($conn);
        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $wire);
        $this->assertStringContainsString("Content-Type: text/plain\r\n", $wire);
        $this->assertSame($body, @gzdecode($this->httpBody($wire)));
    }

    public function testGzipOnNonHttpConnFallsBackToSend(): void
    {
        $conn = $this->makeConn(); // protocolClass 为 null
        $this->assertTrue($conn->gzip('raw-payload', 200, []));
        $wire = $this->drain($conn);
        $this->assertSame('raw-payload', $wire);
    }

    private function drain(NativeConnection $conn): string
    {
        $sock = $conn->native();
        rewind($sock);
        $data = (string)stream_get_contents($sock);
        rewind($sock);
        return $data;
    }

    private function httpBody(string $wire): string
    {
        $pos = strpos($wire, "\r\n\r\n");
        return $pos === false ? '' : substr($wire, $pos + 4);
    }

    /**
     * 优雅关闭：HTTP/2 连接关闭 TCP 前必须先发 GOAWAY，
     * 否则正在进行的多路复用请求会被 RST 硬切断、restart 会中断在途连接。
     */
    public function testGracefulCloseSendsGoawayOnHttp2Connection(): void
    {
        [$sock, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($sock, false);
        stream_set_blocking($peer, false);

        $conn = new NativeConnection($sock, '127.0.0.1:1');

        $session = new Http2Session();
        $session->markPrefaceReceived();
        $session->sendLocalSettings();
        $conn->attachHttp2($session);

        $conn->gracefulClose();

        $this->assertTrue($conn->isClosed(), 'gracefulClose 必须关闭连接');
        $this->assertTrue($session->isClosed(), 'gracefulClose 必须对 h2 会话发送 GOAWAY 并标记关闭');

        // flushHttp2 已在关闭前把 GOAWAY 写入套接字：从对端读取并断言 GOAWAY 帧存在
        $bytes = $this->readPeer($peer);
        $this->assertNotEmpty($bytes, '必须向对端写出帧数据');
        // GOAWAY 帧头：3 字节长度(0x000008) + type(0x07) + flags(0x00) ...
        $this->assertStringContainsString("\x00\x00\x08\x07\x00", $bytes, '应写出 GOAWAY 帧（type=7）');
        fclose($peer);
    }

    public function testGracefulClosePlainConnectionJustCloses(): void
    {
        [$sock, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($sock, false);
        stream_set_blocking($peer, false);

        $conn = new NativeConnection($sock, '127.0.0.1:1');
        $conn->gracefulClose();

        $this->assertTrue($conn->isClosed());
        $this->assertFalse($conn->isHttp2());
        fclose($peer);
    }

    private function readPeer($peer): string
    {
        $bytes    = '';
        $deadline = microtime(true) + 0.5;
        while (microtime(true) < $deadline && strlen($bytes) < 17) {
            $r = @fread($peer, 1024);
            if ($r === false) {
                break;
            }
            $bytes .= $r;
            if ($r === '') {
                usleep(5000);
            }
        }

        return $bytes;
    }
}
