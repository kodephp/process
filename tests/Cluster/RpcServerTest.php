<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Rpc\RpcFrame;
use Kode\Process\Cluster\Rpc\RpcServer;
use Kode\Process\Runtime\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * RpcServer 测试。
 *
 * 重点验证：
 *  - handle() 对合法请求返回 ok 响应
 *  - onMessage() 对「单向通知」（空 id 请求）执行 handler 但**不回包**，避免污染长连接
 *  - onMessage() 对普通请求正常回包
 */
final class RpcServerTest extends TestCase
{
    public function testHandleReturnsOkForValidRequest(): void
    {
        $server = new RpcServer();
        $server->register('echo', static fn (array $p): array => $p);

        $resp = $server->handle(RpcFrame::request('7', 'echo', ['a' => 1]));

        $this->assertSame('7', $resp['i']);
        $this->assertTrue($resp['o']);
        $this->assertSame(['a' => 1], $resp['r']);
    }

    public function testHandleFailsForUnknownMethod(): void
    {
        $server = new RpcServer();

        $resp = $server->handle(RpcFrame::request('8', 'nope', []));

        $this->assertSame('8', $resp['i']);
        $this->assertFalse($resp['o']);
        $this->assertNotEmpty($resp['e']);
    }

    public function testOnMessageSendsResponseForNormalRequest(): void
    {
        $server = new RpcServer();
        $server->register('echo', static fn (array $p): array => $p);

        $conn = new RecordingConnection();
        $this->invokeOnMessage($server, $conn, RpcFrame::request('9', 'echo', ['a' => 1]));

        $this->assertCount(1, $conn->sent, '普通请求必须回包');
        $resp = RpcFrame::shift($conn->sent[0]);
        $this->assertSame('9', $resp['i']);
        $this->assertSame(['a' => 1], $resp['r']);
    }

    /**
     * 关键回归：单向通知（空 id）必须执行 handler，但绝不能回包——
     * 否则持久连接上的遗留响应会被下一次 call 误读，导致静默错值。
     */
    public function testOnMessageDoesNotSendForNotifyButRunsHandler(): void
    {
        $server   = new RpcServer();
        $captured = [];
        $server->register('log', static function (array $p) use (&$captured): void {
            $captured[] = $p;
        });

        $conn = new RecordingConnection();
        $this->invokeOnMessage($server, $conn, RpcFrame::request('', 'log', ['msg' => 'hi']));

        $this->assertEmpty($conn->sent, 'notify 不应回包，否则会污染长连接');
        $this->assertSame([['msg' => 'hi']], $captured, 'notify 的 handler 仍应被执行');
    }

    /**
     * 关键回归：坏帧只该被跳过，不该让它后面的正常请求全部搁浅。
     *
     * 修复前 shift() 对「半包」和「坏帧」都返回 null，onMessage 一律 return，
     * 于是同一次 TCP 读里跟在坏帧后面的请求永远等不到应答——客户端只能等超时。
     */
    public function testOnMessageSkipsBadFrameAndStillHandlesTheNextOne(): void
    {
        $server = new RpcServer();
        $server->register('echo', static fn (array $p): array => $p);

        // 合法 JSON 但不是对象的帧（标量），紧跟一条正常请求
        $bad   = '"scalar"';
        $chunk = pack('N', RpcFrame::HEAD_LEN + strlen($bad)) . $bad
            . RpcFrame::encode(RpcFrame::request('11', 'echo', ['a' => 1]));

        $conn = new RecordingConnection();
        $ref  = new ReflectionMethod($server, 'onMessage');
        $ref->setAccessible(true);
        $ref->invoke($server, $conn, $chunk);

        $this->assertCount(1, $conn->sent, '坏帧后面的正常请求必须照常回包');
        $resp = RpcFrame::shift($conn->sent[0]);
        $this->assertSame('11', $resp['i']);
        $this->assertSame(['a' => 1], $resp['r']);
    }

    public function testOnMessageWaitsForMoreBytesOnHalfFrame(): void
    {
        $server = new RpcServer();
        $server->register('echo', static fn (array $p): array => $p);

        $full = RpcFrame::encode(RpcFrame::request('12', 'echo', ['a' => 2]));
        $conn = new RecordingConnection();
        $ref  = new ReflectionMethod($server, 'onMessage');
        $ref->setAccessible(true);

        $ref->invoke($server, $conn, substr($full, 0, 6));
        $this->assertEmpty($conn->sent, '半包时不该回包');

        $ref->invoke($server, $conn, substr($full, 6));
        $this->assertCount(1, $conn->sent, '补齐后应正常应答');
    }

    // ---------------------------------------------------------------- 鉴权

    public function testWrongTokenIsRejected(): void
    {
        $server = new RpcServer('s3cret');
        $server->register('echo', static fn (array $p): array => $p);

        $resp = $server->handle(RpcFrame::request('1', 'echo', ['_token' => 'wrong']));

        $this->assertFalse($resp['o']);
        $this->assertSame('鉴权失败', $resp['e']);
    }

    public function testMissingTokenIsRejected(): void
    {
        $server = new RpcServer('s3cret');
        $server->register('echo', static fn (array $p): array => $p);

        $resp = $server->handle(RpcFrame::request('1', 'echo', []));

        $this->assertFalse($resp['o']);
    }

    /**
     * 鉴权串属于协议层，不该漏进业务处理器——否则一次参数回显、一行请求日志
     * 就能把集群令牌泄出去。
     */
    public function testTokenIsStrippedBeforeReachingHandler(): void
    {
        $server   = new RpcServer('s3cret');
        $received = null;
        $server->register('echo', static function (array $p) use (&$received): array {
            $received = $p;

            return $p;
        });

        $resp = $server->handle(RpcFrame::request('1', 'echo', ['a' => 1, '_token' => 's3cret']));

        $this->assertTrue($resp['o']);
        $this->assertSame(['a' => 1], $received, 'handler 不该看到 _token');
        $this->assertArrayNotHasKey('_token', (array) $resp['r'], '响应里更不能把令牌带回去');
    }

    /**
     * 处理器抛异常时只回一句通用错误：内部消息常带表名、文件路径、内网地址，
     * 直接回给调用方等于免费送情报。
     */
    public function testHandlerExceptionYieldsGenericMessage(): void
    {
        $server = new RpcServer();
        $server->register('boom', static function (): void {
            throw new RuntimeException('SQLSTATE[42S02]: /var/www/app/Repo.php 表 users_secret 不存在');
        });

        $log = tempnam(sys_get_temp_dir(), 'kode-rpc-log');
        $old = ini_set('error_log', (string) $log);

        try {
            $resp = $server->handle(RpcFrame::request('1', 'boom', []));
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }

        $this->assertFalse($resp['o']);
        $this->assertSame('服务端内部错误', $resp['e']);
        $this->assertStringNotContainsString('users_secret', $resp['e']);

        $this->assertStringContainsString(
            'users_secret',
            (string) file_get_contents((string) $log),
            '细节应落到本地日志，方便排查'
        );

        @unlink((string) $log);
    }

    private function invokeOnMessage(RpcServer $server, ConnectionInterface $conn, array $request): void
    {
        $ref = new ReflectionMethod($server, 'onMessage');
        $ref->setAccessible(true);
        $ref->invoke($server, $conn, RpcFrame::encode($request));
    }
}

/**
 * 记录所有 send() 调用的测试用连接。
 *
 * @internal
 */
final class RecordingConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $sent = [];

    public int $id = 1;

    public function id(): int
    {
        return $this->id;
    }

    public function send(string $data, bool $raw = false): bool
    {
        $this->sent[] = $data;

        return true;
    }

    public function close(?string $data = null): void
    {
    }

    public function remoteAddress(): string
    {
        return '127.0.0.1:1234';
    }

    public function localAddress(): string
    {
        return '127.0.0.1:9700';
    }

    public function isAlive(): bool
    {
        return true;
    }

    public function native(): mixed
    {
        return null;
    }

    public function setContext(string $key, mixed $value): void
    {
    }

    public function getContext(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function chunk(string $data): bool
    {
        $this->sent[] = $data;

        return true;
    }

    public function beginChunked(int $status = 200, array $headers = []): bool
    {
        return true;
    }

    public function endChunk(): bool
    {
        return true;
    }

    public function isChunkStarted(): bool
    {
        return false;
    }

    public function gzip(string $data, int $status = 200, array $headers = []): bool
    {
        $this->sent[] = $data;

        return true;
    }

    public function setGzipAuto(bool $enabled): void
    {
    }

    public function isGzipAuto(): bool
    {
        return false;
    }
}
