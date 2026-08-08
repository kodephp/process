<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Http2Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTTP/2 帧编解码（RFC 7540 §4、§6）。
 *
 * 帧头是 9 字节定长结构，其中长度字段是 24 位——PHP 没有对应的 pack 格式，
 * 只能手工拼三个字节，正是最容易出错的地方，因此这里逐位验证。
 */
final class Http2FrameTest extends TestCase
{
    // ------------------------------------------------------------ 帧头布局

    public function testHeaderLayoutIsNineBytes(): void
    {
        $frame = Frame::encode(Frame::TYPE_DATA, Frame::FLAG_END_STREAM, 1, 'abc');

        $this->assertSame(Frame::HEADER_SIZE + 3, strlen($frame));

        // 长度 24 位大端
        $this->assertSame(0, ord($frame[0]));
        $this->assertSame(0, ord($frame[1]));
        $this->assertSame(3, ord($frame[2]));
        // 类型、标志各一字节
        $this->assertSame(Frame::TYPE_DATA, ord($frame[3]));
        $this->assertSame(Frame::FLAG_END_STREAM, ord($frame[4]));
        // 流 ID 32 位大端，最高位保留必须为 0
        $this->assertSame(1, unpack('N', substr($frame, 5, 4))[1]);
        $this->assertSame('abc', substr($frame, 9));
    }

    /**
     * 24 位长度字段的三字节拼装，跨字节边界的取值最容易写错。
     */
    #[DataProvider('payloadSizes')]
    public function testLengthFieldRoundTrip(int $size): void
    {
        $payload = str_repeat('x', $size);
        $frame   = Frame::encode(Frame::TYPE_DATA, 0, 1, $payload);
        $decoded = Frame::decode($frame, 0, 1 << 20);

        $this->assertNotNull($decoded);
        $this->assertSame($size, strlen($decoded['payload']));
        $this->assertSame(Frame::HEADER_SIZE + $size, $decoded['size']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function payloadSizes(): array
    {
        return [
            '空负载'       => [0],
            '1 字节'       => [1],
            '255（低位满）' => [255],
            '256（进位）'   => [256],
            '65535'        => [65535],
            '65536（再进位）' => [65536],
        ];
    }

    /**
     * 流 ID 最高位是保留位，编码时必须被清零（RFC 7540 §4.1）。
     */
    public function testReservedBitOfStreamIdIsCleared(): void
    {
        $frame   = Frame::encode(Frame::TYPE_DATA, 0, 0x7FFFFFFF, '');
        $decoded = Frame::decode($frame);

        $this->assertNotNull($decoded);
        $this->assertSame(0x7FFFFFFF, $decoded['stream']);

        // 传入越界值时高位被截断，不应污染类型/标志字段
        $frame2   = Frame::encode(Frame::TYPE_DATA, 0, 0x8000_0001, '');
        $decoded2 = Frame::decode($frame2);
        $this->assertNotNull($decoded2);
        $this->assertSame(1, $decoded2['stream']);
    }

    // ------------------------------------------------------------ 粘包/半包

    public function testDecodeReturnsNullWhenIncomplete(): void
    {
        $frame = Frame::encode(Frame::TYPE_DATA, 0, 1, 'hello');

        // 帧头都没收全
        $this->assertNull(Frame::decode(substr($frame, 0, 5)));
        // 帧头齐了但负载不全
        $this->assertNull(Frame::decode(substr($frame, 0, Frame::HEADER_SIZE + 2)));
        // 收齐才返回
        $this->assertNotNull(Frame::decode($frame));
    }

    /**
     * 一次读到多帧时按 offset 连续解析，size 必须能准确推进游标。
     */
    public function testDecodeMultipleFramesByOffset(): void
    {
        $buffer = Frame::encode(Frame::TYPE_SETTINGS, 0, 0, '')
            . Frame::encode(Frame::TYPE_DATA, Frame::FLAG_END_STREAM, 3, 'body')
            . Frame::encode(Frame::TYPE_PING, 0, 0, '12345678');

        $types  = [];
        $offset = 0;
        while (($f = Frame::decode($buffer, $offset, 1 << 20)) !== null) {
            $types[] = $f['type'];
            $offset += $f['size'];
        }

        $this->assertSame(
            [Frame::TYPE_SETTINGS, Frame::TYPE_DATA, Frame::TYPE_PING],
            $types
        );
        $this->assertSame(strlen($buffer), $offset, '游标应正好走到缓冲区末尾');
    }

    /**
     * 超过本端通告的 MAX_FRAME_SIZE 必须直接拒绝，否则等于让对端决定我们分配多大内存。
     */
    public function testOversizedFrameIsRejected(): void
    {
        $frame = Frame::encode(Frame::TYPE_DATA, 0, 1, str_repeat('x', 20000));

        $this->expectException(Http2Exception::class);
        Frame::decode($frame, 0, 16384);
    }

    // -------------------------------------------------------------- 填充

    public function testStripPadding(): void
    {
        // 1 字节填充长度 + 数据 + 填充
        $payload = chr(3) . 'data' . "\0\0\0";

        $this->assertSame('data', Frame::stripPadding($payload, 1));
    }

    public function testStripPaddingRejectsOverlongPad(): void
    {
        $this->expectException(Http2Exception::class);
        Frame::stripPadding(chr(200) . 'data', 1);
    }

    public function testStripPaddingRejectsEmptyPayload(): void
    {
        $this->expectException(Http2Exception::class);
        Frame::stripPadding('', 1);
    }

    // ------------------------------------------------------------ SETTINGS

    public function testSettingsRoundTrip(): void
    {
        $settings = [
            Frame::SETTINGS_MAX_CONCURRENT_STREAMS => 128,
            Frame::SETTINGS_INITIAL_WINDOW_SIZE    => 1048576,
            Frame::SETTINGS_MAX_FRAME_SIZE         => 16384,
        ];

        // settings() 产出的是整帧：9 字节帧头 + 3 项 × 6 字节
        $frame = Frame::settings($settings);
        $this->assertSame(Frame::HEADER_SIZE + 18, strlen($frame));

        $decoded = Frame::decode($frame);
        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_SETTINGS, $decoded['type']);
        $this->assertSame(0, $decoded['flags'], '初始 SETTINGS 不得带 ACK 标志');
        $this->assertSame(0, $decoded['stream'], 'SETTINGS 是连接级帧，流 ID 必须为 0');

        $this->assertSame($settings, Frame::decodeSettings($decoded['payload']));
    }

    public function testDecodeSettingsRejectsUnalignedPayload(): void
    {
        // 直接把整帧（27 字节）喂给只吃纯负载的 decodeSettings 是典型误用
        $this->expectException(Http2Exception::class);
        Frame::decodeSettings(Frame::settings([Frame::SETTINGS_MAX_FRAME_SIZE => 16384]));
    }

    public function testSettingsAckIsEmptyAndFlagged(): void
    {
        $decoded = Frame::decode(Frame::settingsAck());

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_SETTINGS, $decoded['type']);
        $this->assertSame(Frame::FLAG_ACK, $decoded['flags']);
        $this->assertSame('', $decoded['payload'], 'SETTINGS ACK 必须是空负载');
        $this->assertSame(0, $decoded['stream']);
    }

    // ------------------------------------------------- 控制帧构造

    public function testRstStreamCarriesErrorCode(): void
    {
        $decoded = Frame::decode(Frame::rstStream(5, Frame::ERROR_CANCEL));

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_RST_STREAM, $decoded['type']);
        $this->assertSame(5, $decoded['stream']);
        $this->assertSame(Frame::ERROR_CANCEL, unpack('N', $decoded['payload'])[1]);
    }

    public function testGoawayCarriesLastStreamAndDebugData(): void
    {
        $decoded = Frame::decode(Frame::goaway(7, Frame::ERROR_NO_ERROR, 'bye'));

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_GOAWAY, $decoded['type']);
        $this->assertSame(0, $decoded['stream'], 'GOAWAY 属于连接级，流 ID 必须为 0');

        $parts = unpack('Nlast/Nerror', substr($decoded['payload'], 0, 8));
        $this->assertSame(7, $parts['last']);
        $this->assertSame(Frame::ERROR_NO_ERROR, $parts['error']);
        $this->assertSame('bye', substr($decoded['payload'], 8));
    }

    public function testWindowUpdateCarriesIncrement(): void
    {
        $decoded = Frame::decode(Frame::windowUpdate(3, 65535));

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_WINDOW_UPDATE, $decoded['type']);
        $this->assertSame(3, $decoded['stream']);
        $this->assertSame(65535, unpack('N', $decoded['payload'])[1]);
    }

    public function testPingAckEchoesPayload(): void
    {
        $decoded = Frame::decode(Frame::pingAck('opaque!!'));

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_PING, $decoded['type']);
        $this->assertSame(Frame::FLAG_ACK, $decoded['flags']);
        $this->assertSame('opaque!!', $decoded['payload'], 'PING ACK 必须原样回显 8 字节');
    }

    public function testPrefaceConstantMatchesSpec(): void
    {
        $this->assertSame("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n", Frame::PREFACE);
        $this->assertSame(Frame::PREFACE_SIZE, strlen(Frame::PREFACE));
    }
}
