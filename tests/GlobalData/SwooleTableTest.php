<?php

declare(strict_types=1);

namespace Kode\Process\Tests\GlobalData;

use Kode\Process\GlobalData\SwooleTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SwooleTable 后端契约测试（与 WorkermanTable 保持语义一致）。
 *
 * 本机未装 ext-swoole 时整组跳过；CI 装有扩展时才真正执行，用于锁住：
 *  - 过期键对 exists() / get() 不可见；
 *  - add() 可覆盖过期键、replace() 对过期键失败；
 *  - keys() 过滤过期键；
 *  - 默认反序列化阻断对象注入。
 */
#[Group('globaldata')]
final class SwooleTableTest extends TestCase
{
    protected function setUp(): void
    {
        if (!SwooleTable::isSupported()) {
            self::markTestSkipped('需要 ext-swoole');
        }

        parent::setUp();
    }

    public function testExpiredKeyIsInvisible(): void
    {
        $t = new SwooleTable(1024, 4096);
        $t->set('k', 'v', ttl: 1);
        usleep(1_100_000);

        self::assertFalse($t->exists('k'));
        self::assertNull($t->get('k'));
    }

    public function testAddSucceedsOverExpiredKey(): void
    {
        $t = new SwooleTable(1024, 4096);
        $t->set('k', 'old', ttl: 1);
        usleep(1_100_000);

        self::assertTrue($t->add('k', 'new'));
        self::assertSame('new', $t->get('k'));
    }

    public function testReplaceFailsOverExpiredKey(): void
    {
        $t = new SwooleTable(1024, 4096);
        $t->set('k', 'old', ttl: 1);
        usleep(1_100_000);

        self::assertFalse($t->replace('k', 'new'));
        self::assertNull($t->get('k'));
    }

    public function testKeysExcludesExpired(): void
    {
        $t = new SwooleTable(1024, 4096);
        $t->set('live', 1);
        $t->set('dead', 1, ttl: 1);
        usleep(1_100_000);

        self::assertSame(['live'], $t->keys());
    }

    public function testClearRemovesAllRows(): void
    {
        $t = new SwooleTable(1024, 4096);
        $t->set('a', 1);
        $t->set('b', 2, ttl: 1);
        $t->clear();

        self::assertSame([], $t->keys());
        self::assertFalse($t->exists('a'));
    }

    public function testObjectInjectionBlockedByDefault(): void
    {
        $t = new SwooleTable(1024, 4096);
        $t->set('obj', new \stdClass());

        $out = $t->get('obj');
        self::assertNotInstanceOf(\stdClass::class, $out);
    }
}
