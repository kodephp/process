<?php

declare(strict_types=1);

namespace Kode\Process\Parallel;

use Kode\Process\Async\Async;
use Kode\Process\Exceptions\ParallelException;
use Kode\Process\Version;

/**
 * 并行（多线程）门面
 *
 * 将 kode/fibers 的协作式并发与 ext-parallel 的抢占式多线程结合起来：
 *
 * - 协程（Fiber）负责 I/O 密集型工作，在单线程内高效切换；
 * - 并行（parallel）负责 CPU 密集型工作，在真实 OS 线程上执行；
 * - {@see self::run()} 把任务投到并行运行时，返回 {@see FutureInterface}；
 * - {@see self::await()} 在协程内挂起当前 Fiber，待并行任务完成后自动恢复，
 *   从而让事件循环在等待期间继续服务其他协程。
 *
 * 真正的多线程需要 ZTS（线程安全）构建的 PHP + ext-parallel，二者缺一不可。
 * 非 ZTS 环境下 {@see self::run()} 会抛出清晰的 {@see ParallelException}。
 */
final class Parallel
{
    /**
     * 当前 PHP 是否为 ZTS（线程安全）构建
     */
    public static function isZts(): bool
    {
        return Version::isZts();
    }

    /**
     * 是否支持真正的多线程并行（ZTS + ext-parallel）
     */
    public static function isAvailable(): bool
    {
        return Version::supportsParallel();
    }

    /**
     * 当前可用的并行后端
     *
     * @return 'ext-parallel'|'kode-parallel'|'none'
     */
    public static function backend(): string
    {
        return Version::parallelBackend();
    }

    /**
     * 在并行运行时中执行任务
     *
     * @param callable $task  要在独立线程中执行的可调用体（不能捕获 $this / 引用变量）
     * @param mixed    ...$args 传给任务的参数
     *
     * @throws ParallelException 环境不支持并行时
     */
    public static function run(callable $task, mixed ...$args): FutureInterface
    {
        if (!self::isAvailable()) {
            throw ParallelException::notAvailable();
        }

        $runtime = new \parallel\Runtime();
        $future = $runtime->run($task, $args);

        return new Future($future, $runtime);
    }

    /**
     * 等待并行任务完成并获取结果
     *
     * 桥接两种调度模型：
     *
     * - 当本库 Async 事件循环正在运行（Async::run()）时，注册一个轮询器，
     *   在 future 完成后由事件循环恢复当前 Fiber —— 等待期间循环可服务其它协程。
     * - 否则（kode/fibers 的 FiberPool、或普通上下文）采用协作式忙轮询：
     *   在 Fiber 内反复 suspend 让出控制权（FiberPool 会立即 resume 继续轮询），
     *   在普通上下文则 usleep 阻塞。这与本库的 Promise::await() 行为一致。
     *
     * 真正的多线程任务在独立的 OS 线程上执行，future->done() 由该线程翻转，
     * 因此必须由所在循环持续轮询，而非等待“别人来恢复”。
     *
     * @throws \Throwable 任务执行失败时透传原异常
     */
    public static function await(FutureInterface $future): mixed
    {
        $fiber = \Fiber::getCurrent();

        // Async 事件循环正在驱动：用 defer 轮询器恢复 Fiber（避免忙等，等待期间可服务其它协程）
        if ($fiber !== null && Async::getStatus()['running']) {
            $poller = function () use ($future, $fiber, &$poller): void {
                if (!$future->done()) {
                    Async::defer($poller);
                    return;
                }

                try {
                    $fiber->resume($future->value());
                } catch (\Throwable $e) {
                    $fiber->throw($e);
                }
            };

            Async::defer($poller);

            return $fiber->suspend();
        }

        // 协作式忙轮询：兼容 kode/fibers 的 FiberPool（它会持续 resume 挂起的 Fiber），
        // 以及没有任何事件循环的普通阻塞上下文。
        while (!$future->done()) {
            if ($fiber !== null) {
                \Fiber::suspend();
            } else {
                usleep(1000);
            }
        }

        return $future->value();
    }
}
