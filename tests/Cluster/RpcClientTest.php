<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Rpc\RpcClient;
use Kode\Process\Cluster\Rpc\RpcFrame;
use Kode\Process\Cluster\Rpc\RpcServer;
use PHPUnit\Framework\TestCase;

/**
 * RpcClient 集成测试。
 *
 * 用 fork 起一个极简 TCP server，对每个连接用 RpcServer::handle() 处理并回包
 * （注意：服务端对「单向通知」也回包，模拟修复前的服务端行为），
 * 以验证 RpcClient::call() 的「按请求 ID 匹配响应、丢弃错配帧」防御是否生效。
 *
 * 需要 pcntl + posix 扩展；缺失时整体跳过。
 */
final class RpcClientTest extends TestCase
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
            // 子进程：扮演服务端
            $server = new RpcServer();
            $server->register('echo', static fn (array $p): array => $p);
            $server->register('log', static fn (array $p): array => ['ok' => true]);

            while (true) {
                $conn = @stream_socket_accept($sock, 2);
                if ($conn === false) {
                    continue; // 超时，继续等待
                }

                $buffer = '';
                while (true) {
                    $data = @fread($conn, 65536);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $buffer .= $data;

                    while (($frame = RpcFrame::shift($buffer)) !== null) {
                        // 模拟「未修复的服务端」：notify（空 id）也回包，制造遗留响应
                        @fwrite($conn, RpcFrame::encode($server->handle($frame)));
                    }
                }
                @fclose($conn);
            }
            exit(0);
        }

        // 父进程：关闭自己的监听副本，仅保留子进程在跑
        fclose($sock);
        $this->serverPid = $pid;
        usleep(200_000);

        return '127.0.0.1:' . $port;
    }

    public function testCallReturnsResult(): void
    {
        $client = new RpcClient(timeout: 2.0);

        $this->assertSame(['a' => 1], $client->call($this->addr, 'echo', ['a' => 1]));
    }

    /**
     * 关键回归：notify 在持久连接上会留下一条「空 id」响应。
     * 若 call() 不按请求 ID 匹配，就会误把这条遗留响应当成自己的结果返回。
     */
    public function testNotifyDoesNotCorruptSubsequentCall(): void
    {
        $client = new RpcClient(timeout: 2.0);

        // 发送单向通知（服务端会回包，制造遗留响应）
        $this->assertTrue($client->notify($this->addr, 'log', ['msg' => 'hi']));

        // 同一持久连接上的后续 call 必须拿到 echo 的正确结果
        $this->assertSame(['a' => 42], $client->call($this->addr, 'echo', ['a' => 42]));
    }
}
