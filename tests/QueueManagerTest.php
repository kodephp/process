<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Queue\QueueManager;
use Kode\Queue\Enum\Capability;
use Kode\Queue\Enum\DriverType;
use PHPUnit\Framework\TestCase;

/**
 * QueueManager（kode/queue ^2.1 适配层）测试。
 *
 * 使用内存 / 同步驱动，无需 Redis、数据库或任何外部服务即可覆盖
 * 投递、处理器注册、消费、ack/fail、批量、统计等核心路径。
 */
final class QueueManagerTest extends TestCase
{
    protected function setUp(): void
    {
        QueueManager::reset();
    }

    protected function tearDown(): void
    {
        QueueManager::reset();
    }

    public function testMemoryDriverDispatchProcess(): void
    {
        $qm = QueueManager::useMemory();
        $qm->register('email', function (array $payload) {
            return 'sent:' . $payload['to'];
        });

        $id = $qm->dispatch('email', ['to' => 'a@b.com']);
        $this->assertNotEmpty($id);

        $resp = $qm->process();
        $this->assertNotNull($resp);
        $this->assertTrue($resp->isSuccess());
        $this->assertSame('sent:a@b.com', $resp->data);
    }

    public function testMissingHandlerFailsJob(): void
    {
        $qm = QueueManager::useMemory();
        $qm->dispatch('ghost', ['x' => 1]);

        $resp = $qm->process();
        $this->assertNotNull($resp);
        $this->assertTrue($resp->isNotFound());
    }

    public function testHandlerExceptionReturnsError(): void
    {
        $qm = QueueManager::useMemory();
        $qm->register('boom', function (): void {
            throw new \RuntimeException('kaboom');
        });
        $qm->dispatch('boom', []);

        $resp = $qm->process();
        $this->assertNotNull($resp);
        $this->assertTrue($resp->isError());
        $this->assertStringContainsString('kaboom', $resp->message);
    }

    public function testBulkDispatchAndBatchProcess(): void
    {
        $qm = QueueManager::useMemory();
        $seen = [];
        $qm->register('t', function (array $payload) use (&$seen) {
            $seen[] = $payload['i'];

            return true;
        });

        $jobs = array_map(
            static fn (int $i): array => ['job' => 't', 'data' => ['i' => $i]],
            range(0, 4),
        );
        $ids = $qm->dispatchBulk($jobs);
        $this->assertCount(5, $ids);

        $processed = $qm->processBatch();
        $this->assertSame(5, $processed);
        $this->assertSame([0, 1, 2, 3, 4], $seen);
    }

    public function testSizeAndClear(): void
    {
        $qm = QueueManager::useMemory();
        $qm->register('t', static fn (): bool => true);

        $this->assertSame(0, $qm->size());
        $qm->dispatchBulk([['job' => 't', 'data' => ['a' => 1]], ['job' => 't', 'data' => ['a' => 2]]]);
        $this->assertSame(2, $qm->size());

        $cleared = $qm->clear();
        $this->assertSame(2, $cleared);
        $this->assertSame(0, $qm->size());
    }

    public function testStatsShape(): void
    {
        $qm = QueueManager::useMemory();
        $stats = $qm->stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('driver', $stats);
    }

    public function testSupportsCapability(): void
    {
        $qm = QueueManager::useMemory();
        $this->assertIsBool($qm->supports(Capability::Ack));
    }

    public function testDriverAvailability(): void
    {
        $this->assertTrue(QueueManager::driverAvailable(DriverType::Memory));
    }

    public function testSingletonLifecycle(): void
    {
        $a = QueueManager::getInstance();
        $b = QueueManager::getInstance();
        $this->assertSame($a, $b);

        QueueManager::reset();
        $c = QueueManager::getInstance();
        $this->assertNotSame($a, $c);
    }

    public function testRegisterAndUnregister(): void
    {
        $qm = QueueManager::useMemory();
        $qm->register('one', static fn (): string => 'one');
        $this->assertTrue($qm->hasHandler('one'));

        $qm->unregister('one');
        $this->assertFalse($qm->hasHandler('one'));
    }
}
