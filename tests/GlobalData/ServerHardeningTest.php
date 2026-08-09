<?php

declare(strict_types=1);

namespace Kode\Process\Tests\GlobalData;

use Kode\Process\GlobalData\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * GlobalData\Server 加固回归测试：帧长度上限、可选鉴权、完整写。
 *
 * 直接驱动服务端的内部收发流程（socketpair + 反射），
 * 无需真正 bind 端口，也就不会受 CI 环境端口占用影响。
 */
#[Group('globaldata')]
final class ServerHardeningTest extends TestCase
{
    /** @var \Socket[] */
    private array $sockets = [];

    protected function setUp(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('需要 sockets 扩展');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            // 服务端可能已经主动关闭过（例如超长帧断连），重复关闭会抛错
            try {
                @socket_close($socket);
            } catch (\Throwable) {
                // 忽略：已关闭
            }
        }
        $this->sockets = [];
    }

    /**
     * 缺陷复现：长度前缀是对端完全可控的 32 位无符号数，旧实现不设上限，
     * 攻击者发一个 0xFFFFFFFF 就能让服务端一直累积缓冲区等待「后续数据」直到 OOM。
     */
    public function testOversizedFrameLengthClosesConnection(): void
    {
        $server = new Server();
        $id = $this->attachClient($server);

        $this->setClientBuffer($server, $id, pack('N', 0xFFFFFFFF) . 'junk');
        $this->drainFrames($server, $id);

        self::assertSame([], $this->clients($server), '超出帧长上限必须立即断连，而不是继续攒缓冲区');
    }

    /**
     * 边界：恰好等于上限的帧属于合法帧，只是还没收齐，应继续等待而不是断连。
     */
    public function testFrameLengthAtCapIsAccepted(): void
    {
        $server = new Server();
        $id = $this->attachClient($server);

        $this->setClientBuffer($server, $id, pack('N', Server::MAX_FRAME_SIZE) . 'partial');
        $this->drainFrames($server, $id);

        self::assertArrayHasKey($id, $this->clients($server), '等于上限的帧应继续等待收齐');
    }

    /**
     * 超过上限 1 字节即断连。
     */
    public function testFrameLengthJustAboveCapIsRejected(): void
    {
        $server = new Server();
        $id = $this->attachClient($server);

        $this->setClientBuffer($server, $id, pack('N', Server::MAX_FRAME_SIZE + 1));
        $this->drainFrames($server, $id);

        self::assertSame([], $this->clients($server));
    }

    /**
     * 未配置 token 时保持原有行为，不做任何鉴权（向后兼容）。
     */
    public function testAuthIsDisabledByDefault(): void
    {
        $server = new Server();

        $response = $this->handleRequest($server, ['action' => 'set', 'key' => 'k', 'value' => 1]);

        self::assertTrue($response['success']);
    }

    /**
     * 配置了 token 后，未携带 token 的请求必须被拒绝——
     * 该服务持有集群锁 / 选举状态，无鉴权等于把这些状态开放给任何能连上端口的人。
     */
    public function testAuthRejectsRequestWithoutToken(): void
    {
        $server = new Server('127.0.0.1', 2207, 's3cr3t');

        $response = $this->handleRequest($server, ['action' => 'set', 'key' => 'k', 'value' => 1]);

        self::assertFalse($response['success']);
        self::assertSame('Unauthorized', $response['error']);
        self::assertSame([], $server->getData(), '鉴权失败的请求不得改动数据');
    }

    public function testAuthRejectsRequestWithWrongToken(): void
    {
        $server = new Server('127.0.0.1', 2207, 's3cr3t');

        $response = $this->handleRequest($server, ['action' => 'get', 'key' => 'k', '_token' => 'wrong']);

        self::assertFalse($response['success']);
        self::assertSame('Unauthorized', $response['error']);
    }

    public function testAuthAcceptsRequestWithValidToken(): void
    {
        $server = new Server('127.0.0.1', 2207, 's3cr3t');

        $set = $this->handleRequest($server, [
            'action' => 'set',
            'key' => 'k',
            'value' => 'v',
            '_token' => 's3cr3t',
        ]);
        self::assertTrue($set['success']);

        $get = $this->handleRequest($server, ['action' => 'get', 'key' => 'k', '_token' => 's3cr3t']);
        self::assertTrue($get['success']);
        self::assertSame('v', $get['value']);
    }

    /**
     * 缺陷复现：旧实现用单次 `socket_send(..., MSG_DONTWAIT)` 且忽略返回值。
     * 内核发送缓冲区装不下整帧时只写出一部分，客户端按长度前缀读取就会永久错位。
     * 响应必须一字节不差地送达。
     */
    public function testLargeResponseIsWrittenCompletely(): void
    {
        $server = new Server();
        [$serverSide, $peer] = $this->createPair(4096);
        $id = $this->attachClient($server, $serverSide);

        // 让服务端持有一个远大于发送缓冲区的值
        $value = str_repeat('A', 512 * 1024);
        $this->seedData($server, ['big' => ['value' => $value, 'expire' => 0]]);

        $request = json_encode(['action' => 'get', 'key' => 'big']);
        $this->setClientBuffer($server, $id, pack('N', strlen($request)) . $request);
        $this->drainFrames($server, $id);

        // 前提校验：发送缓冲区确实装不下整帧，本用例走的正是「部分写」路径
        self::assertNotSame(
            '',
            $this->clients($server)[$id]['out'],
            '首次写出必然写不完，剩余数据必须留在服务端待发队列里而不是被丢弃'
        );

        $received = $this->pump($server, $id, $peer);

        self::assertGreaterThanOrEqual(4, strlen($received));
        $len = unpack('N', substr($received, 0, 4))[1];
        self::assertSame($len, strlen($received) - 4, '客户端收到的字节数必须与长度前缀一致');

        $decoded = json_decode(substr($received, 4), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertSame($value, $decoded['value'], '大响应必须完整送达，不能被截断');
    }

    /**
     * 反复调用 flushClient 并读空对端，直到没有待发数据。
     */
    private function pump(Server $server, int $id, \Socket $peer): string
    {
        $received = '';
        $flush = new \ReflectionMethod($server, 'flushClient');

        for ($i = 0; $i < 5000; $i++) {
            $chunk = '';
            $bytes = @socket_recv($peer, $chunk, 65535, MSG_DONTWAIT);
            if ($bytes > 0) {
                $received .= $chunk;
            }

            $clients = $this->clients($server);
            if (!isset($clients[$id]) || $clients[$id]['out'] === '') {
                if ($bytes === false || $bytes === 0) {
                    break;
                }
                continue;
            }

            $flush->invoke($server, $id);
        }

        return $received;
    }

    /**
     * @return array{0: \Socket, 1: \Socket} [服务端一侧, 客户端一侧]
     */
    private function createPair(int $bufferSize): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));

        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        @socket_set_option($pair[0], SOL_SOCKET, SO_SNDBUF, $bufferSize);
        @socket_set_option($pair[1], SOL_SOCKET, SO_RCVBUF, $bufferSize);

        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        return [$pair[0], $pair[1]];
    }

    /**
     * 往服务端注册一个连接，返回连接 id。
     */
    private function attachClient(Server $server, ?\Socket $socket = null): int
    {
        if ($socket === null) {
            [$socket] = $this->createPair(65536);
        }

        $property = new \ReflectionProperty($server, 'clients');
        $clients = $property->getValue($server);
        $clients[1] = ['socket' => $socket, 'buffer' => '', 'out' => ''];
        $property->setValue($server, $clients);

        return 1;
    }

    private function setClientBuffer(Server $server, int $id, string $buffer): void
    {
        $property = new \ReflectionProperty($server, 'clients');
        $clients = $property->getValue($server);
        $clients[$id]['buffer'] = $buffer;
        $property->setValue($server, $clients);
    }

    private function seedData(Server $server, array $data): void
    {
        (new \ReflectionProperty($server, 'data'))->setValue($server, $data);
    }

    private function clients(Server $server): array
    {
        return (new \ReflectionProperty($server, 'clients'))->getValue($server);
    }

    private function drainFrames(Server $server, int $id): void
    {
        (new \ReflectionMethod($server, 'drainFrames'))->invoke($server, $id);
    }

    private function handleRequest(Server $server, array $request): array
    {
        return (new \ReflectionMethod($server, 'handleRequest'))->invoke($server, $request);
    }
}
