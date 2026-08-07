<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Store\RedisStore;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * RedisStore 测试——用内存版 FakeRedis 注入，零外部依赖、可重复。
 *
 * 重点验证 {@see RedisStore::keys()} 的 SCAN 游标遍历：修复前 SCAN 只扫一趟即退出，
 * 数据量超过单页时会漏数据；修复后多趟收集完整。
 */
final class RedisStoreTest extends TestCase
{
    private RedisStore $store;

    private FakeRedis $redis;

    protected function setUp(): void
    {
        $this->redis = new FakeRedis();
        $this->store = new RedisStore(['client' => $this->redis]);
    }

    public function testSetGetRoundTrip(): void
    {
        $this->assertTrue($this->store->set('foo', ['a' => 1]));
        $this->assertSame(['a' => 1], $this->store->get('foo'));
    }

    public function testSetIfAbsentIsAtomic(): void
    {
        $this->assertTrue($this->store->setIfAbsent('lock', 'a'));
        // 键已存在，NX 写入必须失败
        $this->assertFalse($this->store->setIfAbsent('lock', 'b'));
        $this->assertSame('a', $this->store->get('lock'));
    }

    public function testExistsAndDelete(): void
    {
        $this->store->set('x', 1);
        $this->assertTrue($this->store->exists('x'));
        $this->assertTrue($this->store->delete('x'));
        $this->assertFalse($this->store->exists('x'));
    }

    public function testDeleteIsIdempotent(): void
    {
        // 与 Redis / GlobalData / FileStore 保持一致：删除不存在的键也返回 true
        $this->assertTrue($this->store->delete('never-existed'));
    }

    /**
     * 核心回归：SCAN 必须多趟收集完整。
     *
     * FakeRedis 每趟只返回 2 个键，因此 5 个键需要至少 3 趟才能拿全。
     * 若 keys() 只扫一趟（修复前的 bug），只会拿到前 2 个。
     */
    public function testKeysReturnsAllEntriesAcrossMultipleScanTrips(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->set('node-' . $i, $i);
        }

        $keys = $this->store->keys();

        $this->assertCount(5, $keys, 'SCAN 游标遍历必须多趟收集，漏数据说明只扫了一趟');
        $this->assertSame(['node-0', 'node-1', 'node-2', 'node-3', 'node-4'], $keys);
    }

    public function testKeysWithPrefixFiltersByNamespace(): void
    {
        $this->store->set('api-1', 1);
        $this->store->set('web-1', 2);

        $this->assertSame(['api-1'], $this->store->keys('api'));
    }

    public function testFlushClearsNamespace(): void
    {
        $this->store->set('a', 1);
        $this->store->set('b', 2);

        $this->assertSame(2, $this->store->flush());
        $this->assertSame([], $this->store->keys());
    }
}

/**
 * 内存版 Redis 客户端，专门用来复现 SCAN 的游标分页语义。
 *
 * 不连接真实服务器，仅实现 RedisStore 实际调用到的几个方法。
 *
 * @internal
 */
final class FakeRedis extends Redis
{
    /** @var array<string, string> */
    private array $data = [];

    public function __construct() // 故意不调用 parent，避免真实连接
    {
    }

    public function set($key, $value, $options = null): bool
    {
        if (is_array($options) && in_array('nx', $options, true) && array_key_exists($key, $this->data)) {
            return false;
        }

        $this->data[$key] = $value;

        return true;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? false;
    }

    public function del(...$keys): int
    {
        $n = 0;
        foreach ($keys as $k) {
            if (array_key_exists($k, $this->data)) {
                unset($this->data[$k]);
                $n++;
            }
        }

        return $n;
    }

    public function exists($key, ...$other): int
    {
        return array_key_exists($key, $this->data) ? 1 : 0;
    }

    public function mget(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[] = $this->data[$k] ?? false;
        }

        return $out;
    }

    public function scan(string|int|null &$cursor, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        if ($cursor === null) {
            $cursor = 0;
        }

        $keys = array_keys($this->data);
        sort($keys);

        // 故意每趟只返回 2 个，制造多趟才能拿全，从而暴露「只扫一趟」的 bug
        $page   = 2;
        $slice  = array_slice($keys, (int) $cursor, $page);
        $next   = (int) $cursor + $page;
        $cursor = $next >= count($keys) ? 0 : $next;

        if ($pattern !== '' && str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            $slice  = array_values(array_filter($slice, static fn (string $k): bool => str_starts_with($k, $prefix)));
        }

        return $slice;
    }

    public function eval($script, $args = [], $numKeys = 0): mixed
    {
        return 0; // 本测试不覆盖 Lua 复合路径
    }

    public function close(): bool
    {
        return true;
    }
}
