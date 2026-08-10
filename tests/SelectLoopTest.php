<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Reactor\SelectLoop;
use PHPUnit\Framework\TestCase;

final class SelectLoopTest extends TestCase
{
    public function testReadableCallbackFires(): void
    {
        $loop = new SelectLoop();
        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        // 本平台 fd 编号可能 >= FD_SETSIZE（如 macOS），stream_select 无法监听，
        // 属平台限制，应改用 ext-event；此处跳过而非误报。
        if ((int) $a >= 1024) {
            \fclose($a);
            \fclose($b);
            $this->markTestSkipped('本平台 fd 编号 >= FD_SETSIZE，SelectLoop 需 ext-event');
        }

        $fired = 0;
        $loop->onReadable($a, function ($stream) use (&$fired): void {
            $fired++;
            @\fread($stream, 8192);
        });

        // 定时向对端写入，制造可读事件
        $loop->addTimer(0.01, static function () use ($b): void {
            @\fwrite($b, 'ping');
        }, true);
        $loop->addTimer(0.08, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();

        $this->assertGreaterThan(0, $fired);
    }

    /**
     * 注册后关闭流却不 offReadable，会令 stream_select 因 Bad file descriptor 失败。
     * 旧实现会每轮空转 100% CPU；新实现应剔除失效流并正常退出。
     */
    public function testPrunesClosedStreamInsteadOfSpinning(): void
    {
        $loop = new SelectLoop();
        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $loop->onReadable($a, static fn() => null);
        \fclose($a); // 不 offReadable，制造失效流

        $loop->addTimer(0.05, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();

        $this->assertFalse($loop->isRunning());
        $this->assertSame(0, $loop->stats()['read'], '失效流应被剔除');
    }

    /**
     * 定时器回调抛异常时，SelectLoop 必须隔离该异常并继续运行（不穿透 run() 打死循环）。
     * 这是长驻事件循环健壮性的硬性要求：单条任务的底层异常不应中断整个服务。
     */
    public function testThrowingTimerCallbackDoesNotCrashLoop(): void
    {
        $loop = new SelectLoop();
        $ok = 0;

        // 会抛异常的定时器：每轮都应被隔离，循环继续
        $loop->addTimer(0.01, static function (): void {
            throw new \RuntimeException('boom');
        }, true);

        // 正常定时器：证明循环在异常被隔离后仍在正常推进其他任务
        $loop->addTimer(0.02, static function () use (&$ok): void {
            $ok++;
        }, true);

        $loop->addTimer(0.1, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();

        $this->assertFalse($loop->isRunning(), '抛出异常后循环应仍正常退出');
        $this->assertGreaterThan(0, $ok, '异常隔离后循环应继续处理其他定时器');
    }

    /**
     * 惰性 prune 行为守卫：流被外部 fclose 却未调用 off* 时，stream_select 会对失效资源
     * 抛 ValueError。新实现应在 select() 内部 catch 并剔除失效流，而非把异常穿透出来、
     * 也不是每轮空转 100% CPU。本测试用反射直接驱动私有 select()，断言「不抛 + 被剔除」。
     */
    public function testSelectLazilyPrunesExternallyClosedStream(): void
    {
        $loop = new SelectLoop();
        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ((int) $a >= 1024) {
            \fclose($a);
            \fclose($b);
            $this->markTestSkipped('本平台 fd 编号 >= FD_SETSIZE，SelectLoop 需 ext-event');
        }

        $loop->onReadable($a, static fn() => null);
        \fclose($a); // 外部关闭，未 offReadable

        $select = new \ReflectionMethod($loop, 'select');
        $select->setAccessible(true);

        // 不应抛异常（内部 catch ValueError + prune 一次）
        $select->invoke($loop, 0.0);
        $this->assertSame(0, $loop->stats()['read'], '失效流应被惰性剔除');

        // 再 tick 一次：此时集合已空，应走快速返回路径，不抛、不残留
        $select->invoke($loop, 0.0);
        $this->assertSame(0, $loop->stats()['read']);

        \fclose($b);
    }

    /**
     * 惰性 prune 的反向守卫：流均有效时，select() 不得误伤任何有效流（零扫描路径必须
     * 保持流集合不变）。这是与「每 tick 全量 prune」最易被混淆的回归点——新实现在稳态下
     * 完全不扫描，因此不会因扫描逻辑改动而误删有效连接。
     */
    public function testSelectKeepsValidStreamsAcrossManyTicks(): void
    {
        $loop = new SelectLoop();
        $pairs = [];
        for ($i = 0; $i < 4; $i++) {
            [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ((int) $a >= 1024) {
                \fclose($a);
                \fclose($b);
                $this->markTestSkipped('本平台 fd 编号 >= FD_SETSIZE，SelectLoop 需 ext-event');
            }
            $pairs[] = [$a, $b];
            $loop->onReadable($a, static fn() => null);
        }

        $select = new \ReflectionMethod($loop, 'select');
        $select->setAccessible(true);

        for ($i = 0; $i < 200; $i++) {
            $select->invoke($loop, 0.0);
        }

        $this->assertSame(4, $loop->stats()['read'], '有效流在多次 tick 后必须完整保留');

        foreach ($pairs as [$a, $b]) {
            \fclose($a);
            \fclose($b);
        }
    }

    /**
     * FD_SETSIZE 告警契约守卫：warnFdSetSize() 通过 fdSetSizeWarned 标志保证
     * 全生命周期只记录一次，无论注册期 guardFdLimit 还是运行期 select 失败先触发。
     * 该契约是「健壮暴露 fd >= 1024 状态而不刷屏」的核心，用 error_log 重定向到临时文件断言。
     */
    public function testFdSetSizeWarningFiresExactlyOnce(): void
    {
        $loop = new SelectLoop();
        $warn = new \ReflectionMethod($loop, 'warnFdSetSize');
        $warn->setAccessible(true);

        $logFile = \sys_get_temp_dir() . '/selectloop_fdtest_' . \uniqid() . '.log';
        $prev = \ini_set('error_log', $logFile);

        try {
            $warn->invoke($loop);
            $warn->invoke($loop); // 第二次必须被标志抑制
            $warn->invoke($loop); // 第三次同样
        } finally {
            if ($prev !== false) {
                \ini_set('error_log', $prev);
            }
        }

        $content = \is_file($logFile) ? \file_get_contents($logFile) : '';
        @\unlink($logFile);

        $lines = \array_values(\array_filter(
            \explode("\n", \rtrim($content)),
            static fn(string $l): bool => $l !== ''
        ));

        $this->assertCount(1, $lines, 'FD_SETSIZE 告警必须全生命周期只记录一次');
        $this->assertStringContainsString('FD_SETSIZE', $content);
    }
}
