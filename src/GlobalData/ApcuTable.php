<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\Exceptions\GlobalDataException;

/**
 * APCu 后端（机会性启用，不强制安装）
 *
 * 当运行环境**已经**装了 ext-apcu 时可用；CLI 下还需 `apc.enable_cli=1`。
 * 没装时 {@see self::isSupported()} 返回 false，{@see GlobalData::auto()}
 * 会自动退回零安装的 {@see SharedMemoryTable}。
 *
 * 与 Swoole Table 的差别：APCu 是进程外的共享缓存，**无需在 fork 前创建**，
 * 任意时刻新建的实例都能看到同一份数据（同一 SAPI 进程池内），因此更适合
 * PHP-FPM / 运行期动态拉起的 worker。
 *
 * 原子性说明：
 *  - 整数自增走 `apcu_inc`、整数 CAS 走 `apcu_cas`，均由 APCu 保证原子；
 *  - 浮点自增与非整数 CAS 由本类用 `apcu_add` 自旋锁串行化。
 */
final class ApcuTable implements TableInterface
{
    private const string LOCK_SUFFIX = "\0lock";

    private string $prefix;
    private int $lockTtl;
    private bool $closed = false;

    /**
     * @param string $namespace 键名前缀，用于在同一份 APCu 缓存中隔离多张表
     * @param int    $lockTtl   自旋锁的兜底过期秒数，防止持锁进程崩溃后死锁
     */
    public function __construct(string $namespace = 'kode', int $lockTtl = 5)
    {
        if (!self::isSupported()) {
            throw GlobalDataException::unsupported('apcu');
        }

        $this->prefix = $namespace . ':';
        $this->lockTtl = max(1, $lockTtl);
    }

    public static function isSupported(): bool
    {
        return extension_loaded('apcu')
            && function_exists('apcu_store')
            && (bool) ini_get('apc.enabled')
            && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli'));
    }

    public function backend(): string
    {
        return 'apcu';
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->assertOpen();
        apcu_store($this->prefix . $key, $value, max(0, $ttl));
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->assertOpen();

        return (bool) apcu_add($this->prefix . $key, $value, max(0, $ttl));
    }

    public function replace(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->assertOpen();
        if (!apcu_exists($this->prefix . $key)) {
            return false;
        }
        apcu_store($this->prefix . $key, $value, max(0, $ttl));

        return true;
    }

    public function setMultiple(array $items, int $ttl = 0): void
    {
        $this->assertOpen();
        $payload = [];
        foreach ($items as $k => $v) {
            $payload[$this->prefix . $k] = $v;
        }
        apcu_store($payload, null, max(0, $ttl));
    }

    public function get(string $key): mixed
    {
        $this->assertOpen();
        $ok = false;
        $value = apcu_fetch($this->prefix . $key, $ok);

        return $ok ? $value : null;
    }

    public function getMultiple(array $keys): array
    {
        $this->assertOpen();
        $prefixed = array_map(fn (string $k): string => $this->prefix . $k, $keys);
        $found = apcu_fetch($prefixed) ?: [];

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $found[$this->prefix . $k] ?? null;
        }

        return $out;
    }

    public function exists(string $key): bool
    {
        $this->assertOpen();

        return (bool) apcu_exists($this->prefix . $key);
    }

    public function delete(string $key): bool
    {
        $this->assertOpen();

        return (bool) apcu_delete($this->prefix . $key);
    }

    public function increment(string $key, int|float $step = 1): int|float
    {
        $this->assertOpen();
        $full = $this->prefix . $key;

        if (is_int($step)) {
            $ok = false;
            $next = apcu_inc($full, $step, $ok);
            if ($ok) {
                return $next;
            }
            // 现存值不是整数，落到加锁路径
        }

        return $this->locked($key, function () use ($full, $step): int|float {
            $ok = false;
            $current = apcu_fetch($full, $ok);
            if (!$ok || !is_numeric($current)) {
                $current = 0;
            }
            $next = $current + $step;
            apcu_store($full, $next);

            return $next;
        });
    }

    public function decrement(string $key, int|float $step = 1): int|float
    {
        return $this->increment($key, -$step);
    }

    public function cas(string $key, mixed $oldValue, mixed $newValue): bool
    {
        $this->assertOpen();
        $full = $this->prefix . $key;

        if (is_int($oldValue) && is_int($newValue)) {
            return (bool) apcu_cas($full, $oldValue, $newValue);
        }

        return $this->locked($key, function () use ($full, $oldValue, $newValue): bool {
            $ok = false;
            $current = apcu_fetch($full, $ok);
            if (!$ok || $current !== $oldValue) {
                return false;
            }
            apcu_store($full, $newValue);

            return true;
        });
    }

    public function keys(): array
    {
        $this->assertOpen();
        $keys = [];
        foreach ($this->scan() as $full) {
            $keys[] = substr($full, strlen($this->prefix));
        }

        return $keys;
    }

    public function count(): int
    {
        $this->assertOpen();

        return count($this->scan());
    }

    public function clear(): void
    {
        $this->assertOpen();
        foreach ($this->scan() as $full) {
            apcu_delete($full);
        }
    }

    public function destroy(): void
    {
        if ($this->closed) {
            return;
        }
        $this->clear();
        $this->closed = true;
    }

    public function stats(): array
    {
        $info = function_exists('apcu_sma_info') ? apcu_sma_info(true) : [];

        return [
            'backend' => 'apcu',
            'namespace' => rtrim($this->prefix, ':'),
            'keys' => $this->closed ? 0 : $this->count(),
            'avail_mem' => $info['avail_mem'] ?? null,
            'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
        ];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    // ------------------------------------------------------------------

    /**
     * 以 `apcu_add` 自旋锁串行执行回调（APCu 无原生互斥）。
     *
     * @template T
     * @param  callable(): T $fn
     * @return T
     */
    private function locked(string $key, callable $fn): mixed
    {
        $lockKey = $this->prefix . $key . self::LOCK_SUFFIX;
        $spins = 0;

        while (!apcu_add($lockKey, 1, $this->lockTtl)) {
            if (++$spins > 1000) {
                // 持锁者可能已崩溃，等锁自然过期
                usleep(200);
                $spins = 0;
            } else {
                usleep(1);
            }
        }

        try {
            return $fn();
        } finally {
            apcu_delete($lockKey);
        }
    }

    /**
     * 列出本命名空间下的所有键（不含内部锁键）。
     *
     * @return string[]
     */
    private function scan(): array
    {
        $info = apcu_cache_info(false);
        $list = $info['cache_list'] ?? [];

        $out = [];
        foreach ($list as $entry) {
            $full = (string) ($entry['info'] ?? '');
            if ($full === '' || !str_starts_with($full, $this->prefix)) {
                continue;
            }
            if (str_ends_with($full, self::LOCK_SUFFIX)) {
                continue;
            }
            $out[] = $full;
        }

        return $out;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw GlobalDataException::closed();
        }
    }
}
