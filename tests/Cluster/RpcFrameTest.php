<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use JsonException;
use Kode\Process\Cluster\Rpc\RpcFrame;
use PHPUnit\Framework\TestCase;

/**
 * 集群 RPC 报文编解码。
 *
 * 用 4 字节大端长度前缀自带分帧，因此不依赖运行时是否支持定长协议——
 * Native / Swoole / Workerman 三家都能跑同一套报文。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class RpcFrameTest extends TestCase
{
    public function testEncodePrependsBigEndianTotalLength(): void
    {
        $frame = RpcFrame::encode(['a' => 1]);
        $body  = substr($frame, RpcFrame::HEAD_LEN);

        // 长度头记的是「含头总长」，不是纯报文体长度
        $this->assertSame(strlen($frame), unpack('N', substr($frame, 0, 4))[1]);
        $this->assertSame('{"a":1}', $body);
    }

    public function testRoundTrip(): void
    {
        $payload = ['i' => 'r1', 'm' => 'sum', 'p' => ['a' => 1, 'b' => 2]];
        $buffer  = RpcFrame::encode($payload);

        $this->assertSame($payload, RpcFrame::shift($buffer));
        $this->assertSame('', $buffer, '完整取出后缓冲区应清空');
    }

    public function testShiftReturnsNullOnIncompleteHeader(): void
    {
        $buffer = "\x00\x00";

        $this->assertNull(RpcFrame::shift($buffer));
        $this->assertSame("\x00\x00", $buffer, '不完整时不能吃掉缓冲区');
    }

    public function testShiftReturnsNullOnIncompleteBody(): void
    {
        $full    = RpcFrame::encode(['hello' => 'world']);
        $partial = substr($full, 0, -3);
        $buffer  = $partial;

        $this->assertNull(RpcFrame::shift($buffer));
        $this->assertSame($partial, $buffer);
    }

    public function testShiftHandlesMultipleFramesInOneBuffer(): void
    {
        // TCP 粘包：一次读到两个半报文
        $buffer = RpcFrame::encode(['n' => 1])
            . RpcFrame::encode(['n' => 2])
            . substr(RpcFrame::encode(['n' => 3]), 0, 5);

        $this->assertSame(['n' => 1], RpcFrame::shift($buffer));
        $this->assertSame(['n' => 2], RpcFrame::shift($buffer));
        $this->assertNull(RpcFrame::shift($buffer), '第三帧不完整，应留在缓冲区里等后续字节');
        $this->assertNotSame('', $buffer);
    }

    public function testOversizedFrameIsRejected(): void
    {
        $buffer = pack('N', RpcFrame::MAX_SIZE + 1) . 'x';

        $this->assertNull(RpcFrame::shift($buffer));
        $this->assertSame('', $buffer, '超长报文应丢弃缓冲区，避免被恶意长度撑爆内存');
    }

    public function testMalformedJsonThrowsAndConsumesTheFrame(): void
    {
        $body   = 'not-json';
        $buffer = pack('N', RpcFrame::HEAD_LEN + strlen($body)) . $body;

        // 调用方（RpcServer/RpcClient）都捕获 JsonException 并清缓冲、断链，
        // 所以这里抛出是预期行为，不会把异常泄进事件循环
        $this->expectException(JsonException::class);

        RpcFrame::shift($buffer);
    }

    public function testRequestHelperUsesCompactKeys(): void
    {
        $req = RpcFrame::request('r1', 'user.get', ['id' => 7]);

        // 线上格式刻意用单字母键压缩体积：i=id、m=method、p=params
        $this->assertSame('r1', $req['i']);
        $this->assertSame('user.get', $req['m']);
        $this->assertSame(['id' => 7], $req['p']);
    }

    public function testOkHelper(): void
    {
        $res = RpcFrame::ok('r1', ['name' => 'kode']);

        $this->assertSame('r1', $res['i']);
        $this->assertTrue($res['o']);
        $this->assertSame(['name' => 'kode'], $res['r']);
    }

    public function testFailHelper(): void
    {
        $res = RpcFrame::fail('r1', 'method not found');

        $this->assertSame('r1', $res['i']);
        $this->assertFalse($res['o']);
        $this->assertSame('method not found', $res['e']);
    }

    public function testHelpersSurviveTheWire(): void
    {
        $buffer = RpcFrame::encode(RpcFrame::request('r1', 'sum', ['a' => 1]))
            . RpcFrame::encode(RpcFrame::ok('r1', 3));

        $this->assertSame(RpcFrame::request('r1', 'sum', ['a' => 1]), RpcFrame::shift($buffer));
        $this->assertSame(RpcFrame::ok('r1', 3), RpcFrame::shift($buffer));
    }

    public function testUnicodePayloadSurvivesRoundTrip(): void
    {
        $payload = ['msg' => '集群广播：节点已上线'];
        $buffer  = RpcFrame::encode($payload);

        $this->assertSame($payload, RpcFrame::shift($buffer));
    }

    public function testNestedStructuresSurviveRoundTrip(): void
    {
        $payload = [
            'i' => 'r1',
            'm' => 'batch',
            'p' => ['items' => [['a' => 1], ['b' => [1, 2, 3]]], 'flag' => true],
        ];
        $buffer = RpcFrame::encode($payload);

        $this->assertSame($payload, RpcFrame::shift($buffer));
    }
}
