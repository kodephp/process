<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Rpc\RpcClient;
use Kode\Process\Cluster\Rpc\RpcFrame;
use Kode\Process\Cluster\Rpc\RpcServer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 回归：broadcast() 不能污染长连接的接收缓冲。
 *
 * 修复前 broadcast() 用的是一份**局部** $buffers：超时或半包时从 socket 上读走的
 * 字节随着局部变量一起丢掉，socket 却仍留在连接池里 —— 下一次 call() 复用它，
 * 读到的就是错位的半个帧。
 *
 * 需要 pcntl + posix 扩展；缺失时整体跳过。
 */
final class RpcClientBroadcastBufferTest extends TestCase
{
    private ?int $serverPid = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('本测试需要 pcntl + posix 扩展');
        }
    }

    protected function tearDown(): void
    {
        if ($this->serverPid !== null) {
            @posix_kill($this->serverPid, \SIGTERM);
            pcntl_waitpid($this->serverPid, $status);
            $this->serverPid = null;
        }
    }

    /**
     * @param 'half'|'split' $mode half：首条连接只回半个帧头然后挂死；split：回包拆成两次写
     */
    private function startServer(string $mode): string
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            $this->fail('无法启动测试用 TCP server：' . $errstr);
        }

        $name = (string) stream_socket_get_name($sock, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork 失败');
        }

        if ($pid === 0) {
            $server = new RpcServer();
            $server->register('echo', static fn (array $p): array => $p);

            $held     = [];     // 持有连接不关闭，模拟服务端卡住而非断开
            $accepted = 0;

            while (true) {
                $conn = @stream_socket_accept($sock, 2);
                if ($conn === false) {
                    continue;
                }

                $accepted++;

                $buffer  = '';
                $request = null;
                while (true) {
                    $data = @fread($conn, 65536);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $buffer .= $data;
                    $frame = RpcFrame::shift($buffer);
                    if (is_array($frame)) {
                        $request = $frame;
                        break;
                    }
                }

                if ($request === null) {
                    @fclose($conn);
                    continue;
                }

                $payload = RpcFrame::encode($server->handle($request));

                if ($mode === 'half' && $accepted === 1) {
                    @fwrite($conn, substr($payload, 0, 3));  // 连 4 字节长度头都没写全
                    $held[] = $conn;
                    continue;
                }

                if ($mode === 'split') {
                    @fwrite($conn, substr($payload, 0, 3));
                    usleep(150_000);
                    @fwrite($conn, substr($payload, 3));
                    $held[] = $conn;
                    continue;
                }

                @fwrite($conn, $payload);
                $held[] = $conn;
            }

            exit(0);
        }

        fclose($sock);
        $this->serverPid = $pid;
        usleep(200_000);

        return '127.0.0.1:' . $port;
    }

    /** @return array<string, string> */
    private function readBuffers(RpcClient $client): array
    {
        return (new ReflectionProperty(RpcClient::class, 'readBuffers'))->getValue($client);
    }

    public function testBroadcastDropsTimedOutConnectionInsteadOfLeavingItDesynced(): void
    {
        $addr   = $this->startServer('half');
        $client = new RpcClient(timeout: 0.5);

        $results = $client->broadcast([$addr], 'echo', ['a' => 1]);

        $this->assertFalse($results[$addr]['ok']);
        $this->assertSame('响应超时', $results[$addr]['error']);

        $this->assertSame(
            0,
            $client->poolSize(),
            '广播超时后连接上还躺着半个帧，必须丢弃；留在池里下一次 call() 就会读到错位字节'
        );
        $this->assertSame([], $this->readBuffers($client), '连接已丢弃，其接收缓冲不应残留');

        // 换了条干净连接，后续调用照常
        $this->assertSame(['a' => 2], $client->call($addr, 'echo', ['a' => 2]));
    }

    public function testBroadcastAccumulatesSplitFramesInTheConnectionBuffer(): void
    {
        $addr   = $this->startServer('split');
        $client = new RpcClient(timeout: 2.0);

        $results = $client->broadcast([$addr], 'echo', ['a' => 1]);

        $this->assertTrue($results[$addr]['ok']);
        $this->assertSame(['a' => 1], $results[$addr]['result']);

        $buffers = $this->readBuffers($client);
        $this->assertArrayHasKey('tcp://' . $addr, $buffers, '广播必须走连接级缓冲，而不是自己开一份局部的');
        $this->assertSame('', $buffers['tcp://' . $addr], '整帧已消费完，缓冲应被清空');
    }
}
