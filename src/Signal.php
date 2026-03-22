<?php

declare(strict_types=1);

namespace Kode\Process;

/**
 * 进程信号常量
 * 
 * 跨平台信号定义，自动适配不同操作系统
 */
final class Signal
{
    public const HUP = SIGHUP;
    public const INT = SIGINT;
    public const QUIT = SIGQUIT;
    public const ILL = SIGILL;
    public const TRAP = SIGTRAP;
    public const ABRT = SIGABRT;
    public const BUS = SIGBUS;
    public const FPE = SIGFPE;
    public const KILL = SIGKILL;
    public const USR1 = SIGUSR1;
    public const SEGV = SIGSEGV;
    public const USR2 = SIGUSR2;
    public const PIPE = SIGPIPE;
    public const ALRM = SIGALRM;
    public const TERM = SIGTERM;
    public const CHLD = SIGCHLD;
    public const CONT = SIGCONT;
    public const STOP = SIGSTOP;
    public const TSTP = SIGTSTP;
    public const TTIN = SIGTTIN;
    public const TTOU = SIGTTOU;
    public const URG = SIGURG;
    public const XCPU = SIGXCPU;
    public const XFSZ = SIGXFSZ;
    public const VTALRM = SIGVTALRM;
    public const PROF = SIGPROF;
    public const WINCH = SIGWINCH;
    public const IO = SIGIO;
    public const SYS = SIGSYS;

    private static ?array $names = null;

    private static ?array $descriptions = null;

    private static function initMaps(): void
    {
        if (self::$names !== null) {
            return;
        }

        self::$names = [
            self::HUP => 'SIGHUP',
            self::INT => 'SIGINT',
            self::QUIT => 'SIGQUIT',
            self::ILL => 'SIGILL',
            self::TRAP => 'SIGTRAP',
            self::ABRT => 'SIGABRT',
            self::BUS => 'SIGBUS',
            self::FPE => 'SIGFPE',
            self::KILL => 'SIGKILL',
            self::USR1 => 'SIGUSR1',
            self::SEGV => 'SIGSEGV',
            self::USR2 => 'SIGUSR2',
            self::PIPE => 'SIGPIPE',
            self::ALRM => 'SIGALRM',
            self::TERM => 'SIGTERM',
            self::CHLD => 'SIGCHLD',
            self::CONT => 'SIGCONT',
            self::STOP => 'SIGSTOP',
            self::TSTP => 'SIGTSTP',
            self::TTIN => 'SIGTTIN',
            self::TTOU => 'SIGTTOU',
            self::URG => 'SIGURG',
            self::XCPU => 'SIGXCPU',
            self::XFSZ => 'SIGXFSZ',
            self::VTALRM => 'SIGVTALRM',
            self::PROF => 'SIGPROF',
            self::WINCH => 'SIGWINCH',
            self::IO => 'SIGIO',
            self::SYS => 'SIGSYS',
        ];

        if (defined('SIGIOT')) {
            self::$names[SIGIOT] = 'SIGIOT';
        }

        if (defined('SIGSTKFLT')) {
            self::$names[SIGSTKFLT] = 'SIGSTKFLT';
        }

        if (defined('SIGCLD')) {
            self::$names[SIGCLD] = 'SIGCLD';
        }

        if (defined('SIGPOLL')) {
            self::$names[SIGPOLL] = 'SIGPOLL';
        }

        if (defined('SIGPWR')) {
            self::$names[SIGPWR] = 'SIGPWR';
        }

        if (defined('SIGBABY')) {
            self::$names[SIGBABY] = 'SIGBABY';
        }

        self::$descriptions = [
            self::HUP => '终端挂起或控制进程终止',
            self::INT => '键盘中断 (Ctrl+C)',
            self::QUIT => '键盘退出 (Ctrl+\\)',
            self::ILL => '非法指令',
            self::TRAP => '跟踪陷阱',
            self::ABRT => '异常终止',
            self::BUS => '总线错误',
            self::FPE => '浮点异常',
            self::KILL => '强制终止 (不可捕获)',
            self::USR1 => '用户自定义信号 1',
            self::SEGV => '段错误',
            self::USR2 => '用户自定义信号 2',
            self::PIPE => '管道破裂',
            self::ALRM => '闹钟信号',
            self::TERM => '终止信号',
            self::CHLD => '子进程状态改变',
            self::CONT => '继续执行 (如果停止)',
            self::STOP => '停止执行 (不可捕获)',
            self::TSTP => '终端停止 (Ctrl+Z)',
            self::TTIN => '后台进程读终端',
            self::TTOU => '后台进程写终端',
            self::URG => '紧急套接字条件',
            self::XCPU => 'CPU 时间限制超出',
            self::XFSZ => '文件大小限制超出',
            self::VTALRM => '虚拟闹钟',
            self::PROF => '性能分析闹钟',
            self::WINCH => '窗口大小改变',
            self::IO => 'I/O 就绪',
            self::SYS => '错误系统调用',
        ];

        if (defined('SIGIOT')) {
            self::$descriptions[SIGIOT] = 'IOT 陷阱';
        }

        if (defined('SIGSTKFLT')) {
            self::$descriptions[SIGSTKFLT] = '协处理器栈错误';
        }

        if (defined('SIGCLD')) {
            self::$descriptions[SIGCLD] = '子进程终止';
        }

        if (defined('SIGPOLL')) {
            self::$descriptions[SIGPOLL] = '可轮询事件';
        }

        if (defined('SIGPWR')) {
            self::$descriptions[SIGPWR] = '电源故障';
        }

        if (defined('SIGBABY')) {
            self::$descriptions[SIGBABY] = '子进程退出';
        }
    }

    public static function getName(int $signal): string
    {
        self::initMaps();

        return self::$names[$signal] ?? "UNKNOWN($signal)";
    }

    public static function getDescription(int $signal): string
    {
        self::initMaps();

        return self::$descriptions[$signal] ?? '未知信号';
    }

    public static function getAll(): array
    {
        self::initMaps();

        return self::$names;
    }

    public static function isCatchable(int $signal): bool
    {
        return !in_array($signal, [self::KILL, self::STOP], true);
    }
}
