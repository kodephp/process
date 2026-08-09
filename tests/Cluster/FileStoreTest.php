<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Store\FileStore;
use Kode\Process\Cluster\Store\StoreInterface;
use Kode\Process\Exceptions\ClusterException;
use PHPUnit\Framework\TestCase;

/**
 * 校验 {@see StoreInterface} 的五条原子原语在 FileStore 上的语义。
 *
 * FileStore 是唯一无需外部服务即可跑通的后端，因此把契约测试压在它上面；
 * Redis / GlobalData 后端复用同一份语义，差异只在实现手段（Lua / 服务端指令）。
 *
 * 约定：测试文件名与类名一致（PHPUnit 每文件只发现同名类）。
 */
final class FileStoreTest extends TestCase
{
    private FileStore $store;

    private string $path;

    protected function setUp(): void
    {
        $this->path  = sys_get_temp_dir() . '/kode-cluster-test-' . getmypid() . '-' . uniqid();
        $this->store = new FileStore(['path' => $this->path]);
    }

    protected function tearDown(): void
    {
        $this->store->flush();
        $this->store->close();

        if (is_dir($this->path)) {
            foreach ((array) glob($this->path . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->path);
        }
    }

    public function testIsAvailableAndName(): void
    {
        $this->assertTrue(FileStore::isAvailable());
        $this->assertSame('file', $this->store->name());
        $this->assertSame($this->path, $this->store->path());
    }

    public function testThrowsWhenDirectoryCannotBeCreated(): void
    {
        $this->expectException(ClusterException::class);

        new FileStore(['path' => '/proc/kode-cluster-should-fail']);
    }

    public function testSetGetRoundTripsScalarsAndStructures(): void
    {
        $cases = [
            'int'    => 42,
            'float'  => 3.5,
            'string' => 'hello',
            'bool'   => true,
            'null'   => null,
            'array'  => ['a' => 1, 'b' => [2, 3]],
        ];

        foreach ($cases as $key => $value) {
            $this->assertTrue($this->store->set($key, $value), $key);
            $this->assertSame($value, $this->store->get($key), $key);
        }
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->store->get('nope'));
        $this->assertFalse($this->store->exists('nope'));
    }

    public function testSetIfAbsentOnlySucceedsOnce(): void
    {
        $this->assertTrue($this->store->setIfAbsent('lock', 'first'));
        $this->assertFalse($this->store->setIfAbsent('lock', 'second'));
        $this->assertSame('first', $this->store->get('lock'));
    }

    public function testSetIfAbsentSucceedsAfterExpiry(): void
    {
        $this->assertTrue($this->store->setIfAbsent('lease', 'a', 30));
        $this->assertFalse($this->store->setIfAbsent('lease', 'b', 30));

        usleep(45_000);

        $this->assertTrue($this->store->setIfAbsent('lease', 'b', 30));
        $this->assertSame('b', $this->store->get('lease'));
    }

    public function testCompareAndSetGuardsAgainstStaleValue(): void
    {
        $this->store->set('k', 'v1');

        $this->assertFalse($this->store->compareAndSet('k', 'wrong', 'v2'));
        $this->assertSame('v1', $this->store->get('k'));

        $this->assertTrue($this->store->compareAndSet('k', 'v1', 'v2'));
        $this->assertSame('v2', $this->store->get('k'));
    }

    public function testCompareAndSetFailsOnMissingKey(): void
    {
        $this->assertFalse($this->store->compareAndSet('ghost', null, 'v'));
        $this->assertNull($this->store->get('ghost'));
    }

    public function testCompareAndDeleteOnlyRemovesOwnValue(): void
    {
        $this->store->set('lock', 'token-a');

        $this->assertFalse($this->store->compareAndDelete('lock', 'token-b'));
        $this->assertTrue($this->store->exists('lock'));

        $this->assertTrue($this->store->compareAndDelete('lock', 'token-a'));
        $this->assertFalse($this->store->exists('lock'));
    }

    public function testIncrementCreatesAndAccumulates(): void
    {
        $this->assertSame(1, $this->store->increment('counter'));
        $this->assertSame(4, $this->store->increment('counter', 3));
        $this->assertSame(2, $this->store->increment('counter', -2));
        $this->assertSame(2, $this->store->get('counter'));
    }

    public function testExpireRemovesKeyAfterTtl(): void
    {
        $this->store->set('temp', 'x');

        $this->assertTrue($this->store->expire('temp', 30));
        $this->assertSame('x', $this->store->get('temp'));

        usleep(45_000);

        $this->assertNull($this->store->get('temp'));
    }

    public function testExpireReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->store->expire('missing', 1000));
    }

    public function testTtlZeroMeansForever(): void
    {
        $this->store->set('perm', 'x', 0);

        usleep(20_000);

        $this->assertSame('x', $this->store->get('perm'));
    }

    public function testMgetReturnsOnlyExistingKeys(): void
    {
        $this->store->set('a', 1);
        $this->store->set('b', 2);

        $got = $this->store->mget(['a', 'b', 'missing']);

        $this->assertSame(['a' => 1, 'b' => 2], $got);
    }

    public function testKeysFiltersByPrefix(): void
    {
        $this->store->set('reg/api/n1', 1);
        $this->store->set('reg/api/n2', 2);
        $this->store->set('reg/web/n3', 3);
        $this->store->set('lock/x', 4);

        $api = $this->store->keys('reg/api/');
        sort($api);

        $this->assertSame(['reg/api/n1', 'reg/api/n2'], $api);
        $this->assertCount(3, $this->store->keys('reg/'));
        $this->assertCount(4, $this->store->keys());
    }

    public function testKeysSkipsExpiredEntries(): void
    {
        $this->store->set('reg/live', 1);
        $this->store->set('reg/dead', 2, 30);

        usleep(45_000);

        $this->assertSame(['reg/live'], $this->store->keys('reg/'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->store->set('a', 1);

        // 契约：删除幂等，键不存在也返回 true。
        // 「只删自己的」由 compareAndDelete 负责，不靠 delete 的返回值分支。
        $this->assertTrue($this->store->delete('a'));
        $this->assertTrue($this->store->delete('a'));
        $this->assertFalse($this->store->exists('a'));
    }

    public function testFlushClearsNamespace(): void
    {
        $this->store->set('a', 1);
        $this->store->set('b', 2);

        $this->assertSame(2, $this->store->flush());
        $this->assertSame([], $this->store->keys());
    }

    public function testPrefixIsolatesNamespaces(): void
    {
        $scoped = new FileStore(['path' => $this->path, 'prefix' => 'tenant-a']);
        $scoped->set('k', 'scoped');
        $this->store->set('k', 'root');

        $this->assertSame('scoped', $scoped->get('k'));
        $this->assertSame('root', $this->store->get('k'));

        $scoped->flush();
        @rmdir($this->path . '/tenant-a');
    }

    public function testObjectValueRoundTrips(): void
    {
        $obj = new \stdClass();
        $obj->name = 'x';
        $obj->nested = ['a' => 1];

        $this->store->set('obj', $obj);

        $got = $this->store->get('obj');
        $this->assertEquals($obj, $got);
    }

    public function testDirectoryIsRestrictedToOwner(): void
    {
        // 协调目录必须仅属主可访问，否则同机其它用户可投毒实现对象注入
        $perms = fileperms($this->path) & 0o777;
        $this->assertSame(0o700, $perms);
    }
}
