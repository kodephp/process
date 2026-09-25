<?php

declare(strict_types=1);

namespace Kode\Process\Daemon;

use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Process;
use Kode\Process\Signal;
use Kode\Process\Timer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 轻量常驻进程运行器（Resident Daemon）。
 *
 * 只依赖两个原语——{@see Process::fork()}（多进程）与 {@see Timer}（周期 / 定时调度），
 * 完全不碰 Master/Worker 池。原因：官方 {@see \Kode\Process\Worker\WorkerProcess::processTasks()}
 * 在事件循环里从不调用用户回调（见 src/Worker/WorkerProcess.php:201），`Process::start($config, $cb)`
 * 传入的回调只会被外部 {@see \Kode\Process\Worker\WorkerProcess::assignTask()} 触发，不主动跑——
 * 即「自定义周期任务」在官方池里实际是空转。
 *
 * 本运行器自建「监督进程 + N 个 worker 子进程」模型：
 *
 * - 监督进程（父）：fork 出 N 个 worker，负责信号、回收僵尸、异常退出重生、优雅退出。
 * - 每个 worker 子进程：独立事件循环，用 {@see Timer} 真正周期 / 定时执行用户任务。
 *
 * 用法：
 *
 * ```php
 * Kode::daemon()
 *     ->task(fn () => file_put_contents('/tmp/tick', time()))
 *     ->every(5)                 // 每 5 秒；或 ->cron('0 * * * *')
 *     ->workers(4)              // 4 个 worker 子进程
 *     ->daemonize()             // 脱离终端常驻（可选）
 *     ->pidFile('/var/run/app.pid')
 *     ->run();
 * ```
 */
final class Daemon
{
    /** 用户任务（每周期执行） */
    private $task = null;

    /** 透传给任务的参数 */
    private array $args = [];

    /** worker 子进程数量 */
    private int $workers = 1;

    /** 周期间隔（秒），当未使用 cron 时生效 */
    private float $interval = 1.0;

    /** cron 表达式；非空时覆盖 interval */
    private ?string $cron = null;

    /** 是否脱离终端（两次 fork + setsid） */
    private bool $daemonize = false;

    /** PID 文件路径（写监督进程 PID） */
    private string $pidFile;

    /** 单槽累计重生上限，超过则放弃该槽位，防 fork bomb */
    private int $maxRestarts = 1000;

    /** 重生前的退避延迟（秒） */
    private float $restartDelay = 0.1;

    /** worker 健康存活达到该秒数后，累计重生计数清零（视为已稳定） */
    private float $healthyWindow = 60.0;

    private LoggerInterface $logger;

    // ---------------------------------------------------------- 运行时状态

    /** slot => 子进程 pid */
    private array $childPids = [];

    /** slot => 累计重生次数（worker 健康存活超过窗口后清零，避免长期运行缓慢逼近上限） */
    private array $restartCount = [];

    /** slot => 本次派生时刻（microtime），用于判定是否达到「健康窗口」 */
    private array $childSpawnAt = [];

    /** 监督进程是否正在退出 */
    private bool $stopping = false;

    /** 是否收到平滑重启（USR1）请求 */
    private bool $reloadRequested = false;

    /** worker 子进程是否收到停止信号（每个子进程独立副本） */
    private bool $workerStopping = false;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->pidFile = sys_get_temp_dir() . '/kode-daemon.pid';
    }

    /** 静态工厂 */
    public static function define(?LoggerInterface $logger = null): self
    {
        return new self($logger);
    }

    // -------------------------------------------------------------- 构造器

    public function task(callable $cb, array $args = []): self
    {
        $this->task = $cb;
        $this->args = $args;

        return $this;
    }

    public function every(float $seconds): self
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('every() 间隔必须 > 0');
        }

        $this->interval = $seconds;
        $this->cron = null;

        return $this;
    }

    public function cron(string $expression): self
    {
        $this->cron = $expression;

        return $this;
    }

    public function workers(int $count): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('workers() 数量必须 >= 1');
        }

        $this->workers = $count;

        return $this;
    }

    public function daemonize(bool $v = true): self
    {
        $this->daemonize = $v;

        return $this;
    }

    public function pidFile(string $path): self
    {
        $this->pidFile = $path;

        return $this;
    }

    public function maxRestarts(int $n): self
    {
        $this->maxRestarts = max(0, $n);

        return $this;
    }

    /**
     * 设置健康窗口（秒）：worker 连续存活超过该时长后，累计重生计数清零。
     * 防止长期运行下历史偶发崩溃缓慢逼近 maxRestarts 上限。
     */
    public function healthyWindow(float $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('healthyWindow() 必须 >= 0');
        }

        $this->healthyWindow = $seconds;

        return $this;
    }

    // ---------------------------------------------------------------- 取值

    public function getPidFile(): string
    {
        return $this->pidFile;
    }

    public function getWorkers(): int
    {
        return $this->workers;
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function getCron(): ?string
    {
        return $this->cron;
    }

    public function isDaemonize(): bool
    {
        return $this->daemonize;
    }

    // ---------------------------------------------------------------- 运行

    /**
     * 启动常驻运行器：fork worker 并进入监督循环，直到收到停止信号。
     *
     * @throws ProcessException 未设置任务、非 CLI、pid 文件被存活进程占用、daemonize 失败
     */
    public function run(): void
    {
        if ($this->task === null) {
            throw new ProcessException('Daemon::task() 必须先设置要执行的任务');
        }

        if (PHP_SAPI !== 'cli') {
            throw new ProcessException('Daemon 只能在 CLI SAPI 下运行');
        }

        // 先占位判定再守护化：为了「文件被别人占」而 fork 一次再退出，
        // 等于把失败原因连同子进程一起丢掉（守护化后父进程已 exit，没人读得到异常）。
        $this->claimPidFile();

        if ($this->daemonize && !Process::daemonize()) {
            throw new ProcessException('daemonize() 失败');
        }

        // 守护化会换 pid，所以落盘必须在它之后（此处再判一次，以最终 pid 为准）。
        $this->writePidFile();
        $this->installSupervisorSignals();

        for ($slot = 0; $slot < $this->workers; $slot++) {
            $this->spawnWorker($slot);
        }

        $this->supervise();

        $this->cleanup();
    }

    /**
     * 在子进程内运行一个 worker：注册定时任务并进入事件循环，直到被信号停止。
     *
     * 设计为可被测试在 fork 后直接调用，从而验证「用户任务确实由 Timer 执行」。
     */
    public function runWorker(int $slot): void
    {
        $this->installWorkerSignals();

        if ($this->cron !== null) {
            Timer::cron($this->cron, $this->task, $this->args);
        } else {
            Timer::forever($this->interval, $this->task, $this->args);
        }

        while (!$this->workerStopping) {
            Timer::tick();
            pcntl_signal_dispatch();
            usleep(10_000);
        }

        $this->logger->info('Daemon worker 已退出', [
            'slot' => $slot,
            'pid' => posix_getpid(),
        ]);

        exit(0);
    }

    // ------------------------------------------------------- 监督进程逻辑

    private function supervise(): void
    {
        while (!$this->stopping) {
            pcntl_signal_dispatch();

            if ($this->reloadRequested) {
                $this->reloadRequested = false;
                $this->restartAllWorkers();
            }

            // 回收已退出的子进程（WNOHANG 轮询，确定性、不依赖 SIGCHLD 时序）
            while (true) {
                $result = Process::wait(null, true);

                if ($result['pid'] <= 0) {
                    break;
                }

                $slot = array_search($result['pid'], $this->childPids, true);

                if ($slot === false) {
                    continue; // 不是本运行器管理的子进程
                }

                if ($this->stopping) {
                    unset($this->childPids[$slot]);
                    continue;
                }

                // 异常退出 → 带上限重生（含健康窗口重置逻辑）
                $this->bumpRestartCount($slot);

                if ($this->exceedsRestartBudget($slot)) {
                    $this->logger->error('Daemon worker 重生次数超限，放弃该槽位', [
                        'slot' => $slot,
                        'count' => $this->restartCount[$slot],
                    ]);
                    unset($this->childPids[$slot]);
                    continue;
                }

                $this->logger->warning('Daemon worker 异常退出，准备重生', [
                    'slot' => $slot,
                    'pid' => $result['pid'],
                    'count' => $this->restartCount[$slot],
                ]);

                usleep((int) ($this->restartDelay * 1_000_000));
                $this->spawnWorker($slot);
            }

            usleep(50_000);
        }

        $this->stopAllWorkers();
    }

    /**
     * 单槽重生次数是否已超出预算（超过则放弃该槽位，防 fork bomb）。
     */
    private function exceedsRestartBudget(int $slot): bool
    {
        return ($this->restartCount[$slot] ?? 0) > $this->maxRestarts;
    }

    /**
     * 递增单槽重生计数；若该槽位已健康存活超过 healthyWindow，先清零旧计数再 +1。
     */
    private function bumpRestartCount(int $slot): void
    {
        if (($this->childSpawnAt[$slot] ?? 0) > 0
            && (microtime(true) - $this->childSpawnAt[$slot]) >= $this->healthyWindow) {
            $this->restartCount[$slot] = 0;
        }

        $this->restartCount[$slot] = ($this->restartCount[$slot] ?? 0) + 1;
    }

    private function spawnWorker(int $slot): void
    {
        $pid = Process::fork(function () use ($slot): void {
            $this->runWorker($slot);
        });

        $this->childPids[$slot] = $pid;
        $this->restartCount[$slot] ??= 0;
        $this->childSpawnAt[$slot] = microtime(true);

        $this->logger->info('Daemon worker 已启动', [
            'slot' => $slot,
            'pid' => $pid,
        ]);
    }

    private function stopAllWorkers(): void    {
        foreach ($this->childPids as $pid) {
            @posix_kill($pid, Signal::TERM);
        }

        // 等待优雅退出（最多约 5 秒）
        for ($i = 0; $i < 50; $i++) {
            if ($this->reapOnce() === 0) {
                break;
            }
            usleep(100_000);
        }

        // 残留强杀，避免孤儿
        foreach ($this->childPids as $pid) {
            @posix_kill($pid, Signal::KILL);
        }

        // 最后兜底回收
        while ($this->reapOnce() > 0) {
            // 继续回收直到无子进程
        }

        $this->childPids = [];
    }

    /** 回收一个已退出子进程，返回剩余被管理子进程数 */
    private function reapOnce(): int
    {
        while (true) {
            $result = Process::wait(null, true);

            if ($result['pid'] <= 0) {
                break;
            }

            $slot = array_search($result['pid'], $this->childPids, true);

            if ($slot !== false) {
                unset($this->childPids[$slot]);
            }
        }

        return count($this->childPids);
    }

    private function restartAllWorkers(): void
    {
        $this->stopAllWorkers();

        for ($slot = 0; $slot < $this->workers; $slot++) {
            $this->spawnWorker($slot);
        }

        $this->logger->info('Daemon worker 已全部平滑重启');
    }

    /**
     * 占住 pid 文件：确认没有**别的存活守护进程**登记在此，否则拒绝启动。
     *
     * 判据只有这一份（`run()` 守护化之前、`writePidFile()` 落盘之前各调一次）。
     * 此前 `run()` 是无条件 `file_put_contents()`，第二次启动会把第一代的 pid
     * 覆盖成自己的 —— 而 pid 文件是「谁在跑」的唯一来源，于是第一代从此查不到、
     * 收不到停止/重载信号，却仍在跑同一份资源。
     *
     * @throws ProcessException 文件里写着一个存活且非本进程的 pid
     */
    private function claimPidFile(): void
    {
        $raw = is_file($this->pidFile) ? @file_get_contents($this->pidFile) : false;

        if ($raw === false) {
            return;
        }

        $trimmed = trim($raw);

        // 空文件/内容不是数字：读不出归属，按「未占用」处理并接管。
        // 绝不能猜一个 pid 去判定 —— 猜中别人的进程会把活着的实例赶走。
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return;
        }

        $pid = (int) $trimmed;

        if ($pid === posix_getpid()) {
            return;
        }

        // EPERM 也按存活算（见 Process::isProcessAlive）：宁可拒启，
        // 也不能对一个查不到的进程假定它已经死了。
        if (Process::isProcessAlive($pid)) {
            $this->logger->error('Daemon 拒绝启动：pid 文件被存活进程占用', [
                'pid_file' => $this->pidFile,
                'owner' => $pid,
                'self' => posix_getpid(),
            ]);

            throw new ProcessException(
                "Daemon 拒绝启动：pid 文件 {$this->pidFile} 已被存活进程 {$pid} 占用"
                . '（本进程 ' . posix_getpid() . '）。请先停止该实例，或确认它确实是残留文件后删除该 pid 文件。'
            );
        }
    }

    private function writePidFile(): void
    {
        $this->claimPidFile();

        // 落盘失败必须抛：pid 写不下去 = 这一代在状态表里永久查不到，
        // 互斥也随之失效（下一个实例能直接起），而进程看着一切正常。
        $written = @file_put_contents($this->pidFile, (string) posix_getpid());

        if ($written === false) {
            throw new ProcessException("Daemon 无法写入 pid 文件：{$this->pidFile}");
        }
    }

    private function cleanup(): void
    {
        // 只删写着**自己 pid** 的文件。别人占的文件删掉，等于让那一代守护进程
        // 从状态表里消失 —— 和「覆盖」是同一个后果，清理责任只属于写侧自己那一份。
        $raw = is_file($this->pidFile) ? @file_get_contents($this->pidFile) : false;

        if ($raw !== false && trim($raw) === (string) posix_getpid()) {
            @unlink($this->pidFile);
        }
    }

    // ------------------------------------------------------------ 信号处理

    private function installSupervisorSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(Signal::TERM, function (): void {
            $this->stopping = true;
        });
        pcntl_signal(Signal::INT, function (): void {
            $this->stopping = true;
        });
        // USR1 = 平滑重启全部 worker
        pcntl_signal(Signal::USR1, function (): void {
            $this->reloadRequested = true;
        });
        // USR2 = 预留：状态探针（由外部读取共享状态或日志）
        pcntl_signal(Signal::USR2, function (): void {
            $this->logger->info('Daemon 状态探针', [
                'children' => count($this->childPids),
                'restarts' => $this->restartCount,
            ]);
        });
    }

    private function installWorkerSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(Signal::TERM, function (): void {
            $this->workerStopping = true;
        });
        pcntl_signal(Signal::INT, function (): void {
            $this->workerStopping = true;
        });
        pcntl_signal(Signal::USR1, function (): void {
            $this->workerStopping = true;
        });
    }
}
