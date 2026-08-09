<?php

declare(strict_types=1);

namespace Kode\Process\Tests\GlobalData;

use Kode\Process\GlobalData\SharedMemoryTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SharedMemoryTable 跨进程缓存一致性回归测试。
 *
 * 同一个共享内存键上建两个实例即可等价模拟两个进程：
 * 每个实例持有各自独立的进程内缓存（key→varId），
 * 这正是「一个进程 clear()/delete() 后，另一个进程缓存失效」这类缺陷的复现条件。
 */
#[Group('globaldata')]
final class SharedMemoryTableCoherencyTest extends TestCase
{
    private const int SEGMENT_SIZE = 262144;

    /** @var SharedMemoryTable[] */
    private array $tables = [];

    private int $key = 0;

    protected function setUp(): void
    {
        if (!SharedMemoryTable::isSupported()) {
            self::markTestSkipped('需要 sysvshm / sysvsem 扩展');
        }

        // 每个用例用一个互不相同的键，避免相互污染与遗留段干扰
        $this->key = 0x4B54_0000 + random_int(1, 0xFFFE);
    }

    protected function tearDown(): void
    {
        $first = array_shift($this->tables);

        foreach ($this->tables as $table) {
            $table->close();
        }

        $first?->destroy();

        $this->tables = [];
    }

    /**
     * 打开一个「进程视角」——独立实例，独立进程内缓存。
     */
    private function openTable(): SharedMemoryTable
    {
        $table = new SharedMemoryTable($this->key, self::SEGMENT_SIZE);
        $this->tables[] = $table;

        return $table;
    }

    /**
     * 缺陷复现：A 缓存了 alpha→varId；B 执行 clear() 把分配游标回退到低位，
     * 新键 beta 复用同一个 varId，于是 A 读 alpha 拿到的是 beta 的值。
     */
    public function testClearInAnotherProcessDoesNotAliasRecycledVarId(): void
    {
        $a = $this->openTable();
        $b = $this->openTable();

        $a->set('alpha', 'ALPHA-VALUE');
        self::assertSame('ALPHA-VALUE', $a->get('alpha'), '写入后应可读回，并填充 A 的进程内缓存');

        // 另一个「进程」清空整表，随后写入一个全新的键
        $b->clear();
        $b->set('beta', 'BETA-VALUE');

        self::assertNull($a->get('alpha'), 'alpha 已被 clear() 删除，绝不能读到 beta 的值');
        self::assertFalse($a->exists('alpha'));
        self::assertSame('BETA-VALUE', $a->get('beta'));
        self::assertSame(['beta'], $a->keys());
    }

    /**
     * clear() 之后分配游标不得回退：新键必须拿到全新的槽位。
     */
    public function testClearDoesNotRewindAllocationCursor(): void
    {
        $a = $this->openTable();

        $a->set('first', 1);
        $before = $this->varIdOf($a, 'first');

        $a->clear();
        $a->set('second', 2);
        $after = $this->varIdOf($a, 'second');

        self::assertGreaterThan($before, $after, 'clear() 后新键不得复用已释放的槽位');
    }

    /**
     * 另一个「进程」删除键后，本进程缓存必须失效，而不是继续读旧槽位。
     */
    public function testDeleteInAnotherProcessInvalidatesLocalCache(): void
    {
        $a = $this->openTable();
        $b = $this->openTable();

        $a->set('shared', 'V1');
        self::assertSame('V1', $b->get('shared'), '填充 B 的进程内缓存');

        $a->delete('shared');

        self::assertNull($b->get('shared'));
        self::assertFalse($b->exists('shared'));
        self::assertSame(0, $b->count());
    }

    /**
     * 删除后另一进程重新写入同名键，本进程必须读到新值。
     */
    public function testRecreatedKeyIsVisibleToStaleCache(): void
    {
        $a = $this->openTable();
        $b = $this->openTable();

        $a->set('cfg', 'OLD');
        self::assertSame('OLD', $b->get('cfg'));

        $a->delete('cfg');
        $a->set('cfg', 'NEW');

        self::assertSame('NEW', $b->get('cfg'));
    }

    /**
     * false 是合法值：get() 返回 false 必须表示「存的就是 false」，
     * 「键不存在」一律返回 null，并可用 exists() 明确区分。
     */
    public function testFalseValueIsDistinguishableFromMissingKey(): void
    {
        $a = $this->openTable();

        $a->set('flag', false);
        self::assertFalse($a->get('flag'), '存进去的 false 应原样读回');
        self::assertTrue($a->exists('flag'));

        self::assertNull($a->get('never-written'), '不存在的键必须返回 null 而不是 false');
        self::assertFalse($a->exists('never-written'));

        $a->delete('flag');
        self::assertNull($a->get('flag'), '删除后必须返回 null');
        self::assertFalse($a->exists('flag'));
    }

    /**
     * 跨实例的 false 值同样不能被误判成「不存在」。
     */
    public function testFalseValueSurvivesCrossInstanceRead(): void
    {
        $a = $this->openTable();
        $b = $this->openTable();

        $a->set('disabled', false);

        self::assertTrue($b->exists('disabled'));
        self::assertFalse($b->get('disabled'));

        $a->delete('disabled');

        self::assertFalse($b->exists('disabled'));
        self::assertNull($b->get('disabled'));
    }

    /**
     * 另一进程删除键后，本进程的 increment 必须从 0 重新起算，而不是读到脏槽位。
     */
    public function testIncrementAfterRemoteDeleteRestartsFromZero(): void
    {
        $a = $this->openTable();
        $b = $this->openTable();

        $a->set('counter', 100);
        self::assertSame(101, $b->increment('counter'));

        $a->delete('counter');

        self::assertSame(1, $b->increment('counter'));
    }

    /**
     * 从共享目录里读出某个键实际占用的槽位号。
     */
    private function varIdOf(SharedMemoryTable $table, string $key): int
    {
        $shm = (new \ReflectionProperty($table, 'shm'))->getValue($table);
        $dir = shm_get_var($shm, 1);

        self::assertIsArray($dir);
        self::assertArrayHasKey($key, $dir['keys']);

        return (int) $dir['keys'][$key]['v'];
    }
}
