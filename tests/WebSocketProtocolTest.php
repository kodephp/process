<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\WebSocketProtocol;
use PHPUnit\Framework\TestCase;

/**
 * WebSocket 协议回归测试（RFC 6455）
 *
 * 重点覆盖历史缺陷：input() 曾错误地用第一字节最高位（FIN）判断掩码位，
 * 导致客户端帧与服务端帧的长度计算互相串位，引发粘包 / 阻塞。
 */
final class WebSocketProtocolTest extends TestCase
{
    /**
     * 构造一条客户端帧（带掩码）
     */
    private static function clientFrame(int $opcode, string $payload, int $fin = 1): string
    {
        $frame = chr(($fin << 7) | $opcode);
        $len = strlen($payload);
        $mask = "\x12\x34\x56\x78";

        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        $masked = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);

        return $frame . $mask . $masked;
    }

    public function testGetName(): void
    {
        $this->assertSame('websocket', WebSocketProtocol::getName());
    }

    // --- input() 帧长计算 -------------------------------------------------

    public function testInputReturnsZeroWhenHeaderIncomplete(): void
    {
        $this->assertSame(0, WebSocketProtocol::input(''));
        $this->assertSame(0, WebSocketProtocol::input("\x81"));
    }

    /**
     * 回归：客户端分片帧（FIN=0 + MASK=1）
     * 旧实现读第一字节最高位得 FIN=0，误判为未掩码，少算 4 字节掩码键。
     */
    public function testInputHandlesMaskedContinuationFrame(): void
    {
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, 'hello', fin: 0);

        // 2 字节头 + 4 字节掩码 + 5 字节载荷 = 11
        $this->assertSame(11, strlen($frame));
        $this->assertSame(11, WebSocketProtocol::input($frame));
    }

    /**
     * 回归：服务端帧（FIN=1 + MASK=0）
     * 旧实现读第一字节最高位得 1，误判为已掩码，多算 4 字节。
     */
    public function testInputHandlesUnmaskedServerFrame(): void
    {
        $frame = WebSocketProtocol::encode('hello');

        // 2 字节头 + 5 字节载荷 = 7
        $this->assertSame(7, strlen($frame));
        $this->assertSame(7, WebSocketProtocol::input($frame));
    }

    public function testInputMatchesFrameLengthForBothDirections(): void
    {
        foreach (['', 'a', 'hello world', str_repeat('x', 125)] as $payload) {
            $server = WebSocketProtocol::encode($payload);
            $client = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, $payload);

            $this->assertSame(strlen($server), WebSocketProtocol::input($server));
            $this->assertSame(strlen($client), WebSocketProtocol::input($client));
        }
    }

    public function testInputHandles16BitExtendedLength(): void
    {
        $payload = str_repeat('a', 1000);

        $server = WebSocketProtocol::encode($payload);
        $client = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, $payload);

        $this->assertSame(1004, WebSocketProtocol::input($server));
        $this->assertSame(1008, WebSocketProtocol::input($client));
    }

    public function testInputHandles64BitExtendedLength(): void
    {
        $payload = str_repeat('a', 70000);

        $server = WebSocketProtocol::encode($payload);

        $this->assertSame(70010, WebSocketProtocol::input($server));
    }

    public function testInputWaitsForExtendedLengthBytes(): void
    {
        // 声明 126 但只给了 3 字节，长度字段不完整
        $this->assertSame(0, WebSocketProtocol::input("\x81\xFE\x00"));

        // 声明 127 但不足 10 字节
        $this->assertSame(0, WebSocketProtocol::input("\x81\xFF\x00\x00\x00"));
    }

    public function testInputRejectsOversizedFrame(): void
    {
        $tooBig = WebSocketProtocol::MAX_PAYLOAD_LENGTH + 1;

        $this->assertSame(-1, WebSocketProtocol::input("\x81\xFF" . pack('J', $tooBig)));
    }

    public function testInputRejectsNegativeLengthField(): void
    {
        // 64 位最高位置 1，unpack('J') 在 PHP 中得到负数
        $this->assertSame(-1, WebSocketProtocol::input("\x81\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF"));
    }

    // --- encode / decode --------------------------------------------------

    public function testEncodeDecodeText(): void
    {
        $decoded = WebSocketProtocol::decode(WebSocketProtocol::encode('hello websocket'));

        $this->assertSame('message', $decoded['type']);
        $this->assertSame('hello websocket', $decoded['data']);
        $this->assertSame(WebSocketProtocol::OPCODE_TEXT, $decoded['opcode']);
        $this->assertSame(1, $decoded['fin']);
    }

    public function testEncodeArrayProducesJsonPayload(): void
    {
        $decoded = WebSocketProtocol::decode(WebSocketProtocol::encode(['type' => 'msg', 'n' => 1]));

        $this->assertSame(['type' => 'msg', 'n' => 1], json_decode($decoded['data'], true));
    }

    public function testEncodeUnsupportedTypeReturnsEmptyString(): void
    {
        $this->assertSame('', WebSocketProtocol::encode(123));
    }

    public function testDecodeMaskedClientFrame(): void
    {
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, '你好，WebSocket');

        $decoded = WebSocketProtocol::decode($frame);

        $this->assertSame('你好，WebSocket', $decoded['data']);
    }

    public function testMaskRoundTripForVariousLengths(): void
    {
        foreach ([1, 3, 4, 5, 7, 8, 125, 1000] as $len) {
            $payload = random_bytes($len);
            $frame = self::clientFrame(WebSocketProtocol::OPCODE_BINARY, $payload);

            $this->assertSame($payload, WebSocketProtocol::decode($frame)['data'], "长度 {$len} 掩码还原失败");
        }
    }

    public function testDecodeIncompleteFrameReturnsNull(): void
    {
        $frame = WebSocketProtocol::encode('hello');

        $this->assertNull(WebSocketProtocol::decode(substr($frame, 0, 4)));
        $this->assertNull(WebSocketProtocol::decode("\x81"));
    }

    public function testDecodeControlFrames(): void
    {
        $this->assertSame('ping', WebSocketProtocol::decode(WebSocketProtocol::encodePing('hb'))['type']);
        $this->assertSame('pong', WebSocketProtocol::decode(WebSocketProtocol::encodePong('hb'))['type']);
        $this->assertSame('close', WebSocketProtocol::decode(WebSocketProtocol::encodeClose())['type']);
    }

    public function testEncodeCloseCarriesStatusCode(): void
    {
        $decoded = WebSocketProtocol::decode(WebSocketProtocol::encodeClose(1001, 'bye'));

        $this->assertSame(1001, unpack('n', substr($decoded['data'], 0, 2))[1]);
        $this->assertSame('bye', substr($decoded['data'], 2));
    }

    public function testEncodeBinaryUsesBinaryOpcode(): void
    {
        $payload = random_bytes(64);
        $decoded = WebSocketProtocol::decode(WebSocketProtocol::encodeBinary($payload));

        $this->assertSame(WebSocketProtocol::OPCODE_BINARY, $decoded['opcode']);
        $this->assertSame($payload, $decoded['data']);
    }

    public function testFragmentedFrameKeepsFinFlag(): void
    {
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, 'part', fin: 0);

        $this->assertSame(0, WebSocketProtocol::decode($frame)['fin']);
    }

    // --- 握手 --------------------------------------------------------------

    public function testAcceptKeyMatchesRfc6455Example(): void
    {
        // RFC 6455 §1.3 给出的标准样例
        $this->assertSame(
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
            WebSocketProtocol::acceptKey('dGhlIHNhbXBsZSBub25jZQ==')
        );
    }

    public function testIsHandshakeRequest(): void
    {
        $request = "GET /chat HTTP/1.1\r\nHost: a\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        $this->assertTrue(WebSocketProtocol::isHandshakeRequest($request));
        $this->assertFalse(WebSocketProtocol::isHandshakeRequest("GET / HTTP/1.1\r\nHost: a\r\n\r\n"));
        $this->assertFalse(WebSocketProtocol::isHandshakeRequest("POST / HTTP/1.1\r\n\r\n"));
    }

    public function testHandshakeResponse(): void
    {
        $request = "GET /chat HTTP/1.1\r\nHost: a\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        $response = WebSocketProtocol::handshake($request);

        $this->assertStringStartsWith("HTTP/1.1 101 Switching Protocols\r\n", $response);
        $this->assertStringContainsString('Upgrade: websocket', $response);
        $this->assertStringContainsString('Connection: Upgrade', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    public function testHandshakeSupportsExtraHeaders(): void
    {
        $request = "GET / HTTP/1.1\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        $response = WebSocketProtocol::handshake($request, ['Sec-WebSocket-Protocol' => 'chat']);

        $this->assertStringContainsString('Sec-WebSocket-Protocol: chat', $response);
    }

    public function testHandshakeReturnsNullWithoutKey(): void
    {
        $this->assertNull(WebSocketProtocol::handshake("GET / HTTP/1.1\r\nHost: a\r\n\r\n"));
    }
}
