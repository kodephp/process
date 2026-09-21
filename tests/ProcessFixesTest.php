<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Master\MasterProcess;
use Kode\Process\Process;
use Kode\Process\Contracts\ProcessInterface;
use Kode\Process\Signal;
use Kode\Process\Worker\WorkerProcess;
use PHPUnit\Framework\TestCase;

/**
 * v5.3.0 修复回归：
 *  - master 侧代理对象 stop() 必须真正终止并回收 fork 出的子进程（原实现直接 return 留下孤儿）；
 *  - Process::wait() 按终止类别取值，信号死亡不再把信号号当退出码；
 *  - MasterProcess::start() 监听失败回滚 PID 文件（否则下次启动误判已在运行）；
 *  - max_restart_attempts 配置生效。
 */
final class ProcessFixesTest extends TestCase
{
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $this->tmpFiles = [];
    }

    private function tmpFile(string $name): string
    {
        $path = sys_get_temp_dir() . '/kode-proc-fix-' . getmypid() . '-' . $name;
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function setProp(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    public function testForceStopTerminatesAndReapsRemoteChild(): void
    {
        $worker = new WorkerProcess(1);

        $pid = pcntl_fork();

        if ($pid === 0) {
            while (true) {
                usleep(10000);
            }
        }

        $this->assertGreaterThan(0, $pid);

        // 模拟 master 侧代理：持有子进程 pid，但 running 恒为 false（仅子进程内置 true）
        $this->setProp($worker, 'pid', $pid);
        $this->setProp($worker, 'state', ProcessInterface::STATE_RUNNING);

        $worker->stop(false);

        // 必须已被回收：僵尸状态下 posix_kill(pid,0) 仍为 true，故再 waitpid 一次确认彻底消失
        $this->assertFalse(@posix_kill($pid, 0), '子进程未被终止或未被回收（僵尸残留）');

        $ref = new \ReflectionProperty($worker::class, 'state');
        $ref->setAccessible(true);
        $this->assertSame(ProcessInterface::STATE_STOPPED, $ref->getValue($worker));
    }

    public function testGracefulStopTerminatesAndReapsRemoteChild(): void
    {
        $worker = new WorkerProcess(2);

        $pid = pcntl_fork();

        if ($pid === 0) {
            while (true) {
                usleep(10000);
            }
        }

        $this->setProp($worker, 'pid', $pid);

        $start = microtime(true);
        $worker->stop(true);

        // 子进程不处理 SIGTERM → 默认终止；优雅等待应在超时前因回收成功而退出
        $this->assertLessThan(9.0, microtime(true) - $start, '优雅停止未走回收提前退出路径');
        $this->assertFalse(@posix_kill($pid, 0));
    }

    public function testWaitDistinguishesSignalDeathFromExitCode(): void
    {
        $pid = pcntl_fork();

        if ($pid === 0) {
            posix_kill(posix_getpid(), Signal::KILL);
            exit(0);
        }

        $result = Process::wait($pid, false);

        $this->assertSame($pid, $result['pid']);
        $this->assertTrue($result['signaled']);
        $this->assertSame(Signal::KILL, $result['signal']);
        $this->assertSame(0, $result['exit_code'], '信号死亡时退出码不得混入信号编号');
    }

    public function testWaitReportsNormalExitCode(): void
    {
        $pid = pcntl_fork();

        if ($pid === 0) {
            exit(7);
        }

        $result = Process::wait($pid, false);

        $this->assertSame(7, $result['exit_code']);
        $this->assertFalse($result['signaled']);
    }

    public function testStartRollsBackPidFileWhenListenFails(): void
    {
        $pidFile = $this->tmpFile('master.pid');

        $master = new MasterProcess([
            'pid_file' => $pidFile,
            'log_file' => $this->tmpFile('master.log'),
            // 端口号会被 C 层 uint16 截断、macOS 又允许非 root 绑低端口，
            // 用保留的 E 类地址保证 bind 必失败（EADDRNOTAVAIL）
            'host' => '240.0.0.1',
            'port' => 19999,
        ]);

        try {
            $master->start();
            $this->fail('低端口绑定应抛 ProcessException');
        } catch (ProcessException $e) {
            $this->assertStringContainsString('绑定', $e->getMessage());
        }

        $this->assertFileDoesNotExist($pidFile, '监听失败后 PID 文件必须回滚删除');
        $this->assertFalse($master->isRunning());
    }

    public function testMaxRestartAttemptsComesFromConfig(): void
    {
        $master = new MasterProcess([
            'pid_file' => null,
            'log_file' => null,
            'max_restart_attempts' => '3',
        ]);

        $ref = new \ReflectionProperty($master::class, 'maxRestartAttempts');
        $ref->setAccessible(true);

        $this->assertSame(3, $ref->getValue($master));
    }
}
