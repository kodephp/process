<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Node;
use Kode\Process\Cluster\NodeStatus;
use PHPUnit\Framework\TestCase;

/**
 * 节点值对象与健康状态推导。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class NodeTest extends TestCase
{
    public function testDefaults(): void
    {
        $node = new Node('n1');

        $this->assertSame('n1', $node->id);
        $this->assertSame('default', $node->service);
        $this->assertSame('127.0.0.1', $node->host);
        $this->assertSame(0, $node->port);
        $this->assertSame(100, $node->weight);
        $this->assertSame(NodeStatus::Up, $node->status);
    }

    public function testAddressOmitsZeroPort(): void
    {
        $this->assertSame('10.0.0.1:9501', (new Node('n1', 'api', '10.0.0.1', 9501))->address());
        $this->assertSame('10.0.0.1', (new Node('n1', 'api', '10.0.0.1'))->address());
    }

    public function testHeartbeatAgeIsInfiniteWhenNeverReported(): void
    {
        $this->assertInfinite((new Node('n1'))->heartbeatAge());
    }

    public function testHeartbeatAgeIsMeasuredFromLastReport(): void
    {
        $now  = microtime(true);
        $node = new Node('n1', heartbeatAt: $now - 5.0);

        $this->assertEqualsWithDelta(5.0, $node->heartbeatAge($now), 0.001);
    }

    public function testHeartbeatAgeNeverGoesNegative(): void
    {
        $now  = microtime(true);
        $node = new Node('n1', heartbeatAt: $now + 10.0);

        $this->assertSame(0.0, $node->heartbeatAge($now));
    }

    public function testMetaAccessor(): void
    {
        $node = new Node('n1', meta: ['zone' => 'sh', 'version' => '5.0.0']);

        $this->assertSame('sh', $node->meta('zone'));
        $this->assertNull($node->meta('missing'));
        $this->assertSame('fallback', $node->meta('missing', 'fallback'));
    }

    public function testWithersReturnNewInstances(): void
    {
        $node = new Node('n1', meta: ['a' => 1]);

        $down = $node->withStatus(NodeStatus::Down);
        $beat = $node->withHeartbeat(1234.5);
        $meta = $node->withMeta(['b' => 2]);

        $this->assertNotSame($node, $down);
        $this->assertSame(NodeStatus::Up, $node->status, '原实例不应被修改');
        $this->assertSame(NodeStatus::Down, $down->status);
        $this->assertSame(1234.5, $beat->heartbeatAt);
        $this->assertSame(['a' => 1, 'b' => 2], $meta->meta, 'withMeta 应合并而非覆盖');
    }

    public function testWithHeartbeatRevivesStatus(): void
    {
        $node = (new Node('n1'))->withStatus(NodeStatus::Down)->withHeartbeat(microtime(true));

        $this->assertSame(NodeStatus::Up, $node->status);
    }

    public function testArrayRoundTrip(): void
    {
        $node = new Node(
            'n1',
            'api',
            '10.0.0.7',
            9501,
            200,
            ['zone' => 'sh'],
            NodeStatus::Suspect,
            1000.0,
            2000.0,
        );

        $restored = Node::fromArray($node->toArray());

        $this->assertEquals($node, $restored);
    }

    public function testFromArrayToleratesPartialPayload(): void
    {
        $node = Node::fromArray(['id' => 'n9']);

        $this->assertSame('n9', $node->id);
        $this->assertSame('default', $node->service);
        $this->assertSame(NodeStatus::Up, $node->status);
    }

    public function testJsonSerializable(): void
    {
        $node = new Node('n1', 'api', '10.0.0.1', 9501);
        $json = json_decode((string) json_encode($node), true);

        $this->assertIsArray($json);
        $this->assertSame('n1', $json['id']);
        $this->assertSame('api', $json['service']);
    }

    public function testToStringIsHumanReadable(): void
    {
        $text = (string) new Node('n1', 'api', '10.0.0.1', 9501);

        $this->assertStringContainsString('n1', $text);
        $this->assertStringContainsString('10.0.0.1:9501', $text);
    }
}
