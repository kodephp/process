<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Rpc\RpcFrame;
use Kode\Process\Cluster\Rpc\RpcServer;
use Kode\Process\Runtime\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
}
