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
     * 服务端帧（MASK=0）作为入向帧非法：RFC 6455 §5.1 要求服务端断开未掩码的客户端帧。
     *
     * 兼带守住历史缺陷——旧实现读第一字节最高位（FIN）当掩码位，
     * 这条 FIN=1 / MASK=0 的帧在旧实现里会被误判为「已掩码」而放行。
     */
    public function testInputRejectsUnmaskedFrame(): void
    {
        $frame = WebSocketProtocol::encode('hello');

        // 2 字节头 + 5 字节载荷 = 7，帧本身完整，但服务端仍必须拒绝
        $this->assertSame(7, strlen($frame));
        $this->assertSame(-1, WebSocketProtocol::input($frame));
    }

    public function testInputMatchesFrameLengthForClientFrames(): void
    {
        foreach (['', 'a', 'hello world', str_repeat('x', 125)] as $payload) {
            $client = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, $payload);

            $this->assertSame(strlen($client), WebSocketProtocol::input($client));
        }
    }

    public function testInputHandles16BitExtendedLength(): void
    {
        $client = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, str_repeat('a', 1000));

        // 2 字节头 + 2 字节扩展长度 + 4 字节掩码 + 1000 字节载荷
        $this->assertSame(1008, WebSocketProtocol::input($client));
    }

    public function testInputHandles64BitExtendedLength(): void
    {
        $client = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, str_repeat('a', 70000));

        // 2 字节头 + 8 字节扩展长度 + 4 字节掩码 + 70000 字节载荷
        $this->assertSame(70014, WebSocketProtocol::input($client));
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

    // --- 分包到达（RFC 6455 §5.2 分阶段校验） ------------------------------

    /**
     * 模拟 NativeRuntime 的收包循环：input() 报 0 就继续等更多数据，
     * 报正数就按该长度切片交给 decode()，剩余字节留在缓冲区。
     *
     * @param list<string> $reads 依次到达的 TCP 分片
     * @return list<mixed> 派发出去的消息
     */
    private static function dispatch(array $reads): array
    {
        $buffer = '';
        $out = [];

        foreach ($reads as $chunk) {
            $buffer .= $chunk;

            while ($buffer !== '') {
                $len = WebSocketProtocol::input($buffer);

                if ($len === 0) {
                    break;
                }

                self::assertGreaterThan(0, $len, 'input() 误报协议错误');
                self::assertLessThanOrEqual(
                    strlen($buffer),
                    $len,
                    'input() 返回的长度超过当前缓冲区，调用方按此切片会把尚未到达的字节当成已消费'
                );

                $out[] = WebSocketProtocol::decode(substr($buffer, 0, $len));
                $buffer = substr($buffer, $len);
            }
        }

        return $out;
    }

    /**
     * 回归：>64KB 的帧必定走 127 扩展长度且必定跨多次 TCP 读到达。
     *
     * 旧实现在帧未收全时照样返回完整帧长，调用方 substr 后缓冲区被清空，
     * 整帧连同后续分片一起丢失。
     */
    public function testLargeFrameSplitAcrossReadsIsFullyReassembled(): void
    {
        $payload = random_bytes(100000);
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_BINARY, $payload);

        $reads = str_split($frame, 8192);
        $this->assertGreaterThan(1, count($reads), '用例前提：该帧必须被拆成多次读');

        $messages = self::dispatch($reads);

        $this->assertCount(1, $messages);
        $this->assertSame($payload, $messages[0]['data']);
    }

    /**
     * 分片切点精确落在基础头 / 扩展长度 / 掩码键三处边界上。
     */
    public function testLargeFrameSplitAtHeaderBoundariesIsFullyReassembled(): void
    {
        $payload = str_repeat('K', 70000);
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, $payload);

        $reads = [
            substr($frame, 0, 2),   // 仅基础头
            substr($frame, 2, 5),   // 8 字节扩展长度只到一半
            substr($frame, 7, 5),   // 扩展长度收齐，4 字节掩码键只到一半
            substr($frame, 12),     // 掩码键 + 全部负载
        ];

        $messages = self::dispatch($reads);

        $this->assertCount(1, $messages);
        $this->assertSame($payload, $messages[0]['data']);
    }

    /**
     * 大帧后面紧跟一个小帧，且两者在同一次读里到达：不能粘包也不能丢帧。
     */
    public function testLargeFrameFollowedBySmallFrameInSameRead(): void
    {
        $big = str_repeat('B', 70000);
        $wire = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, $big)
            . self::clientFrame(WebSocketProtocol::OPCODE_TEXT, 'tail');

        $messages = self::dispatch(str_split($wire, 16384));

        $this->assertCount(2, $messages);
        $this->assertSame($big, $messages[0]['data']);
        $this->assertSame('tail', $messages[1]['data']);
    }

    public function testInputWaitsForMaskKeyAndPayload(): void
    {
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, str_repeat('a', 70000));

        $this->assertSame(0, WebSocketProtocol::input(substr($frame, 0, 13)), '掩码键差 1 字节');
        $this->assertSame(0, WebSocketProtocol::input(substr($frame, 0, 14)), '负载一个字节都没到');
        $this->assertSame(0, WebSocketProtocol::input(substr($frame, 0, -1)), '负载差 1 字节');
        $this->assertSame(strlen($frame), WebSocketProtocol::input($frame));
    }

    public function testInputWaitsForPayloadWith16BitLength(): void
    {
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, str_repeat('a', 1000));

        $this->assertSame(0, WebSocketProtocol::input(substr($frame, 0, 7)), '掩码键差 1 字节');
        $this->assertSame(0, WebSocketProtocol::input(substr($frame, 0, -1)), '负载差 1 字节');
        $this->assertSame(strlen($frame), WebSocketProtocol::input($frame));
    }

    // --- 帧合法性校验（RFC 6455 §5.1 / §5.2 / §5.5） -----------------------

    public function testInputRejectsReservedBits(): void
    {
        foreach (['RSV1' => 0x40, 'RSV2' => 0x20, 'RSV3' => 0x10] as $name => $bit) {
            $frame = self::clientFrame(WebSocketProtocol::OPCODE_TEXT, 'hi');
            $frame[0] = chr(ord($frame[0]) | $bit);

            $this->assertSame(-1, WebSocketProtocol::input($frame), "{$name} 置位未被拒绝");
        }
    }

    public function testInputRejectsOversizedControlFrame(): void
    {
        foreach ([0x8, 0x9, 0xA] as $opcode) {
            $frame = self::clientFrame($opcode, str_repeat('x', 126));

            $this->assertSame(-1, WebSocketProtocol::input($frame), "opcode {$opcode} 超长控制帧未被拒绝");
        }
    }

    public function testInputAcceptsControlFrameAtSizeLimit(): void
    {
        $frame = self::clientFrame(WebSocketProtocol::OPCODE_PING, str_repeat('x', 125));

        $this->assertSame(strlen($frame), WebSocketProtocol::input($frame));
    }

    public function testInputRejectsFragmentedControlFrame(): void
    {
        foreach ([0x8, 0x9, 0xA] as $opcode) {
            $frame = self::clientFrame($opcode, 'x', fin: 0);

            $this->assertSame(-1, WebSocketProtocol::input($frame), "opcode {$opcode} 分片控制帧未被拒绝");
        }
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

    /**
     * 握手响应同样是 HTTP 报文：业务传入的附加头不得凭 CRLF 注入额外响应头。
     */
    public function testHandshakeStripsCrlfFromExtraHeaders(): void
    {
        $request = "GET / HTTP/1.1\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        $response = WebSocketProtocol::handshake($request, [
            'Sec-WebSocket-Protocol' => "chat\r\nX-Injected: evil",
        ]);

        $this->assertStringNotContainsString("\r\nX-Injected:", $response);
        $this->assertStringContainsString("Sec-WebSocket-Protocol: chatX-Injected: evil\r\n", $response);
    }
}
