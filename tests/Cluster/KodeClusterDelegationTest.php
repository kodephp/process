<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster;
use Kode\Process\Cluster\Registry\RegistryInterface;
use Kode\Process\Cluster\Rpc\RpcClient;
use Kode\Process\Cluster\Rpc\RpcServer;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Kode;
use PHPUnit\Framework\TestCase;

/**
 * Kode 门面上的集群委托方法（heartbeat / leave / self / nodes / peers /
 * registry / renewSnowflake / rpc / rpcServer / broadcast）。
 */
final class KodeClusterDelegationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kode-kode-deleg-test-' . getmypid() . '-' . uniqid();

        Cluster::reset();
        Cluster::useStore(new FileStore(['path' => $this->path]));
    }

    protected function tearDown(): void
    {
        Cluster::store()->flush();
        Cluster::reset();

        foreach ((array) glob($this->path . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->path);
    }

    public function testSelfIsNullBeforeJoin(): void
    {
        $this->assertNull(Kode::self());
    }

    public function testJoinThenSelf(): void
    {
        $node = Kode::join(['id' => 'node-a', 'service' => 'api', 'port' => 9501]);

        $this->assertSame('node-a', $node->id);
        $this->assertNotNull(Kode::self());
        $this->assertSame('node-a', Kode::self()->id);
    }

    public function testNodesAndPeersExcludesSelf(): void
    {
        // 先注册其它节点，最后再 join 本节点，确保 self 指向 'me'
        // （Cluster::join 总是把被注册节点设为 self）
        Cluster::join(['id' => 'peer-1', 'service' => 'api', 'port' => 9502]);
        Cluster::join(['id' => 'peer-2', 'service' => 'api', 'port' => 9503]);
        Kode::join(['id' => 'me', 'service' => 'api', 'port' => 9501]);

        $ids = array_map(static fn ($n) => $n->id, Kode::nodes('api'));
        $this->assertContains('me', $ids);
        $this->assertContains('peer-1', $ids);

        $peerIds = array_map(static fn ($n) => $n->id, Kode::peers('api'));
        $this->assertContains('peer-1', $peerIds);
        $this->assertNotContains('me', $peerIds);
    }

    public function testHeartbeatReturnsBool(): void
    {
        Kode::join(['id' => 'hb', 'service' => 'api', 'port' => 9501]);

        $this->assertIsBool(Kode::heartbeat());
    }

    public function testRegistryReturnsRegistryInterface(): void
    {
        $this->assertInstanceOf(RegistryInterface::class, Kode::registry());
    }

    public function testRenewSnowflakeReturnsBool(): void
    {
        Kode::join(['id' => 'sf', 'service' => 'api', 'port' => 9501]);
        Kode::snowflake(); // 分配一个机器 ID

        $this->assertIsBool(Kode::renewSnowflake());
    }

    public function testRpcReturnsClient(): void
    {
        $this->assertInstanceOf(RpcClient::class, Kode::rpc());
    }

    public function testRpcServerReturnsServer(): void
    {
        $this->assertInstanceOf(RpcServer::class, Kode::rpcServer());
    }

    public function testBroadcastWithNoPeersReturnsEmpty(): void
    {
        Kode::join(['id' => 'alone', 'service' => 'api', 'port' => 9501]);

        $this->assertSame([], Kode::broadcast('node.ping'));
    }

    public function testLeaveReturnsBoolAndClearsSelf(): void
    {
        Kode::join(['id' => 'bye', 'service' => 'api', 'port' => 9501]);
        $this->assertNotNull(Kode::self());

        $this->assertTrue(Kode::leave());
        $this->assertNull(Kode::self());
    }
}
