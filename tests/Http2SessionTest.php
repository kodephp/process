<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Hpack;
use Kode\Process\Protocol\Http2\Http2Exception;
use Kode\Process\Protocol\Http2\Http2Session;
use PHPUnit\Framework\TestCase;

/**
 * HTTP/2 连接层测试：前奏握手、流状态机、头块拼接、流控与错误分级。
 *
 * 这里把 Session 当成纯字节的状态机来测（喂字节 → 收请求 / 看 drain 出来的帧），
 * 不牵扯 socket，因此可以精确构造半包、越界、超限等真实链路上难复现的场景。
 */
final class Http2SessionTest extends TestCase
{
    /** 客户端侧 HPACK 编码器（与 Session 内的 decoder 相互独立） */
    private Hpack $client;

    protected function setUp(): void
    {
        $this->client = new Hpack();
    }

    /**
     * 构造一个 HEADERS 帧。
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private function headersFrame(int $stream, array $headers, int $flags): string
    {
        return Frame::encode(Frame::TYPE_HEADERS, $flags, $stream, $this->client->encode($headers));
    }

    /** @return list<array{0: string, 1: string}> 一组最小可用的请求伪头 */
    private static function getHeaders(string $path = '/', string $authority = 'example.com'): array
    {
        return [
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', $authority],
        ];
    }

    /** 完成前奏握手并清空自动发出的 SETTINGS */
    private function handshake(Http2Session $session): void
    {
        $session->feed(Frame::PREFACE);
        $session->drain();
    }

    // -------------------------------------------------- 安全：洪泛防护

    /**
     * Rapid Reset（CVE-2023-44487）：只开流不完成、立刻 RST_STREAM 的连接
     * 必须在预算耗尽后被 GOAWAY(ENHANCE_YOUR_CALM) 掐断。
     */
    public function testRapidResetFloodTriggersGoaway(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $limit  = $session->stats()['reset_limit'];
        $stream = 1;

        for ($i = 0; $i <= $limit; $i++) {
            $session->feed($this->headersFrame($stream, self::getHeaders(), Frame::FLAG_END_HEADERS));
            $session->feed(Frame::rstStream($stream, Frame::ERROR_CANCEL));
            $stream += 2;
        }

        $this->assertTrue($session->isClosed(), 'RST_STREAM 洪泛必须导致连接关闭');
        $this->assertStringContainsString(
            "\x0b",
            $session->drain(),
            'GOAWAY 必须携带 ENHANCE_YOUR_CALM(0xb)'
        );
    }

    /**
     * 正常客户端偶尔取消请求不得被误判：完成一条流会抵扣一点预算，
     * 因此「完成一条 + 取消一条」交替进行时预算稳定在 0 附近。
     */
    public function testOccasionalResetIsNotTreatedAsFlood(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $limit  = $session->stats()['reset_limit'];
        $stream = 1;

        for ($i = 0; $i <= $limit * 2; $i++) {
            // 一条正常完成
            $session->feed($this->headersFrame(
                $stream,
                self::getHeaders(),
                Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
            ));
            $session->respond($stream, 200, ['content-type' => 'text/plain'], 'ok');
            $stream += 2;

            // 一条被取消
            $session->feed($this->headersFrame($stream, self::getHeaders(), Frame::FLAG_END_HEADERS));
            $session->feed(Frame::rstStream($stream, Frame::ERROR_CANCEL));
            $stream += 2;

            $session->drain();
        }

        $this->assertFalse($session->isClosed(), '正常取消不得被判为洪泛');
        $this->assertLessThanOrEqual(1, $session->stats()['reset_budget'], '预算应被完成的流抵扣');
    }

    /**
     * PING 洪泛：对端只发不读时，排队的 ACK 不得无限堆积。
     */
    public function testPingFloodTriggersGoaway(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        // 不调用 drain()，模拟对端只灌不读
        for ($i = 0; $i < 1200; $i++) {
            $session->feed(Frame::encode(Frame::TYPE_PING, 0, 0, '12345678'));
        }

        $this->assertTrue($session->isClosed(), 'PING 洪泛必须导致连接关闭');
    }

    /**
     * drain() 表示缓冲已交给传输层，控制帧排队计数随之归零，
     * 因此正常的心跳 PING（发一次读一次）永远不会触发洪泛防护。
     */
    public function testDrainResetsControlFrameBudget(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        for ($i = 0; $i < 5000; $i++) {
            $session->feed(Frame::encode(Frame::TYPE_PING, 0, 0, '12345678'));
            $session->drain();
        }

        $this->assertFalse($session->isClosed(), '正常心跳不得被判为洪泛');
        $this->assertSame(0, $session->stats()['queued_control']);
    }

    // ------------------------------------------------------------ 连接前奏

    public function testPartialPrefaceIsBufferedNotRejected(): void
    {
        $session = new Http2Session();

        $this->assertSame([], $session->feed(substr(Frame::PREFACE, 0, 10)));
        $this->assertFalse($session->isPrefaceReceived());

        $session->feed(substr(Frame::PREFACE, 10));
        $this->assertTrue($session->isPrefaceReceived());
    }

    public function testInvalidPrefaceIsRejectedEarly(): void
    {
        $session = new Http2Session();

        $this->expectException(Http2Exception::class);
        // 只喂 3 字节就能判定不是 h2：不必等收满 24 字节
        $session->feed('GET');
    }

    public function testPrefaceTriggersLocalSettings(): void
    {
        $session = new Http2Session();
        $session->feed(Frame::PREFACE);

        $decoded = Frame::decode($session->drain());
        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_SETTINGS, $decoded['type'], '收到前奏后必须立即回 SETTINGS');
    }

    // -------------------------------------------------------------- 请求组装

    public function testSimpleGetRequestIsAssembled(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $requests = $session->feed($this->headersFrame(
            1,
            self::getHeaders('/hello'),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));

        $this->assertCount(1, $requests);
        $this->assertSame(1, $requests[0]['stream']);

        $req = $requests[0]['request'];
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/hello', $req['path']);
        $this->assertSame('HTTP/2', $req['protocol']);
        $this->assertSame('', $req['body']);
        // :authority 必须映射成 Host，让依赖 Host 的业务代码零改动
        $this->assertSame('example.com', $req['headers']['host']);
    }

    public function testQueryStringIsParsed(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $requests = $session->feed($this->headersFrame(
            1,
            self::getHeaders('/search?q=php&page=2'),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));

        $req = $requests[0]['request'];
        $this->assertSame('/search', $req['path']);
        $this->assertSame(['q' => 'php', 'page' => '2'], $req['query']);
        $this->assertSame($req['query'], $req['get']);
        $this->assertSame('/search?q=php&page=2', $req['uri']);
    }

    public function testPostBodyIsCollectedFromDataFrames(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $headers = [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/submit'],
            [':authority', 'example.com'],
            ['content-type', 'application/x-www-form-urlencoded'],
        ];

        // HEADERS 不带 END_STREAM → 请求尚未完整
        $this->assertSame([], $session->feed($this->headersFrame(1, $headers, Frame::FLAG_END_HEADERS)));

        $requests = $session->feed(
            Frame::encode(Frame::TYPE_DATA, 0, 1, 'name=kode&')
            . Frame::encode(Frame::TYPE_DATA, Frame::FLAG_END_STREAM, 1, 'lang=php')
        );

        $this->assertCount(1, $requests);
        $req = $requests[0]['request'];
        $this->assertSame('name=kode&lang=php', $req['body'], '多个 DATA 帧必须按序拼接');
        $this->assertSame(['name' => 'kode', 'lang' => 'php'], $req['post']);
    }

    public function testJsonBodyIsDecodedIntoPost(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $headers = [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/api'],
            [':authority', 'example.com'],
            ['content-type', 'application/json'],
        ];
        $session->feed($this->headersFrame(1, $headers, Frame::FLAG_END_HEADERS));
        $requests = $session->feed(
            Frame::encode(Frame::TYPE_DATA, Frame::FLAG_END_STREAM, 1, '{"a":1,"b":[2,3]}')
        );

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $requests[0]['request']['post']);
    }

    public function testHeaderBlockSplitAcrossContinuation(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $block = $this->client->encode(self::getHeaders('/big'));
        $half  = intdiv(strlen($block), 2);

        // HEADERS 不带 END_HEADERS，余下部分走 CONTINUATION
        $bytes = Frame::encode(Frame::TYPE_HEADERS, Frame::FLAG_END_STREAM, 1, substr($block, 0, $half))
            . Frame::encode(Frame::TYPE_CONTINUATION, Frame::FLAG_END_HEADERS, 1, substr($block, $half));

        $requests = $session->feed($bytes);

        $this->assertCount(1, $requests);
        $this->assertSame('/big', $requests[0]['request']['path']);
    }

    public function testOrphanContinuationIsProtocolError(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $this->expectException(Http2Exception::class);
        $session->feed(Frame::encode(Frame::TYPE_CONTINUATION, Frame::FLAG_END_HEADERS, 1, 'x'));
    }

    public function testRequestSurvivesByteByByteDelivery(): void
    {
        $session = new Http2Session();
        $bytes   = Frame::PREFACE . $this->headersFrame(
            1,
            self::getHeaders('/drip'),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        );

        $requests = [];
        $len      = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            foreach ($session->feed($bytes[$i]) as $r) {
                $requests[] = $r;
            }
        }

        $this->assertCount(1, $requests, '逐字节投递必须与整包投递等价');
        $this->assertSame('/drip', $requests[0]['request']['path']);
    }

    public function testTwoRequestsInOneTcpSegment(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $flags    = Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM;
        $requests = $session->feed(
            $this->headersFrame(1, self::getHeaders('/one'), $flags)
            . $this->headersFrame(3, self::getHeaders('/two'), $flags)
        );

        $this->assertCount(2, $requests);
        $this->assertSame('/one', $requests[0]['request']['path']);
        $this->assertSame('/two', $requests[1]['request']['path']);
    }

    // -------------------------------------------------------------- 流 ID 规则

    public function testEvenStreamIdIsRejected(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $this->expectException(Http2Exception::class);
        $session->feed($this->headersFrame(2, self::getHeaders(), Frame::FLAG_END_HEADERS));
    }

    public function testNonMonotonicStreamIdIsRejected(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $flags = Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM;
        $session->feed($this->headersFrame(5, self::getHeaders(), $flags));

        $this->expectException(Http2Exception::class);
        // 流 3 小于已见过的 5：RFC 7540 §5.1.1 要求单调递增
        $session->feed($this->headersFrame(3, self::getHeaders(), $flags));
    }

    public function testExceedingMaxConcurrentStreamsRefusesInsteadOfKillingConnection(): void
    {
        $session = new Http2Session(maxConcurrentStreams: 2);
        $this->handshake($session);

        // 三个流都不带 END_STREAM，保持活动状态
        $session->feed($this->headersFrame(1, self::getHeaders(), Frame::FLAG_END_HEADERS));
        $session->feed($this->headersFrame(3, self::getHeaders(), Frame::FLAG_END_HEADERS));
        $session->drain();

        $session->feed($this->headersFrame(5, self::getHeaders(), Frame::FLAG_END_HEADERS));

        $this->assertSame(2, $session->activeStreams(), '超限的流不得占用槽位');
        $this->assertFalse($session->isClosed(), '超并发是流级问题，连接必须存活');

        $decoded = Frame::decode($session->drain());
        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_RST_STREAM, $decoded['type']);
        $this->assertSame(5, $decoded['stream']);
    }

    // ---------------------------------------------------------- 连接级控制帧

    public function testPingIsAnsweredWithAck(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed(Frame::encode(Frame::TYPE_PING, 0, 0, '12345678'));

        $decoded = Frame::decode($session->drain());
        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_PING, $decoded['type']);
        $this->assertSame(Frame::FLAG_ACK, $decoded['flags']);
        $this->assertSame('12345678', $decoded['payload'], 'PING ACK 必须原样回显负载');
    }

    public function testPingAckIsNotEchoedAgain(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed(Frame::encode(Frame::TYPE_PING, Frame::FLAG_ACK, 0, '87654321'));

        $this->assertFalse($session->hasPendingOutput(), '收到 PING ACK 不得再回 ACK，否则会无限乒乓');
    }

    public function testPeerSettingsAreAcknowledged(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed(Frame::settings([Frame::SETTINGS_MAX_FRAME_SIZE => 32768]));

        $decoded = Frame::decode($session->drain());
        $this->assertNotNull($decoded);
        $this->assertSame(Frame::TYPE_SETTINGS, $decoded['type']);
        $this->assertSame(Frame::FLAG_ACK, $decoded['flags']);
        $this->assertSame(32768, $session->stats()['peer_max_frame'], '对端 SETTINGS 必须生效');
    }

    public function testSettingsAckDoesNotTriggerAnotherAck(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed(Frame::settingsAck());

        $this->assertFalse($session->hasPendingOutput());
    }

    public function testGoawayClosesSession(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed(Frame::goaway(0, Frame::ERROR_NO_ERROR));

        $this->assertTrue($session->isClosed());
        $this->assertSame([], $session->feed(Frame::PREFACE), '关闭后不再处理任何输入');
    }

    public function testRstStreamReleasesTheStream(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed($this->headersFrame(1, self::getHeaders(), Frame::FLAG_END_HEADERS));
        $this->assertSame(1, $session->activeStreams());

        $session->feed(Frame::rstStream(1, Frame::ERROR_CANCEL));

        $this->assertSame(0, $session->activeStreams());
        $this->assertFalse($session->isClosed(), 'RST_STREAM 只影响单流');
    }

    public function testPriorityFrameIsIgnoredWithoutError(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        // 本实现不做优先级调度，但必须容忍该帧
        $session->feed(Frame::encode(Frame::TYPE_PRIORITY, 0, 1, pack('NC', 0, 16)));

        $this->assertFalse($session->isClosed());
    }

    public function testUnknownFrameTypeIsIgnored(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        // RFC 7540 §4.1：必须丢弃并忽略未知类型的帧
        $session->feed(Frame::encode(0xFA, 0, 0, 'whatever'));

        $this->assertFalse($session->isClosed());
    }

    // -------------------------------------------------------------- 流控

    public function testWindowUpdateGrowsSendWindow(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $before = $session->stats()['send_window'];
        $session->feed(Frame::windowUpdate(0, 1000));

        $this->assertSame($before + 1000, $session->stats()['send_window']);
    }

    public function testZeroWindowUpdateIsProtocolError(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $this->expectException(Http2Exception::class);
        // RFC 7540 §6.9：增量为 0 是错误
        $session->feed(Frame::encode(Frame::TYPE_WINDOW_UPDATE, 0, 0, pack('N', 0)));
    }

    public function testReceivingDataReplenishesRecvWindow(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $headers = [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/upload'],
            [':authority', 'example.com'],
        ];
        $session->feed($this->headersFrame(1, $headers, Frame::FLAG_END_HEADERS));
        $session->drain();

        // 送一大块 DATA，触发窗口补充逻辑
        $session->feed(Frame::encode(Frame::TYPE_DATA, 0, 1, str_repeat('x', 16384)));

        $this->assertGreaterThan(
            0,
            $session->stats()['recv_window'],
            '接收窗口不得被耗尽到 0，否则后续上传会永久停顿'
        );
    }

    // -------------------------------------------------------------- 响应

    public function testRespondEmitsHeadersAndData(): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        $session->feed($this->headersFrame(
            1,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $session->drain();

        $this->assertTrue($session->respond(1, 200, ['content-type' => 'text/plain'], 'hello'));

        $out    = $session->drain();
        $header = Frame::decode($out);
        $this->assertNotNull($header);
        $this->assertSame(Frame::TYPE_HEADERS, $header['type']);
        $this->assertSame(1, $header['stream']);
        $this->assertSame(Frame::FLAG_END_HEADERS, $header['flags'] & Frame::FLAG_END_HEADERS);

        $data = Frame::decode($out, $header['size']);
        $this->assertNotNull($data);
        $this->assertSame(Frame::TYPE_DATA, $data['type']);
        $this->assertSame('hello', $data['payload']);
        $this->assertSame(Frame::FLAG_END_STREAM, $data['flags'] & Frame::FLAG_END_STREAM);

        $this->assertSame(0, $session->activeStreams(), '响应结束后必须释放流');
    }

    /**
     * 回归：响应头块缓存对调用方完全透明——同一 (status, headers) 跨会话两次 respond
     * 必须产生逐字节相同的 HEADERS 头块（缓存命中不改变线格式），且解码内容正确。
     */
    public function testResponseBlockCacheIsTransparentAndCorrect(): void
    {
        $make = function () {
            $s = new Http2Session();
            $this->handshake($s);
            $s->feed($this->headersFrame(1, self::getHeaders(), Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM));
            $s->drain();
            return $s;
        };

        $headers = ['content-type' => 'text/plain', 'cache-control' => 'no-cache'];

        $s1 = $make();
        $this->assertTrue($s1->respond(1, 200, $headers, 'a'));
        $payload1 = Frame::decode($s1->drain())['payload'];

        $s2 = $make();
        $this->assertTrue($s2->respond(1, 200, $headers, 'b'));
        $payload2 = Frame::decode($s2->drain())['payload'];

        $this->assertSame($payload1, $payload2, '同一响应头组合必须编码出相同字节块（缓存命中不影响线格式）');

        $decoded = (new Hpack())->decode($payload1);
        $map = [];
        foreach ($decoded as [$name, $value]) {
            $map[$name] = $value;
        }
        $this->assertSame('200', $map[':status']);
        $this->assertSame('text/plain', $map['content-type']);
        $this->assertSame('no-cache', $map['cache-control']);
    }

    /**
     * 回归：clearResponseBlockCache() 必须让缓存可复位（测试隔离与基准冷启动需要）。
     */
    public function testResponseBlockCacheCanBeCleared(): void
    {
        Http2Session::clearResponseBlockCache();
        $make = function () {
            $s = new Http2Session();
            $this->handshake($s);
            $s->feed($this->headersFrame(1, self::getHeaders(), Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM));
            $s->drain();
            return $s;
        };

        $s1 = $make();
        $this->assertTrue($s1->respond(1, 200, ['content-type' => 'text/plain'], 'x'));
        $this->assertNotEmpty($s1->drain());

        Http2Session::clearResponseBlockCache();

        $s2 = $make();
        $this->assertTrue($s2->respond(1, 200, ['content-type' => 'text/plain'], 'y'));
        $this->assertNotEmpty($s2->drain());
    }

    /**
     * 回归：流式响应收尾时 pending 已排空，若不补一帧就没有任何帧带 END_STREAM，
     * 流会被静默关闭而客户端永远等不到响应结束（真实 curl 会挂起）。
     */
    public function testEndingStreamWithEmptyPendingStillSendsEndStream(): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        $session->feed($this->headersFrame(
            1,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));

        $session->respondHeaders(1, 200, ['content-type' => 'text/plain']);
        $session->writeData(1, 'chunk-1');
        $session->drain(); // 把头和第一块数据都取走，使 pending 归空

        // 收尾：不带新数据，只标记结束
        $session->writeData(1, '', true);

        $frame = Frame::decode($session->drain());
        $this->assertNotNull($frame, '收尾必须产生一帧，否则客户端会一直等待');
        $this->assertSame(Frame::TYPE_DATA, $frame['type']);
        $this->assertSame('', $frame['payload']);
        $this->assertSame(
            Frame::FLAG_END_STREAM,
            $frame['flags'] & Frame::FLAG_END_STREAM,
            '收尾帧必须携带 END_STREAM'
        );
        $this->assertSame(0, $session->activeStreams());
    }

    public function testEndStreamIsNotDuplicatedWhenLastChunkCarriesIt(): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        $session->feed($this->headersFrame(
            1,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $session->drain();

        // 数据与结束标记一起提交：END_STREAM 应搭在这帧数据上，不应再补空帧
        $session->respond(1, 200, [], 'body');

        $out    = $session->drain();
        $offset = 0;
        $data   = [];
        while (($f = Frame::decode($out, $offset, Frame::MAX_MAX_FRAME_SIZE)) !== null) {
            if ($f['type'] === Frame::TYPE_DATA) {
                $data[] = $f;
            }
            $offset += $f['size'];
        }

        $this->assertCount(1, $data, '不应产生多余的空 DATA 帧');
        $this->assertSame('body', $data[0]['payload']);
        $this->assertSame(Frame::FLAG_END_STREAM, $data[0]['flags'] & Frame::FLAG_END_STREAM);
    }

    public function testRespondOnUnknownStreamIsRejected(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $this->assertFalse($session->respond(99, 200, [], 'x'), '未知流上的响应必须被拒绝而不是抛异常');
    }

    public function testLargeBodyIsSplitByPeerMaxFrameSize(): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        $session->feed($this->headersFrame(
            1,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $session->drain();

        $body = str_repeat('a', Frame::MIN_MAX_FRAME_SIZE * 2 + 100);
        $session->respond(1, 200, [], $body);

        $out    = $session->drain();
        $offset = 0;
        $seen   = '';
        $frames = 0;
        while (($f = Frame::decode($out, $offset, Frame::MAX_MAX_FRAME_SIZE)) !== null) {
            if ($f['type'] === Frame::TYPE_DATA) {
                $this->assertLessThanOrEqual(
                    Frame::MIN_MAX_FRAME_SIZE,
                    strlen($f['payload']),
                    'DATA 帧不得超过对端通告的 max frame size'
                );
                $seen .= $f['payload'];
                $frames++;
            }
            $offset += $f['size'];
        }

        $this->assertGreaterThan(1, $frames, '超长响应体必须被切成多帧');
        $this->assertSame($body, $seen, '切帧后拼回来必须与原始响应体一致');
    }

    // ---------------------------------------------------------- h2c 升级

    public function testAdoptUpgradedRequestOpensStreamOne(): void
    {
        $session = new Http2Session();
        $session->markPrefaceReceived();

        $result = $session->adoptUpgradedRequest([
            'method'  => 'GET',
            'uri'     => '/upgraded',
            'headers' => [
                'Host'       => 'example.com',
                'Connection' => 'Upgrade, HTTP2-Settings', // 逐跳头必须被剥离
                'User-Agent' => 'curl/8',
            ],
            'body'    => '',
        ]);

        $this->assertSame(1, $result['stream'], 'h2c 升级后的请求固定落在流 1');
        $this->assertSame('/upgraded', $result['request']['path']);
        $this->assertSame('HTTP/2', $result['request']['protocol']);
        $this->assertSame('example.com', $result['request']['headers']['host'], '头名必须归一为小写');
        $this->assertArrayNotHasKey(
            'connection',
            $result['request']['headers'],
            'RFC 7540 §8.1.2.2：逐跳头在 HTTP/2 中被禁止，升级时必须剥离'
        );
        $this->assertSame(1, $session->activeStreams());
    }

    // ------------------------------------------------------- 响应头书写形式

    /**
     * 响应头允许重复（多个 Set-Cookie 是刚需），单纯的 name => value
     * 表达不了，因此 respond() 额外接受「值为数组」与「列表对」两种写法。
     *
     * @return array<string, array{0: array<mixed, mixed>}>
     */
    public static function multiValueHeaderForms(): array
    {
        return [
            '值为数组'  => [['set-cookie' => ['sid=a', 'csrf=b']]],
            '列表对'    => [[['set-cookie', 'sid=a'], ['set-cookie', 'csrf=b']]],
        ];
    }

    /**
     * @param array<mixed, mixed> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('multiValueHeaderForms')]
    public function testDuplicateResponseHeadersArePreserved(array $headers): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        $session->feed($this->headersFrame(
            1,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $session->drain();

        $session->respond(1, 200, $headers, '');

        $frame = Frame::decode($session->drain());
        $this->assertNotNull($frame);
        // 用独立解码器还原头块，验证两个 set-cookie 都在且保序
        $decoded = (new Hpack())->decode($frame['payload']);

        $cookies = [];
        foreach ($decoded as [$name, $value]) {
            if ($name === 'set-cookie') {
                $cookies[] = $value;
            }
        }

        $this->assertSame(['sid=a', 'csrf=b'], $cookies, '同名响应头不得被覆盖或塌缩');
    }

    public function testHopByHopResponseHeadersAreStripped(): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        $session->feed($this->headersFrame(
            1,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $session->drain();

        $session->respond(1, 200, [
            'Content-Type'      => 'text/plain',
            'Connection'        => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
        ], '');

        $frame = Frame::decode($session->drain());
        $this->assertNotNull($frame);
        $names = array_column((new Hpack())->decode($frame['payload']), 0);

        $this->assertContains('content-type', $names);
        $this->assertNotContains('connection', $names);
        $this->assertNotContains('transfer-encoding', $names);
    }

    public function testApplyUpgradeSettingsAcceptsBase64Url(): void
    {
        $session = new Http2Session();
        // HTTP2-Settings 头是 base64url 编码的 SETTINGS 负载（不含帧头）
        $payload = pack('nN', Frame::SETTINGS_MAX_FRAME_SIZE, 32768);
        $session->applyUpgradeSettings(rtrim(strtr(base64_encode($payload), '+/', '-_'), '='));

        $this->assertSame(32768, $session->stats()['peer_max_frame']);
    }

    // ----------------------------------------------------- CONTINUATION 洪泛防护

    /**
     * 头块序列由许多「单帧合法但累计超限」的 HEADERS+CONTINUATION 拼成：超过体积上限即 RST，
     * 不进入 HPACK 解码，不在内存里无限累积（单帧本身都小于等于 maxFrameSize，绕过了帧层校验）。
     */
    public function testOversizedHeaderBlockIsRejectedWithoutAccumulating(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        // 每段都远低于单帧上限（默认 16 KiB），但累计远超头块上限（64 KiB）
        $chunk = str_repeat('x', 16380);

        $session->feed(Frame::encode(
            Frame::TYPE_HEADERS,
            Frame::FLAG_END_STREAM,           // 不含 END_HEADERS
            1,
            $chunk
        ));
        for ($i = 0; $i < 4; $i++) {          // 累计 5 段 ≈ 80 KiB > 64 KiB
            $session->feed(Frame::encode(
                Frame::TYPE_CONTINUATION,
                0,
                1,
                $chunk
            ));
        }

        $this->assertSame(0, $session->activeStreams(), '被拒绝的流不得残留');

        $out = $session->drain();
        $this->assertNotSame('', $out, '应写出 RST_STREAM');
        $rst = Frame::decode($out);
        $this->assertSame(Frame::TYPE_RST_STREAM, $rst['type']);
        $this->assertSame(Frame::ERROR_PROTOCOL, unpack('N', $rst['payload'])[1]);
    }

    /**
     * HEADERS + 大量无 END_HEADERS 的 CONTINUATION：超过帧数上限即 RST，防止逐帧累积耗尽内存。
     */
    public function testContinuationFloodIsRejected(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $session->feed(Frame::encode(
            Frame::TYPE_HEADERS,
            Frame::FLAG_END_STREAM,           // 不含 END_HEADERS
            1,
            'partial-headers'
        ));

        // 16 个 CONTINUATION（均无 END_HEADERS）：第 16 个使累计帧数达到上限 → 被拒绝
        for ($i = 0; $i < 16; $i++) {
            $session->feed(Frame::encode(
                Frame::TYPE_CONTINUATION,
                0,
                1,
                'fragment-' . $i
            ));
        }

        $this->assertSame(0, $session->activeStreams(), '洪泛流必须被丢弃');
        $out = $session->drain();
        $this->assertNotSame('', $out, '应写出 RST_STREAM');
        $rst = Frame::decode($out);
        $this->assertSame(Frame::TYPE_RST_STREAM, $rst['type']);
        $this->assertSame(Frame::ERROR_PROTOCOL, unpack('N', $rst['payload'])[1]);

        // 洪泛之后连接仍可正常服务新流（隔离性）
        $ok = $session->feed($this->headersFrame(
            3,
            self::getHeaders('/after'),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $this->assertCount(1, $ok);
    }

    // ----------------------------------------- SETTINGS_MAX_HEADER_LIST_SIZE 防御

    /**
     * 未压缩头列表超过本端通告上限即 RST 该流（RFC 7540 §6.5.2）。
     * 把上限压到 200 字节，构造 4 伪头 + 1 自定义头（累计 ≈220 字节）触发拒绝。
     */
    public function testOversizedHeaderListIsRejected(): void
    {
        $session = new Http2Session(maxHeaderListSize: 200);
        $this->handshake($session);

        $requests = $session->feed($this->headersFrame(
            1,
            array_merge(self::getHeaders('/'), [['x-test', 'hello']]),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));

        // 超限即拒：不组装请求、流被回收
        $this->assertSame([], $requests);
        $this->assertSame(0, $session->activeStreams());

        $out = $session->drain();
        $this->assertNotSame('', $out, '超限必须写出 RST_STREAM');
        $rst = Frame::decode($out);
        $this->assertSame(Frame::TYPE_RST_STREAM, $rst['type']);
        $this->assertSame(Frame::ERROR_PROTOCOL, unpack('N', $rst['payload'])[1]);
    }

    /** 未压缩头列表低于上限时正常组装，不能误伤合法请求 */
    public function testHeaderListBelowLimitIsAccepted(): void
    {
        $session = new Http2Session(maxHeaderListSize: 200);
        $this->handshake($session);

        // 仅 4 个伪头（累计 ≈177 字节）低于 200，应正常组装
        $requests = $session->feed($this->headersFrame(
            1,
            self::getHeaders('/'),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));

        $this->assertCount(1, $requests);
        $this->assertSame('/', $requests[0]['request']['path']);
    }

    /** 本端 SETTINGS 必须通告 MAX_HEADER_LIST_SIZE，让对端知晓上限 */
    public function testMaxHeaderListSizeAnnouncedInSettings(): void
    {
        $session = new Http2Session(maxHeaderListSize: 8192);
        $session->markPrefaceReceived();
        $session->sendLocalSettings();
        $out = $session->drain();

        // 缓冲里可能还含一条 WINDOW_UPDATE（initialWindow 高于默认时），
        // 只取首个 SETTINGS 帧解析其负载。
        $frame = Frame::decode($out);
        $this->assertSame(Frame::TYPE_SETTINGS, $frame['type']);
        $settings = Frame::decodeSettings($frame['payload']);
        $this->assertSame(8192, $settings[Frame::SETTINGS_MAX_HEADER_LIST_SIZE] ?? null);
    }

    // ------------------------------------------------- 待发流索引（flushPending）

    /**
     * 把 drain 出来的 DATA 负载按流拼回去，顺带断言 END_STREAM 只出现一次。
     *
     * @return array{body: string, ended: bool}
     */
    private static function collectData(string $out, int $streamId): array
    {
        $offset = 0;
        $body   = '';
        $ended  = 0;
        while (($f = Frame::decode($out, $offset, Frame::MAX_MAX_FRAME_SIZE)) !== null) {
            if ($f['type'] === Frame::TYPE_DATA && $f['stream'] === $streamId) {
                $body .= $f['payload'];
                if (($f['flags'] & Frame::FLAG_END_STREAM) !== 0) {
                    $ended++;
                }
            }
            $offset += $f['size'];
        }

        self::assertLessThanOrEqual(1, $ended, 'END_STREAM 不得重复发送');

        return ['body' => $body, 'ended' => $ended === 1];
    }

    /** 打开一条流并清空自动帧 */
    private function openStream(Http2Session $session, int $streamId): void
    {
        $this->handshake($session);
        $session->feed($this->headersFrame(
            $streamId,
            self::getHeaders(),
            Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
        ));
        $session->drain();
    }

    /**
     * 发送窗口卡住时，未发完的流必须留在待发索引里；窗口回补后续发完毕、
     * 索引清空，且分批发出的字节拼回来与原始响应体逐字节一致。
     */
    public function testPendingStreamIsTrackedUntilWindowAllowsFullFlush(): void
    {
        $session = new Http2Session();
        $this->openStream($session, 1);

        // 对端默认发送窗口 65535，响应体远大于它 → 必然发不完
        $body = str_repeat('z', 200000);
        $session->respond(1, 200, ['content-type' => 'text/plain'], $body);

        $seen = self::collectData($session->drain(), 1)['body'];
        $this->assertSame(65535, strlen($seen), '首轮只能发满一个发送窗口');
        $this->assertSame(1, $session->stats()['pending_streams'], '未发完的流必须留在待发索引');
        $this->assertSame(0, $session->stats()['send_window']);

        // 分多次回补窗口，逐段续发
        $ended = false;
        for ($i = 0; $i < 10; $i++) {
            $session->feed(Frame::windowUpdate(0, 20000));
            $session->feed(Frame::windowUpdate(1, 20000));
            $chunk = self::collectData($session->drain(), 1);
            $seen .= $chunk['body'];
            $ended = $ended || $chunk['ended'];
        }

        $this->assertSame($body, $seen, '分批续发拼回来必须与原始响应体一致');
        $this->assertTrue($ended, '发完后必须带上 END_STREAM');
        $this->assertSame(0, $session->stats()['pending_streams'], '发完即退出待发索引');
        $this->assertSame(0, $session->stats()['streams'], '流已关闭回收');
    }

    /** 发送途中被 RST_STREAM 打断：索引摘除，之后回补窗口不得再吐该流的数据 */
    public function testResetDuringSendClearsPendingStreamIndex(): void
    {
        $session = new Http2Session();
        $this->openStream($session, 1);

        $session->respond(1, 200, [], str_repeat('q', 200000));
        $session->drain();
        $this->assertSame(1, $session->stats()['pending_streams']);

        $session->feed(Frame::rstStream(1, Frame::ERROR_CANCEL));
        $this->assertSame(0, $session->stats()['pending_streams'], 'RST 后待发索引必须摘除');
        $this->assertSame(0, $session->stats()['streams']);

        $session->drain();
        $session->feed(Frame::windowUpdate(0, 500000));
        $this->assertSame('', self::collectData($session->drain(), 1)['body'], '已重置的流不得再发数据');
    }

    /** 空闲连接（没有任何待发数据）收到 WINDOW_UPDATE 时不应产生任何输出 */
    public function testWindowUpdateOnIdleSessionProducesNoOutput(): void
    {
        $session = new Http2Session();
        $this->handshake($session);
        for ($id = 1; $id <= 9; $id += 2) {
            $session->feed($this->headersFrame($id, self::getHeaders(), Frame::FLAG_END_HEADERS));
        }
        $session->drain();

        $session->feed(Frame::windowUpdate(0, 1024));
        $this->assertSame('', $session->drain(), '无待发数据时 WINDOW_UPDATE 不应触发任何帧');
        $this->assertSame(0, $session->stats()['pending_streams']);
    }

    /** 多流并发发送：各流数据互不串扰，窗口回补后都能完整送达 */
    public function testConcurrentStreamsFlushIndependently(): void
    {
        $session = new Http2Session();
        $this->handshake($session);

        $bodies = [];
        for ($id = 1; $id <= 5; $id += 2) {
            $session->feed($this->headersFrame(
                $id,
                self::getHeaders('/s' . $id),
                Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM
            ));
            $bodies[$id] = str_repeat((string) $id, 90000);
        }
        $session->drain();

        foreach ($bodies as $id => $body) {
            $session->respond($id, 200, [], $body);
        }

        $seen = [1 => '', 3 => '', 5 => ''];
        $out  = $session->drain();
        foreach ($seen as $id => $_) {
            $seen[$id] = self::collectData($out, $id)['body'];
        }

        $this->assertSame(3, $session->stats()['pending_streams']);

        for ($r = 0; $r < 12; $r++) {
            $session->feed(Frame::windowUpdate(0, 40000));
            foreach ($bodies as $id => $_) {
                $session->feed(Frame::windowUpdate($id, 40000));
            }
            $out = $session->drain();
            foreach ($bodies as $id => $_) {
                $seen[$id] .= self::collectData($out, $id)['body'];
            }
        }

        foreach ($bodies as $id => $body) {
            $this->assertSame($body, $seen[$id], "流 {$id} 的响应体必须完整且不串流");
        }
        $this->assertSame(0, $session->stats()['pending_streams']);
    }

    /** writeData 流式分片同样走待发索引：中途空片、末片带 end 都要正确收口 */
    public function testStreamingWriteDataTracksPendingIndex(): void
    {
        $session = new Http2Session();
        $this->openStream($session, 1);

        $session->respondHeaders(1, 200, ['x-stream' => 'on']);
        $expected = '';
        $seen     = '';

        for ($i = 0; $i < 6; $i++) {
            $chunk     = str_repeat(chr(97 + $i), 20000);
            $expected .= $chunk;
            $session->writeData(1, $chunk);
            $seen .= self::collectData($session->drain(), 1)['body'];

            $session->feed(Frame::windowUpdate(0, 20000));
            $session->feed(Frame::windowUpdate(1, 20000));
            $seen .= self::collectData($session->drain(), 1)['body'];
        }

        $session->writeData(1, '');                 // 空片不应改变任何东西
        $session->writeData(1, 'tail', true);
        $last  = self::collectData($session->drain(), 1);
        $seen .= $last['body'];

        $this->assertSame($expected . 'tail', $seen);
        $this->assertTrue($last['ended'], '末片必须带 END_STREAM');
        $this->assertSame(0, $session->stats()['pending_streams']);
        $this->assertSame(0, $session->stats()['streams']);
    }
}
