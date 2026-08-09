<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Rpc\RpcClient;
use Kode\Process\Cluster\Rpc\RpcFrame;
use Kode\Process\Cluster\Rpc\RpcServer;
use PHPUnit\Framework\TestCase;

/**
 * 回归：长连接失效时 call() 必须真的重连重试一次。
 *
 * 测试服务端对**第一条连接**收下请求后直接关闭（模拟被中间设备/服务端回收的
 * 空闲长连接），对第二条连接正常回包。
 *
 * 修复前内层 `break` 只跳出读帧循环，随后拿着 null 掉进错误分支，
 * 抛出一句莫名其妙的「未知错误」——重试根本没发生。
 *
 * 需要 pcntl + posix 扩展；缺失时整体跳过。
 */
final class RpcClientRetryTest extends TestCase
{
    private string $addr;

    private ?int $serverPid = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('本测试需要 pcntl + posix 扩展');
        }
    }

    protected function setUp(): void
    {
        $this->addr = $this->startServer();
    }

    protected function tearDown(): void
    {
        if ($this->serverPid !== null) {
            @posix_kill($this->serverPid, \SIGTERM);
            pcntl_waitpid($this->serverPid, $status);
            $this->serverPid = null;
        }
    }

    private function startServer(): string
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

            $accepted = 0;

            while (true) {
                $conn = @stream_socket_accept($sock, 2);
                if ($conn === false) {
                    continue;
                }

                $accepted++;

                // 第一条连接：收下请求就关，不回包
                if ($accepted === 1) {
                    @fread($conn, 65536);
                    @fclose($conn);
                    continue;
                }

                $buffer = '';
                while (true) {
                    $data = @fread($conn, 65536);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $buffer .= $data;

                    while (is_array($frame = RpcFrame::shift($buffer))) {
                        @fwrite($conn, RpcFrame::encode($server->handle($frame)));
                    }
                }
                @fclose($conn);
            }
            exit(0);
        }

        fclose($sock);
        $this->serverPid = $pid;
        usleep(200_000);

        return '127.0.0.1:' . $port;
    }

    public function testCallReconnectsWhenFirstAttemptGetsNoResponse(): void
    {
        $client = new RpcClient(timeout: 2.0);

        $this->assertSame(
            ['a' => 7],
            $client->call($this->addr, 'echo', ['a' => 7]),
            '首次连接被服务端关掉时应重连重试，而不是报「未知错误」'
        );
    }
}
