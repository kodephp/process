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
}
