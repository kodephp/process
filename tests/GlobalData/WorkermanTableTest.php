<?php

declare(strict_types=1);

namespace Kode\Process\Tests\GlobalData;

use Kode\Process\GlobalData\WorkermanTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * WorkermanTable 后端契约测试（v5.2.17 修复 TTL / 原子性语义不一致）。
 *
 * 本机未装 Workerman\Table（仅旧版 v3 + ext-swoole）时整组跳过；CI 装有扩展时才真正执行，
 * 用于锁住 WorkermanTable 与 SwooleTable / SharedMemoryTable 一致的 TTL 语义：
 *  - exists() 对过期键返回 false（此前用 $table->exist() 不检查过期，误报为存在）；
 *  - add() 可覆盖过期键（此前误判「已存在」而失败）；
 *  - replace() 对过期键失败（此前误判「已存在」而覆盖）；
 *  - keys() 过滤过期键（此前返回全部含过期）；
 *  - clear() 清掉所有行（含过期残留）；
 *  - 默认反序列化阻断对象注入。
 */
#[Group('globaldata')]
final class WorkermanTableTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WorkermanTable::isSupported()) {
            self::markTestSkipped('需要 Workerman\\Table（旧版 v3 + ext-swoole）');
        }

        parent::setUp();
    }

    public function testExpiredKeyIsInvisibleToExists(): void
    {
        $t = new WorkermanTable(1024, 4096);
        $t->set('k', 'v', ttl: 1);
        usleep(1_100_000);

        self::assertFalse($t->exists('k'), '过期键必须视为不存在');
        self::assertNull($t->get('k'));
    }

    public function testAddSucceedsOverExpiredKey(): void
    {
        $t = new WorkermanTable(1024, 4096);
        $t->set('k', 'old', ttl: 1);
        usleep(1_100_000);

        self::assertTrue($t->add('k', 'new'), '过期键应允许 add 覆盖');
        self::assertSame('new', $t->get('k'));
    }

    public function testReplaceFailsOverExpiredKey(): void
    {
        $t = new WorkermanTable(1024, 4096);
        $t->set('k', 'old', ttl: 1);
        usleep(1_100_000);

        self::assertFalse($t->replace('k', 'new'), '过期键应使 replace 失败');
        self::assertNull($t->get('k'));
    }

    public function testKeysExcludesExpired(): void
    {
        $t = new WorkermanTable(1024, 4096);
        $t->set('live', 1);
        $t->set('dead', 1, ttl: 1);
        usleep(1_100_000);

        self::assertSame(['live'], $t->keys());
    }

    public function testClearRemovesExpiredResidue(): void
    {
        $t = new WorkermanTable(1024, 4096);
        $t->set('a', 1);
        $t->set('b', 2, ttl: 1);
        usleep(1_100_000);
        // 过期键尚未被惰性清理，keys() 已不再返回它，但 clear() 必须连残留一并清掉
        $t->clear();

        self::assertSame([], $t->keys());
        self::assertFalse($t->exists('a'));
    }

    public function testObjectInjectionBlockedByDefault(): void
    {
        $t = new WorkermanTable(1024, 4096);
        $t->set('obj', new \stdClass());

        $out = $t->get('obj');
        self::assertNotInstanceOf(\stdClass::class, $out);
    }
}
