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
            if (is_dir($path)) {
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
}
