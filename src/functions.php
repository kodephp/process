<?php

declare(strict_types=1);

namespace Kode\Process;

/**
 * 进程辅助函数
 */

/**
 * 获取 CPU 核心数
 */
function cpu_count(): int
{
    if (function_exists('swoole_cpu_num')) {
        return \swoole_cpu_num();
    }

    if (is_readable('/proc/cpuinfo')) {
        $content = file_get_contents('/proc/cpuinfo');

        if ($content !== false) {
            return substr_count($content, 'processor');
        }
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $result = shell_exec('echo %NUMBER_OF_PROCESSORS%');

        return $result !== null ? (int) trim($result) : 1;
    }

    $result = shell_exec('nproc');

    return $result !== null ? (int) trim($result) : 1;
}

/**
 * 获取当前进程 ID
 */
function get_pid(): int
{
    return posix_getpid();
}

/**
 * 获取父进程 ID
 */
function get_parent_pid(): int
{
    return posix_getppid();
}

/**
 * 检查进程是否存活
 */
function is_process_alive(int $pid): bool
{
    return posix_kill($pid, 0) && posix_get_last_error() !== 3;
}

/**
 * 创建子进程
 * 
 * @param callable $childCallback 子进程回调
 * @param callable|null $parentCallback 父进程回调
 * @return int 子进程 PID
 */
function fork(callable $childCallback, ?callable $parentCallback = null): int
{
    $pid = pcntl_fork();

    if ($pid < 0) {
        throw new Exceptions\ProcessException('Fork 失败');
    }

    if ($pid === 0) {
        $childCallback();
        exit(0);
    }

    if ($parentCallback !== null) {
        $parentCallback($pid);
    }

    return $pid;
}

/**
 * 等待子进程
 */
function wait(?int $pid = null, bool $noHang = false): array
{
    $flags = $noHang ? WNOHANG : 0;
    $targetPid = $pid ?? -1;

    $result = pcntl_waitpid($targetPid, $status, $flags);

    return [
        'pid' => $result,
        'exit_code' => $result > 0 ? pcntl_wexitstatus($status) : 0,
        'signaled' => $result > 0 ? pcntl_wifsignaled($status) : false,
        'signal' => $result > 0 ? pcntl_wtermsig($status) : 0,
    ];
}

/**
 * 发送信号
 */
function kill(int $pid, int $signal = Signal::TERM): bool
{
    return posix_kill($pid, $signal);
}

/**
 * 守护进程化
 */
function daemonize(): bool
{
    $pid = pcntl_fork();

    if ($pid < 0) {
        return false;
    }

    if ($pid > 0) {
        exit(0);
    }

    posix_setsid();

    $pid = pcntl_fork();

    if ($pid < 0) {
        return false;
    }

    if ($pid > 0) {
        exit(0);
    }

    umask(0);
    chdir('/');

    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);

    return true;
}

/**
 * 设置进程标题
 */
function set_process_title(string $title): bool
{
    if (function_exists('cli_set_process_title')) {
        return cli_set_process_title($title);
    }

    return false;
}

/**
 * 获取进程内存使用
 */
function get_memory_usage(bool $real = true): int
{
    return memory_get_usage($real);
}

/**
 * 获取进程峰值内存使用
 */
function get_peak_memory_usage(bool $real = true): int
{
    return memory_get_peak_usage($real);
}

/**
 * 获取系统负载
 */
function get_load_average(): array
{
    if (function_exists('sys_getloadavg')) {
        return sys_getloadavg();
    }

    return [0, 0, 0];
}

/**
 * 获取进程信息
 */
function get_process_info(?int $pid = null): array
{
    $pid = $pid ?? posix_getpid();

    return [
        'pid' => $pid,
        'ppid' => posix_getppid(),
        'uid' => posix_getuid(),
        'gid' => posix_getgid(),
        'euid' => posix_geteuid(),
        'egid' => posix_getegid(),
        'sid' => posix_getsid($pid),
        'pgid' => posix_getpgid($pid),
        'memory' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
    ];
}

/**
 * 切换用户
 */
function set_user(string $user): bool
{
    $userInfo = posix_getpwnam($user);

    if ($userInfo === false) {
        return false;
    }

    return posix_setuid($userInfo['uid']) && posix_setgid($userInfo['gid']);
}

/**
 * 切换组
 */
function set_group(string $group): bool
{
    $groupInfo = posix_getgrnam($group);

    if ($groupInfo === false) {
        return false;
    }

    return posix_setgid($groupInfo['gid']);
}
