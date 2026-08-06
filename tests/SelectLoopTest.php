<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Reactor\SelectLoop;
use PHPUnit\Framework\TestCase;

/**
 * 零扩展兜底事件循环的行为契约测试。
 *
 * SelectLoop 是所有环境都必须可用的最后一道防线，因此这里的断言最严格：
 * 可读/可写事件、一次性与周期定时器、defer、stop、destroy 都必须精确。
 */
final class SelectLoopTest extends TestCase
{
    private SelectLoop $loop;

    /** @var list<resource> */
    private array $pairs = [];

    protected function setUp(): void
    {
        $this->loop = new SelectLoop();
    }

    protected function tearDown(): void
    {
        $this->loop->destroy();

        foreach ($this->pairs as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->pairs = [];
    }

    /** @return array{0:resource,1:resource} */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        $this->pairs[] = $pair[0];
        $this->pairs[] = $pair[1];

        return $pair;
    }

    public function testMetadata(): void
    {
        $this->assertTrue(SelectLoop::isSupported());
        $this->assertSame('select', SelectLoop::name());
        $this->assertSame(0, SelectLoop::priority(), 'select 是兜底，权重必须最低');
    }

    public function testReadableEventFires(): void
    {
        [$a, $b] = $this->socketPair();
        $received = null;

        $this->loop->onReadable($a, function ($stream) use (&$received): void {
            $received = fread($stream, 1024);
            $this->loop->stop();
        });

        fwrite($b, 'ping');
        $this->loop->run();

        $this->assertSame('ping', $received);
    }

    public function testOffReadableStopsDelivery(): void
    {
        [$a, $b] = $this->socketPair();
        $hits = 0;

        $this->loop->onReadable($a, static function () use (&$hits): void {
            $hits++;
        });
        $this->loop->offReadable($a);

        fwrite($b, 'ping');

        // 没有任何监听时用一次性定时器兜住循环，避免永久阻塞
        $this->loop->addTimer(0.05, fn () => $this->loop->stop());
        $this->loop->run();

        $this->assertSame(0, $hits);
        $this->assertSame(0, $this->loop->stats()['read']);
    }

    public function testWritableEventFires(): void
    {
        [$a] = $this->socketPair();
        $fired = false;

        $this->loop->onWritable($a, function () use (&$fired): void {
            $fired = true;
            $this->loop->stop();
        });

        $this->loop->run();

        $this->assertTrue($fired);
    }

    public function testOneShotTimerFiresExactlyOnce(): void
    {
        $hits = 0;

        $this->loop->addTimer(0.01, function () use (&$hits): void {
            $hits++;
        }, false);
        $this->loop->addTimer(0.1, fn () => $this->loop->stop(), false);

        $this->loop->run();

        $this->assertSame(1, $hits);
        $this->assertSame(0, $this->loop->stats()['timer'], '一次性定时器触发后应自动摘除');
    }

    public function testPeriodicTimerRepeats(): void
    {
        $hits = 0;

        $id = $this->loop->addTimer(0.01, function () use (&$hits, &$id): void {
            if (++$hits >= 3) {
                $this->loop->delTimer($id);
                $this->loop->stop();
            }
        }, true);

        $this->loop->run();

        $this->assertSame(3, $hits);
    }

    public function testDelTimerReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->loop->delTimer(999999));
    }

    public function testDeferRunsOnNextIteration(): void
    {
        $order = [];

        $this->loop->defer(function () use (&$order): void {
            $order[] = 'deferred';
            $this->loop->stop();
        });
        $order[] = 'sync';

        $this->loop->run();

        $this->assertSame(['sync', 'deferred'], $order);
    }

    public function testStatsShape(): void
    {
        [$a] = $this->socketPair();

        $this->loop->onReadable($a, static fn () => null);
        $this->loop->addTimer(10.0, static fn () => null, true);

        $stats = $this->loop->stats();

        $this->assertSame('select', $stats['driver']);
        $this->assertSame(1, $stats['read']);
        $this->assertSame(0, $stats['write']);
        $this->assertSame(1, $stats['timer']);
        $this->assertArrayHasKey('signal', $stats);
        $this->assertArrayHasKey('deferred', $stats);
    }

    public function testDestroyClearsEverything(): void
    {
        [$a] = $this->socketPair();

        $this->loop->onReadable($a, static fn () => null);
        $this->loop->addTimer(10.0, static fn () => null, true);
        $this->loop->destroy();

        $stats = $this->loop->stats();

        $this->assertSame(0, $stats['read']);
        $this->assertSame(0, $stats['timer']);
        $this->assertFalse($this->loop->isRunning());
    }

    public function testIsRunningReflectsState(): void
    {
        $this->assertFalse($this->loop->isRunning());

        $inside = null;
        $this->loop->addTimer(0.01, function () use (&$inside): void {
            $inside = $this->loop->isRunning();
            $this->loop->stop();
        });
        $this->loop->run();

        $this->assertTrue($inside);
        $this->assertFalse($this->loop->isRunning());
    }
}
