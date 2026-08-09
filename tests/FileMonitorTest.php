<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Process\Monitor\FileMonitor;

final class FileMonitorTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/kode_filemonitor_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            // 软链接必须直接删除，否则会顺着环形链接无限递归
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testAddWatchDir(): void
    {
        $monitor = new FileMonitor();
        $monitor->addWatchDir($this->testDir);

        $dirs = $monitor->getWatchDirs();
        $this->assertCount(1, $dirs);
        $this->assertEquals(realpath($this->testDir), $dirs[0]);
    }

    public function testScanFiles(): void
    {
        file_put_contents($this->testDir . '/test1.php', '<?php echo "test1";');
        file_put_contents($this->testDir . '/test2.php', '<?php echo "test2";');
        file_put_contents($this->testDir . '/test3.txt', 'text file');

        $monitor = new FileMonitor([$this->testDir]);
        $files = $monitor->scan();

        $this->assertCount(2, $files);
        
        $filePaths = array_keys($files);
        $this->assertTrue(
            in_array(realpath($this->testDir) . '/test1.php', $filePaths) ||
            in_array($this->testDir . '/test1.php', $filePaths)
        );
        $this->assertTrue(
            in_array(realpath($this->testDir) . '/test2.php', $filePaths) ||
            in_array($this->testDir . '/test2.php', $filePaths)
        );
    }

    public function testDetectNewFiles(): void
    {
        $monitor = new FileMonitor([$this->testDir]);
        $monitor->scan();

        file_put_contents($this->testDir . '/new.php', '<?php echo "new";');

        $changes = $monitor->checkChanges();

        $this->assertCount(1, $changes['added']);
        $this->assertCount(0, $changes['modified']);
        $this->assertCount(0, $changes['deleted']);
    }

    public function testDetectModifiedFiles(): void
    {
        $file = $this->testDir . '/test.php';
        file_put_contents($file, '<?php echo "original";');

        $monitor = new FileMonitor([$this->testDir]);
        $initialFiles = $monitor->scan();
        $monitor->applyChanges(['added' => array_keys($initialFiles), 'modified' => [], 'deleted' => []]);

        clearstatcache(true, $file);
        sleep(1);
        file_put_contents($file, '<?php echo "modified";');
        clearstatcache(true, $file);

        $changes = $monitor->checkChanges();

        $this->assertGreaterThanOrEqual(1, count($changes['modified']));
    }

    public function testDetectDeletedFiles(): void
    {
        $file = $this->testDir . '/delete.php';
        file_put_contents($file, '<?php echo "delete";');

        $monitor = new FileMonitor([$this->testDir]);
        $initialFiles = $monitor->scan();
        $monitor->applyChanges(['added' => array_keys($initialFiles), 'modified' => [], 'deleted' => []]);

        unlink($file);
        clearstatcache(true, $file);

        $changes = $monitor->checkChanges();

        $this->assertGreaterThanOrEqual(1, count($changes['deleted']));
    }

    public function testExcludeDirs(): void
    {
        $excludeDir = $this->testDir . '/exclude';
        mkdir($excludeDir);
        file_put_contents($excludeDir . '/test.php', '<?php echo "test";');

        $monitor = new FileMonitor([$this->testDir]);
        $monitor->addExcludeDir('exclude');
        $files = $monitor->scan();

        $this->assertCount(0, $files);
    }

    public function testSetExtensions(): void
    {
        file_put_contents($this->testDir . '/test.php', '<?php echo "test";');
        file_put_contents($this->testDir . '/test.js', 'console.log("test");');

        $monitor = new FileMonitor([$this->testDir]);
        $monitor->setExtensions(['.js']);
        $files = $monitor->scan();

        $this->assertCount(1, $files);
        
        $filePaths = array_keys($files);
        $this->assertTrue(
            in_array(realpath($this->testDir) . '/test.js', $filePaths) ||
            in_array($this->testDir . '/test.js', $filePaths)
        );
    }

    public function testOnChangeCallback(): void
    {
        $callbackCalled = false;
        $capturedChanges = [];

        $monitor = new FileMonitor([$this->testDir]);
        $monitor->scan();
        $monitor->setOnChange(function ($changes) use (&$callbackCalled, &$capturedChanges) {
            $callbackCalled = true;
            $capturedChanges = $changes;
        });

        file_put_contents($this->testDir . '/new.php', '<?php echo "new";');
        $monitor->tick();

        $this->assertTrue($callbackCalled);
        $this->assertCount(1, $capturedChanges['added']);
    }

    public function testCreateStaticMethod(): void
    {
        $monitor = FileMonitor::create([$this->testDir]);
        $this->assertInstanceOf(FileMonitor::class, $monitor);
    }

    /**
     * 回归守卫：tick() 上报变更后必须推进 mtime 基线。
     * 旧实现从不调用 applyChanges，同一次改动会在每个 tick 反复上报，
     * 热重载场景下退化为无限重启。
     */
    public function testTickAdvancesBaselineSoChangeIsReportedOnce(): void
    {
        $monitor = new FileMonitor([$this->testDir]);
        $monitor->applyChanges(['added' => array_keys($monitor->scan()), 'modified' => [], 'deleted' => []]);

        $calls = 0;
        $monitor->setOnChange(function () use (&$calls): void {
            $calls++;
        });

        file_put_contents($this->testDir . '/fresh.php', '<?php echo "fresh";');
        clearstatcache();

        $this->assertTrue($monitor->tick(), '首个 tick 必须检测到新增文件');
        $this->assertFalse($monitor->tick(), '同一次变更不得被重复上报');
        $this->assertFalse($monitor->tick());
        $this->assertSame(1, $calls);
    }

    /**
     * 回归守卫：变更回调抛异常不得穿透 tick()，
     * 否则 start() 的监视循环会被单次业务错误打死。
     */
    public function testThrowingChangeCallbackDoesNotEscapeTick(): void
    {
        $monitor = new FileMonitor([$this->testDir]);
        $monitor->applyChanges(['added' => array_keys($monitor->scan()), 'modified' => [], 'deleted' => []]);

        $monitor->setOnChange(function (): void {
            throw new \RuntimeException('回调炸了');
        });

        file_put_contents($this->testDir . '/boom.php', '<?php echo "boom";');
        clearstatcache();

        $this->assertTrue($monitor->tick());
        // 即便回调抛错，基线也已推进，不会陷入重复触发
        $this->assertFalse($monitor->tick());
    }

    /**
     * 回归守卫：指向祖先目录的软链接会让旧实现无限递归直至栈溢出。
     */
    public function testSymlinkLoopDoesNotHangScan(): void
    {
        $nested = $this->testDir . '/nested';
        mkdir($nested);
        file_put_contents($nested . '/a.php', '<?php echo "a";');

        if (!@symlink($this->testDir, $nested . '/loop')) {
            $this->markTestSkipped('当前环境不支持创建软链接');
        }

        $monitor = new FileMonitor([$this->testDir]);
        $files = $monitor->scan();

        $this->assertCount(1, $files);
        $this->assertArrayHasKey(realpath($nested) . '/a.php', $files);
    }

    /**
     * 回归守卫：不可读目录会让 scandir() 返回 false，
     * 旧实现直接 foreach(false) 触发 TypeError。
     */
    public function testUnreadableDirectoryIsSkipped(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root 用户可读取任意目录');
        }

        file_put_contents($this->testDir . '/ok.php', '<?php echo "ok";');

        $locked = $this->testDir . '/locked';
        mkdir($locked);
        file_put_contents($locked . '/hidden.php', '<?php echo "hidden";');
        chmod($locked, 0000);

        try {
            $monitor = new FileMonitor([$this->testDir]);
            $files = $monitor->scan();

            $this->assertArrayHasKey(realpath($this->testDir) . '/ok.php', $files);
        } finally {
            chmod($locked, 0777);
        }
    }

    public function testWatchAcceptsNullCallback(): void
    {
        // PHP 8.4 起隐式可空参数会触发弃用告警，这里同时守卫签名与行为
        $monitor = FileMonitor::watch([$this->testDir]);
        $this->assertInstanceOf(FileMonitor::class, $monitor);

        $reflection = new \ReflectionMethod(FileMonitor::class, 'watch');
        $param = $reflection->getParameters()[1];
        $this->assertTrue($param->allowsNull());
        $this->assertTrue($param->getType()?->allowsNull());
    }

    public function testApplyChangesToleratesVanishedFile(): void
    {
        $monitor = new FileMonitor([$this->testDir]);
        $ghost = $this->testDir . '/ghost.php';

        $monitor->applyChanges(['added' => [$ghost], 'modified' => [], 'deleted' => []]);

        $this->assertNotContains($ghost, $monitor->getTrackedFiles());
    }

    public function testRestoringOlderMtimeCountsAsModification(): void
    {
        $file = $this->testDir . '/rollback.php';
        file_put_contents($file, '<?php echo "new";');

        $monitor = new FileMonitor([$this->testDir]);
        $monitor->applyChanges(['added' => array_keys($monitor->scan()), 'modified' => [], 'deleted' => []]);

        // 模拟 git checkout 回退到更旧的版本（mtime 变小）
        touch($file, time() - 3600);
        clearstatcache(true, $file);

        $changes = $monitor->checkChanges();
        $this->assertContains(realpath($file), $changes['modified']);
    }
}
