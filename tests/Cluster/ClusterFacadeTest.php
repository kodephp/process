<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster;
use Kode\Process\Cluster\Balancer\ConsistentHashBalancer;
use Kode\Process\Cluster\Balancer\LeastConnectionsBalancer;
use Kode\Process\Cluster\Balancer\RandomBalancer;
use Kode\Process\Cluster\Balancer\RoundRobinBalancer;
use Kode\Process\Cluster\Balancer\WeightedRoundRobinBalancer;
use Kode\Process\Cluster\Node;
use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Exceptions\ClusterException;
use Kode\Process\Kode;
use PHPUnit\Framework\TestCase;

/**
 * Cluster 门面与 Kode 门面上的集群委托方法。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class ClusterFacadeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kode-facade-test-' . getmypid() . '-' . uniqid();

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

    // -------------------------------------------------------------- 后端

    public function testAvailableBackendsAlwaysIncludeFile(): void
    {
        $this->assertContains('file', Cluster::available(), 'file 后端零依赖，任何环境都该可用');
    }

    public function testSupportsKnownBackends(): void
    {
        $this->assertTrue(Cluster::supports('redis'));
        $this->assertTrue(Cluster::supports('globaldata'));
        $this->assertTrue(Cluster::supports('file'));
        $this->assertFalse(Cluster::supports('etcd'));
    }

    public function testMakeUnknownBackendThrows(): void
    {
        $this->expectException(ClusterException::class);

        Cluster::make('etcd');
    }

    public function testMakeSwitchesBackendAndResetsDerivedState(): void
    {
        Cluster::join(['id' => 'n1', 'service' => 'api']);
        $this->assertNotNull(Cluster::self());

        Cluster::make('file', ['path' => $this->path, 'prefix' => 'switched']);

        $this->assertSame('file', Cluster::store()->name());
        $this->assertNull(Cluster::self(), '换后端后本节点身份必须失效，否则会指向不存在的注册表');

        Cluster::store()->flush();
        @rmdir($this->path . '/switched');
    }

    public function testAutoFallsBackToFileWhenNothingElseIsReachable(): void
    {
        // redis/globaldata 在 CI 上通常连不通，auto() 应顺延到 file 而不是抛错
        $store = Cluster::auto(['file' => ['path' => $this->path, 'prefix' => 'auto']]);

        $this->assertContains($store->name(), ['redis', 'globaldata', 'file']);

        $store->flush();
        @rmdir($this->path . '/auto');
    }

    // ---------------------------------------------------------- 注册与发现

    public function testJoinFillsInDefaults(): void
    {
        $node = Cluster::join(['service' => 'api', 'port' => 9501]);

        $this->assertSame(gethostname() . '-' . getmypid(), $node->id, '缺省 id 应取 主机名-PID');
        $this->assertNotSame('', $node->host, '缺省 host 应自动探测本机 IP');
        $this->assertSame(9501, $node->port);
        $this->assertSame($node, Cluster::self());
    }

    public function testJoinAcceptsNodeObject(): void
    {
        $node = Cluster::join(new Node('n1', 'api', '10.0.0.1', 9501));

        $this->assertSame('n1', $node->id);
        $this->assertSame('10.0.0.1', $node->host);
    }

    public function testHeartbeatWithoutJoinIsFalse(): void
    {
        $this->assertFalse(Cluster::heartbeat());
    }

    public function testHeartbeatRenewsAfterJoin(): void
    {
        Cluster::join(['id' => 'n1', 'service' => 'api']);

        $this->assertTrue(Cluster::heartbeat());
    }

    public function testHeartbeatSelfHealsAfterBeingPruned(): void
    {
        Cluster::join(['id' => 'n1', 'service' => 'api']);

        // 模拟长时间失联被摘除
        Cluster::registry()->deregister('n1', 'api');

        $this->assertFalse(Cluster::heartbeat(), '重新注册时返回 false');
        $this->assertNotNull(Cluster::registry()->find('n1', 'api'), '节点应自愈回到注册表');
        $this->assertTrue(Cluster::heartbeat(), '自愈之后续约恢复正常');
    }

    public function testNodesAndPeers(): void
    {
        Cluster::join(['id' => 'self', 'service' => 'api']);
        Cluster::registry()->register(new Node('other', 'api', '10.0.0.2', 9501));

        $this->assertCount(2, Cluster::nodes('api'));

        $peers = Cluster::peers('api');
        $this->assertCount(1, $peers);
        $this->assertSame('other', $peers[0]->id, 'peers 必须排除自己，否则广播会自己打自己');
    }

    public function testLeaveDeregistersAndResignsElections(): void
    {
        Cluster::join(['id' => 'n1', 'service' => 'api']);
        $election = Cluster::election('cron');
        $election->tick();

        $this->assertTrue($election->isLeader());
        $this->assertTrue(Cluster::leave());

        $this->assertNull(Cluster::self());
        $this->assertSame([], Cluster::nodes('api'));
        $this->assertNull($election->leaderId(), '下线必须让出 Leader，否则要等一个 TTL 才有人接手');
    }

    public function testLeaveWithoutJoinIsFalse(): void
    {
        $this->assertFalse(Cluster::leave());
    }

    public function testRegistryTtlIsConfigurable(): void
    {
        $this->assertSame(20.0, Cluster::registry(20.0)->ttl());
    }

    // ---------------------------------------------------------- 协调原语

    public function testLockUsesJoinedNodeIdAsOwner(): void
    {
        Cluster::join(['id' => 'node-a', 'service' => 'api']);

        $lock = Cluster::lock('job');
        $lock->tryAcquire();

        $this->assertSame('node-a', $lock->owner());
    }

    public function testElectionIsCachedPerName(): void
    {
        $this->assertSame(Cluster::election('cron'), Cluster::election('cron'));
        $this->assertNotSame(Cluster::election('cron'), Cluster::election('report'));
    }

    public function testBalancerAliasesResolveToStrategies(): void
    {
        $nodes = [new Node('n1', 'api', '10.0.0.1', 9501)];

        $this->assertInstanceOf(RoundRobinBalancer::class, Cluster::balancer('round-robin', $nodes));
        $this->assertInstanceOf(RoundRobinBalancer::class, Cluster::balancer('rr', $nodes));
        $this->assertInstanceOf(WeightedRoundRobinBalancer::class, Cluster::balancer('weighted', $nodes));
        $this->assertInstanceOf(WeightedRoundRobinBalancer::class, Cluster::balancer('wrr', $nodes));
        $this->assertInstanceOf(RandomBalancer::class, Cluster::balancer('random', $nodes));
        $this->assertInstanceOf(LeastConnectionsBalancer::class, Cluster::balancer('least-conn', $nodes));
        $this->assertInstanceOf(LeastConnectionsBalancer::class, Cluster::balancer('least', $nodes));
        $this->assertInstanceOf(ConsistentHashBalancer::class, Cluster::balancer('hash', $nodes));
        $this->assertInstanceOf(ConsistentHashBalancer::class, Cluster::balancer('consistent-hash', $nodes));
    }

    public function testUnknownBalancerStrategyThrows(): void
    {
        $this->expectException(ClusterException::class);

        Cluster::balancer('magic');
    }

    public function testBalancerPullsNodesFromRegistryByService(): void
    {
        Cluster::registry()->register(new Node('n1', 'cache', '10.0.0.1', 6379));
        Cluster::registry()->register(new Node('n2', 'cache', '10.0.0.2', 6379));

        $this->assertSame(2, Cluster::balancer('rr', service: 'cache')->count());
    }

    public function testSnowflakeIsCachedAndAllocatesWorkerId(): void
    {
        $sf = Cluster::snowflake();

        $this->assertGreaterThanOrEqual(0, $sf->workerId());
        $this->assertSame($sf, Cluster::snowflake(), '同一进程内应复用同一个生成器');
        $this->assertGreaterThan(0, $sf->next());
    }

    public function testRenewSnowflakeWithoutInstanceIsFalse(): void
    {
        $this->assertFalse(Cluster::renewSnowflake());
    }

    public function testRenewSnowflakeKeepsLease(): void
    {
        $sf = Cluster::snowflake();

        $this->assertTrue(Cluster::renewSnowflake());
        $this->assertSame($sf->workerId(), Cluster::snowflake()->workerId());
    }

    public function testRenewSnowflakeReallocatesAfterLeaseLoss(): void
    {
        $sf  = Cluster::snowflake();
        $old = $sf->workerId();

        // 租约被别人抢走
        Cluster::store()->set('snowflake/default/' . $old, 'someone-else');

        $this->assertFalse(Cluster::renewSnowflake(), '丢租约时返回 false');
        $this->assertSame($sf, Cluster::snowflake(), '实例要复用，否则业务侧持有的旧引用会继续用废掉的机器 ID');
        $this->assertNotSame($old, $sf->workerId(), '应自动换一个新的机器 ID');
    }

    public function testLimiterIsCached(): void
    {
        $this->assertSame(Cluster::limiter(), Cluster::limiter());
        $this->assertTrue(Cluster::limiter()->attempt('k', 1));
    }

    public function testRpcFactoriesReturnFreshInstances(): void
    {
        $this->assertNotSame(Cluster::rpc(), Cluster::rpc());
        $this->assertNotSame(Cluster::server(), Cluster::server());
    }

    public function testBroadcastWithNoPeersReturnsEmpty(): void
    {
        Cluster::join(['id' => 'lonely', 'service' => 'api']);

        $this->assertSame([], Cluster::broadcast('ping', service: 'api'));
    }

    // ------------------------------------------------------------ 诊断

    public function testDiagnoseReportsBackendAndTopology(): void
    {
        Cluster::join(['id' => 'n1', 'service' => 'api']);

        $info = Cluster::diagnose();

        $this->assertSame('file', $info['backend']);
        $this->assertTrue($info['connected']);
        $this->assertContains('file', $info['available_backends']);
        $this->assertSame('n1', $info['self']['id']);
        $this->assertSame(['api'], $info['services']);
        $this->assertSame(1, $info['node_count']);
    }

    public function testResetClearsEverything(): void
    {
        Cluster::join(['id' => 'n1', 'service' => 'api']);
        Cluster::snowflake();

        Cluster::reset();
        Cluster::useStore(new FileStore(['path' => $this->path]));

        $this->assertNull(Cluster::self());
        $this->assertNull(Cluster::diagnose()['self']);
    }

    public function testLocalIpIsResolvable(): void
    {
        $ip = Cluster::localIp();

        $this->assertNotSame('', $ip);
        $this->assertNotSame('0.0.0.0', $ip);
    }

    // ------------------------------------------------------- Kode 门面委托

    public function testKodeClusterReturnsSameStore(): void
    {
        $this->assertSame(Cluster::store(), Kode::cluster());
    }

    public function testKodeJoinDelegates(): void
    {
        $node = Kode::join(['id' => 'n1', 'service' => 'api']);

        $this->assertSame('n1', $node->id);
        $this->assertSame($node, Cluster::self());
    }

    public function testKodeLockDelegates(): void
    {
        $lock = Kode::lock('job', 10.0);

        $this->assertSame('job', $lock->key());
        $this->assertSame(10.0, $lock->ttl());
        $this->assertTrue($lock->tryAcquire());
    }

    public function testKodeElectionDelegates(): void
    {
        $this->assertSame(Cluster::election('cron'), Kode::election('cron'));
    }

    public function testKodeBalancerDelegates(): void
    {
        $lb = Kode::balancer('wrr', [new Node('n1', 'api', '10.0.0.1', 9501)]);

        $this->assertInstanceOf(WeightedRoundRobinBalancer::class, $lb);
        $this->assertSame(1, $lb->count());
    }

    public function testKodeSnowflakeAndLimiterDelegate(): void
    {
        $this->assertSame(Cluster::snowflake(), Kode::snowflake());
        $this->assertSame(Cluster::limiter(), Kode::limiter());
    }

    public function testKodeDiagnoseIncludesClusterSection(): void
    {
        $cluster = Kode::diagnose()['cluster'];

        $this->assertContains('file', $cluster['backends']);
        $this->assertNull($cluster['joined']);

        Cluster::join(['id' => 'n1', 'service' => 'api']);

        $this->assertSame('n1', Kode::diagnose()['cluster']['joined']);
    }
}
