<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Exceptions\GlobalDataException;
use Kode\Process\GlobalData\ApcuTable;
use Kode\Process\GlobalData\GlobalData;
use Kode\Process\GlobalData\SwooleTable;
use Kode\Process\GlobalData\TableInterface;
use PHPUnit\Framework\TestCase;

/**
 * GlobalData 门面 + 多后端 TableInterface 一致性测试。
 *
 * 在「已安装什么就用什么」的策略下，本测试对当前环境所有可用后端（Swoole Table /
 * APCu / 共享内存）跑同一套 {@see TableInterface} 行为断言，保证语义一致；
 * 不可用后端通过 markTestSkipped 跳过，装了对应扩展时自动获得覆盖。
 */
final class GlobalDataTest extends TestCase
{
    protected function tearDown(): void
    {
        GlobalData::reset();
    }

    public function testFacadeReportsSupportedBackends(): void
    {
        $available = GlobalData::available();
        $this->assertIsArray($available);
        $this->assertContains(GlobalData::BACKEND_SHM, $available);
        $this->assertSame(GlobalData::BACKEND_SHM, GlobalData::preferred());
    }

    public function testAutoReturnsUsableTable(): void
    {
        $table = GlobalData::auto(0x77000002);
        $this->assertInstanceOf(TableInterface::class, $table);
        $this->assertSame(GlobalData::preferred(), $table->backend());
        $table->destroy();
    }

    public function testMakeUnknownBackendThrows(): void
    {
        $this->expectException(GlobalDataException::class);
        GlobalData::make('nope');
    }

    public function testMakeUnsupportedKnownBackendThrows(): void
    {
        if (SwooleTable::isSupported()) {
            $this->markTestSkipped('本机已安装 swoole');
        }
        $this->expectException(GlobalDataException::class);
        GlobalData::make(GlobalData::BACKEND_SWOOLE, 0x77000009);
    }

    public function testSupportsMirrorsBackendAvailability(): void
    {
        $this->assertSame(SwooleTable::isSupported(), GlobalData::supports(GlobalData::BACKEND_SWOOLE));
        $this->assertSame(ApcuTable::isSupported(), GlobalData::supports(GlobalData::BACKEND_APCU));
        $this->assertTrue(GlobalData::supports(GlobalData::BACKEND_SHM));
    }

    public function testDiagnoseStructure(): void
    {
        $diag = GlobalData::diagnose();
        $this->assertArrayHasKey(GlobalData::BACKEND_SHM, $diag);
        $this->assertTrue($diag[GlobalData::BACKEND_SHM]['available']);
        $this->assertArrayHasKey('requires', $diag[GlobalData::BACKEND_SHM]);
        $this->assertArrayHasKey('note', $diag[GlobalData::BACKEND_SHM]);
    }

    public function testDefaultSingletonAndReset(): void
    {
        $a = GlobalData::default(0x77000005);
        $b = GlobalData::default();
        $this->assertSame($a, $b);
        $a->destroy();
        GlobalData::reset();

        $c = GlobalData::default(0x77000006);
        $this->assertNotSame($a, $c);
        $c->destroy();
        GlobalData::reset();
    }

    public function testSharedMemoryHelpers(): void
    {
        $t = GlobalData::table(0x77000003, 1024 * 1024);
        $t->set('x', 42);
        $this->assertSame(42, $t->get('x'));
        $t->destroy();

        $file = tempnam(sys_get_temp_dir(), 'kode_gd');
        $o = GlobalData::open($file, 'g', 1024 * 1024);
        $o->set('y', 'z');
        $this->assertSame('z', $o->get('y'));
        $o->destroy();
        @unlink($file);
    }

    public function testTableInterfaceOnSupportedBackends(): void
    {
        $backends = array_values(array_filter(
            [GlobalData::BACKEND_SWOOLE, GlobalData::BACKEND_APCU, GlobalData::BACKEND_SHM],
            [GlobalData::class, 'supports'],
        ));

        if ($backends === []) {
            $this->markTestSkipped('无可用 GlobalData 后端（需 swoole/apcu/sysvshm 之一）');
        }

        $baseKey = 0x77000001;
        foreach ($backends as $i => $backend) {
            $size = $backend === GlobalData::BACKEND_SHM ? 1024 * 1024 : 65536;
            $table = GlobalData::make($backend, $baseKey + $i * 17, $size);
            $this->assertTableInterfaceBehavior($table, $backend);
            $table->destroy();
        }
    }

    private function assertTableInterfaceBehavior(TableInterface $table, string $backend): void
    {
        $table->clear();

        // 后端标识
        $this->assertSame($backend, $table->backend());

        // 基本读写
        $table->set('a', 'hello');
        $this->assertSame('hello', $table->get('a'));

        // 假值 / null 与「不存在」可正确区分
        $table->set('b', false);
        $this->assertFalse($table->get('b'));
        $this->assertTrue($table->exists('b'));
        $table->set('c', 0);
        $this->assertSame(0, $table->get('c'));
        $this->assertTrue($table->exists('c'));
        $table->set('d', null);
        $this->assertNull($table->get('d'));
        $this->assertTrue($table->exists('d'));

        $this->assertNull($table->get('nope'));
        $this->assertFalse($table->exists('nope'));

        // add / replace
        $this->assertTrue($table->add('e', 1));
        $this->assertFalse($table->add('e', 2));
        $this->assertSame(1, $table->get('e'));
        $this->assertFalse($table->replace('nope', 1));
        $this->assertTrue($table->replace('e', 5));
        $this->assertSame(5, $table->get('e'));

        // 原子自增 / 自减
        $this->assertSame(6, $table->increment('e'));
        $this->assertSame(1, $table->increment('newc'));
        $this->assertSame(0, $table->decrement('newc'));
        $this->assertSame(0.5, $table->increment('f', 0.5));

        // CAS
        $this->assertTrue($table->cas('e', 6, 60));
        $this->assertSame(60, $table->get('e'));
        $this->assertFalse($table->cas('e', 999, 1));

        // 批量读写
        $table->setMultiple(['m1' => 1, 'm2' => 2]);
        $this->assertSame(['m1' => 1, 'm2' => 2, 'nope' => null], $table->getMultiple(['m1', 'm2', 'nope']));

        // 键集合
        $keys = $table->keys();
        $this->assertContains('a', $keys);
        $this->assertGreaterThan(0, $table->count());

        // TTL 惰性过期
        $table->set('ttl', 'x', 1);
        $this->assertSame('x', $table->get('ttl'));
        sleep(1);
        $this->assertNull($table->get('ttl'));
        $this->assertFalse($table->exists('ttl'));

        // 清空
        $table->clear();
        $this->assertSame(0, $table->count());

        // 统计
        $stats = $table->stats();
        $this->assertArrayHasKey('backend', $stats);
        $this->assertSame($backend, $stats['backend']);

        $table->close();
    }
}
