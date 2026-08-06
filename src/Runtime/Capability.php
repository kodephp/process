<?php

declare(strict_types=1);

namespace Kode\Process\Runtime;

/**
 * 运行时能力枚举。
 *
 * 不同运行时的能力集合并不相同，应用应通过 {@see RuntimeInterface::supports()}
 * 查询后做优雅降级，而不是假定某项能力一定存在。
 */
enum Capability: string
{
    /** 原生协程（Swoole 独有，基于 C 栈协程；Workerman 无原生协程能力） */
    case Coroutine = 'coroutine';

    /** 共享内存表（跨进程数据共享） */
    case SharedTable = 'shared_table';

    /** 独立 Task 工作进程（投递耗时任务，不阻塞 I/O 进程） */
    case TaskWorker = 'task_worker';

    /** UDP 服务端 */
    case UdpServer = 'udp_server';

    /** Unix Domain Socket */
    case UnixSocket = 'unix_socket';

    /** TLS/SSL 加密传输 */
    case Ssl = 'ssl';

    /** 不中断服务的平滑重载（reload） */
    case HotReload = 'hot_reload';

    /** SO_REUSEPORT 内核级负载均衡 */
    case ReusePort = 'reuse_port';

    /** WebSocket 协议 */
    case WebSocket = 'websocket';

    /** 定时器 */
    case Timer = 'timer';

    /** 进程内异步文件/DNS 等异步 I/O */
    case AsyncIo = 'async_io';

    public function label(): string
    {
        return match ($this) {
            self::Coroutine   => '原生协程',
            self::SharedTable => '共享内存表',
            self::TaskWorker  => 'Task 工作进程',
            self::UdpServer   => 'UDP 服务端',
            self::UnixSocket  => 'Unix Socket',
            self::Ssl         => 'SSL/TLS',
            self::HotReload   => '平滑重载',
            self::ReusePort   => 'SO_REUSEPORT',
            self::WebSocket   => 'WebSocket',
            self::Timer       => '定时器',
            self::AsyncIo     => '异步 I/O',
        };
    }
}
