<?php

declare(strict_types=1);

namespace Kode\Process\Debug;

final class ProcessDebugger
{
    private static array $traces = [];
    private static bool $enabled = false;
    private static int $maxTraces = 100;
    private static array $slowLog = [];
    private static float $slowThreshold = 1.0;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function setSlowThreshold(float $seconds): void
    {
        self::$slowThreshold = $seconds;
    }

    public static function setMaxTraces(int $max): void
    {
        self::$maxTraces = $max;
    }

    public static function startTrace(string $name): string
    {
        if (!self::$enabled) {
            return '';
        }

        $id = uniqid($name . '_', true);
        self::$traces[$id] = [
            'name' => $name,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'end_time' => null,
            'end_memory' => null,
            'duration' => null,
            'memory_delta' => null,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ];

        return $id;
    }

    public static function endTrace(string $id): ?array
    {
        if (!self::$enabled || !isset(self::$traces[$id])) {
            return null;
        }

        $trace = &self::$traces[$id];
        $trace['end_time'] = microtime(true);
        $trace['end_memory'] = memory_get_usage(true);
        $trace['duration'] = $trace['end_time'] - $trace['start_time'];
        $trace['memory_delta'] = $trace['end_memory'] - $trace['start_memory'];

        if ($trace['duration'] >= self::$slowThreshold) {
            self::$slowLog[] = [
                'id' => $id,
                'name' => $trace['name'],
                'duration' => $trace['duration'],
                'memory_delta' => $trace['memory_delta'],
                'time' => date('Y-m-d H:i:s')
            ];
        }

        if (count(self::$traces) > self::$maxTraces) {
            array_shift(self::$traces);
        }

        return $trace;
    }

    public static function getTrace(string $id): ?array
    {
        return self::$traces[$id] ?? null;
    }

    public static function getAllTraces(): array
    {
        return self::$traces;
    }

    public static function getSlowTraces(): array
    {
        return self::$slowLog;
    }

    public static function clearTraces(): void
    {
        self::$traces = [];
        self::$slowLog = [];
    }

    public static function profile(callable $callback, string $name = 'profile'): mixed
    {
        $id = self::startTrace($name);
        $result = $callback();
        self::endTrace($id);
        
        return $result;
    }

    public static function getBacktrace(int $limit = 10): array
    {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
    }

    public static function formatBacktrace(array $trace): string
    {
        $output = '';
        
        foreach ($trace as $i => $item) {
            $file = $item['file'] ?? 'unknown';
            $line = $item['line'] ?? 0;
            $class = $item['class'] ?? '';
            $type = $item['type'] ?? '';
            $function = $item['function'] ?? 'unknown';

            $output .= sprintf(
                "#%d %s(%d): %s%s%s()\n",
                $i,
                $file,
                $line,
                $class,
                $type,
                $function
            );
        }

        return $output;
    }

    public static function getMemoryUsage(): array
    {
        return [
            'usage' => memory_get_usage(true),
            'usage_real' => memory_get_usage(false),
            'peak' => memory_get_peak_usage(true),
            'peak_real' => memory_get_peak_usage(false),
            'formatted' => [
                'usage' => self::formatBytes(memory_get_usage(true)),
                'peak' => self::formatBytes(memory_get_peak_usage(true))
            ]
        ];
    }

    public static function getResourceUsage(): array
    {
        $ru = getrusage();
        
        return [
            'user_time' => ($ru['ru_utime.tv_sec'] * 1000000 + $ru['ru_utime.tv_usec']) / 1000000,
            'system_time' => ($ru['ru_stime.tv_sec'] * 1000000 + $ru['ru_stime.tv_usec']) / 1000000,
            'maxrss' => $ru['ru_maxrss'] ?? 0,
            'minflt' => $ru['ru_minflt'] ?? 0,
            'majflt' => $ru['ru_majflt'] ?? 0,
            'inblock' => $ru['ru_inblock'] ?? 0,
            'outblock' => $ru['ru_oublock'] ?? 0
        ];
    }

    public static function getOpenFiles(?int $pid = null): array
    {
        $pid = $pid ?? getmypid();
        $output = [];
        
        if (PHP_OS_FAMILY === 'Linux') {
            $dir = "/proc/{$pid}/fd";
            
            if (is_dir($dir)) {
                $files = scandir($dir);
                
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    $link = @readlink("{$dir}/{$file}");
                    
                    if ($link !== false) {
                        $output[] = [
                            'fd' => $file,
                            'target' => $link
                        ];
                    }
                }
            }
        }

        return $output;
    }

    public static function getConnections(?int $pid = null): array
    {
        $pid = $pid ?? getmypid();
        $connections = [];

        if (PHP_OS_FAMILY === 'Linux') {
            $cmd = "netstat -anp 2>/dev/null | grep {$pid}/";
            $output = shell_exec($cmd);
            
            if ($output) {
                $lines = explode("\n", trim($output));
                
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', $line);
                    
                    if (count($parts) >= 6) {
                        $connections[] = [
                            'protocol' => $parts[0],
                            'local' => $parts[3],
                            'remote' => $parts[4],
                            'state' => $parts[5]
                        ];
                    }
                }
            }
        }

        return $connections;
    }

    public static function dump(string $label, mixed $data): void
    {
        $output = sprintf(
            "[%s] %s: %s\n",
            date('Y-m-d H:i:s'),
            $label,
            is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        file_put_contents('php://stderr', $output);
    }

    public static function log(string $message, array $context = []): void
    {
        $log = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $message,
            empty($context) ? '' : json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        file_put_contents('php://stderr', $log);
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . $units[$i];
    }

    public static function generateReport(): array
    {
        return [
            'memory' => self::getMemoryUsage(),
            'resources' => self::getResourceUsage(),
            'traces' => [
                'total' => count(self::$traces),
                'slow' => count(self::$slowLog),
                'slow_details' => self::$slowLog
            ],
            'open_files' => count(self::getOpenFiles()),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
