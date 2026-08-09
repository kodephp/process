<?php

declare(strict_types=1);

namespace Kode\Process\Tests\IPC;

use Kode\Process\IPC\SharedMemoryIPC;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SharedMemoryIPC 回归测试：写入失败检测、反序列化与权限位。
 */
#[Group('ipc')]
final class SharedMemoryIPCTest extends TestCase
{
    private int $key = 0;

    private ?SharedMemoryIPC $ipc = null;

    protected function setUp(): void
    {
        if (!extension_loaded('sysvshm') || !extension_loaded('sysvsem')) {
            self::markTestSkipped('需要 sysvshm / sysvsem 扩展');
        }

        $this->key = 0x5348_0000 + random_int(1, 0xFFFE);
    }

    protected function tearDown(): void
    {
        $this->ipc?->destroy();
        $this->ipc = null;
    }

    /**
     * 缺陷复现：共享内存段写满时 shm_put_var 会失败，旧实现忽略返回值照样推进 tail / count，
     * 消费者随后从一个根本没写进去的槽位读出 false，等于凭空多出一条损坏消息。
     *
     * 这里把头部里的 capacity 撑到远超物理段容量，逼出「段满但逻辑上未满」的路径。
     */
    public function testSendReportsFailureWhenSegmentIsFull(): void
    {
        $this->ipc = new SharedMemoryIPC($this->key, null, 262144, 4096);

        $this->inflateDeclaredCapacity($this->ipc, 100000);

        $payload = str_repeat('d', 3800);
        $limit = 200;
        $accepted = 0;

        while ($accepted < $limit && $this->ipc->send($payload)) {
            $accepted++;
        }

        self::assertGreaterThan(0, $accepted, '段未满时应能正常入队');
        self::assertLessThan($limit, $accepted, '共享内存段写满后 send() 必须返回 false，而不是谎报成功');

        // send() 报告成功的每一条都必须能原样读回，不能出现写失败留下的空槽位
        for ($i = 0; $i < $accepted; $i++) {
            self::assertSame($payload, $this->ipc->receive(1.0), "第 {$i} 条消息读取结果不符");
        }
    }

    /**
     * 正常容量下的收发闭环，确认修复没有破坏基本路径。
     */
    public function testRoundTrip(): void
    {
        $this->ipc = new SharedMemoryIPC($this->key, null, 262144, 4096);

        self::assertTrue($this->ipc->send(['task' => 'thumb', 'id' => 7]));
        self::assertSame(['task' => 'thumb', 'id' => 7], $this->ipc->receive(1.0));
    }

    /**
     * IPC 报文只承载数据：反序列化必须禁止实例化任意类。
     */
    public function testUnserializeRejectsObjects(): void
    {
        $this->ipc = new SharedMemoryIPC($this->key, null, 262144, 4096);

        $this->ipc->send(new \ArrayObject(['a' => 1]));
        $received = $this->ipc->receive(1.0);

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $received);
        self::assertNotInstanceOf(\ArrayObject::class, $received);
    }

    /**
     * 共享内存段不得对同主机其他本地用户可读：权限位必须是 0600。
     */
    public function testSegmentIsCreatedWithOwnerOnlyPermissions(): void
    {
        $this->ipc = new SharedMemoryIPC($this->key, null, 262144, 4096);

        $mode = $this->readSegmentMode($this->key);

        if ($mode === null) {
            self::markTestSkipped('无法通过 ipcs 读取共享内存段权限');
        }

        self::assertSame('--rw-------', $mode, '共享内存段必须是 0600');
    }

    /**
     * 改写头部中声明的 capacity，使其远超物理段能容纳的槽位数。
     */
    private function inflateDeclaredCapacity(SharedMemoryIPC $ipc, int $capacity): void
    {
        (new \ReflectionProperty($ipc, 'capacity'))->setValue($ipc, $capacity);

        $writeHeader = new \ReflectionMethod($ipc, 'writeHeader');
        $writeHeader->invoke($ipc, 0, 0, 0);
    }

    /**
     * 通过 ipcs 读取指定 key 的共享内存段权限串，读不到返回 null。
     */
    private function readSegmentMode(int $key): ?string
    {
        $output = @shell_exec('ipcs -m 2>/dev/null');

        if (!is_string($output) || $output === '') {
            return null;
        }

        $needle = sprintf('0x%08x', $key);

        foreach (explode("\n", $output) as $line) {
            if (!str_contains(strtolower($line), $needle)) {
                continue;
            }
            if (preg_match('/(-{2}[rwx-]{9})/', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
