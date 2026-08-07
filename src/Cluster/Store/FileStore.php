<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Store;

use Kode\Process\Exceptions\ClusterException;

/**
 * 基于共享文件系统的协调存储——零依赖兜底后端。
 *
 * 每个键落成一个文件，所有「读-比较-写」复合操作在 `flock(LOCK_EX)` 排他锁内完成，
 * 因此同一台机器上的多进程（以及 NFS/CIFS 等支持 flock 的共享盘上的多台机器）
 * 之间是原子的。
 *
 * ```php
 * $store = new FileStore(['path' => '/dev/shm/kode-cluster']);   // 单机多实例：用 tmpfs 最快
 * $store = new FileStore(['path' => '/mnt/nfs/kode-cluster']);   // 小规模多机
 * ```
 *
 * 适用：单机多实例、开发测试、没有 Redis 的小规模部署。
 * 不适用：高并发或网络文件系统 flock 语义不可靠的环境——那些场景请用
 * {@see RedisStore} 或 {@see GlobalDataStore}。
 *
 * @since 5.0.0
 */
final class FileStore implements StoreInterface
{
    use ValueCodec;

    private readonly string $path;

    /**
     * @param array{path?: string, prefix?: string, mode?: int} $options
     */
    public function __construct(array $options = [])
    {
        $base   = (string) ($options['path'] ?? sys_get_temp_dir() . '/kode-cluster');
        $prefix = trim((string) ($options['prefix'] ?? ''), '/');

        $this->path = $prefix === '' ? $base : $base . '/' . $prefix;

        if (!is_dir($this->path) && !@mkdir($this->path, (int) ($options['mode'] ?? 0o777), true) && !is_dir($this->path)) {
            throw ClusterException::backendUnavailable('file', sprintf('无法创建目录 %s', $this->path));
        }
        if (!is_writable($this->path)) {
            throw ClusterException::backendUnavailable('file', sprintf('目录 %s 不可写', $this->path));
        }
    }

    public static function isAvailable(): bool
    {
        return function_exists('flock');
    }

    public function name(): string
    {
        return 'file';
    }

    /** 存储根目录，便于运维排查。 */
    public function path(): string
    {
        return $this->path;
    }

    private function fileOf(string $key): string
    {
        // 用摘要做文件名，规避键中的 / 与长度上限；原始键存进文件内容供 keys() 还原
        return $this->path . '/' . sha1($key) . '.kv';
    }

    /**
     * 在排他锁内执行操作，是本后端所有原子性的来源。
     *
     * 回调收到当前记录（不存在或已过期时为 null），返回值决定后续动作：
     *   - `['write' => mixed]` 写入新值（配合 `ttlMs`）
     *   - `['delete' => true]` 删除
     *   - `['keep' => true]`   不改动
     * 第二个元素为本方法的返回值。
     *
     * @param callable(array{k: string, v: mixed, e: float}|null): array{0: array<string, mixed>, 1: mixed} $fn
     */
    private function locked(string $key, callable $fn): mixed
    {
        $file   = $this->fileOf($key);
        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            throw new ClusterException(sprintf('无法打开存储文件 %s', $file));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new ClusterException(sprintf('无法锁定存储文件 %s', $file));
            }

            $record = $this->readLocked($handle);
            [$action, $return] = $fn($record);

            if (isset($action['write'])) {
                $ttlMs   = (int) ($action['ttlMs'] ?? 0);
                $payload = serialize([
                    'k' => $key,
                    'v' => $this->encodeValue($action['write']),
                    'e' => $ttlMs > 0 ? microtime(true) + $ttlMs / 1000 : 0.0,
                ]);

                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $payload);
                fflush($handle);
            } elseif (isset($action['delete'])) {
                ftruncate($handle, 0);
                fflush($handle);
                @unlink($file);
            } elseif ($record === null) {
                // 读操作命中不存在的键时 fopen('c+') 会留下空文件，顺手清掉避免目录膨胀
                $stat = fstat($handle);
                if (($stat['size'] ?? 0) === 0) {
                    @unlink($file);
                }
            }

            return $return;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 读取已加锁句柄中的记录，过期视同不存在。
     *
     * @param resource $handle
     * @return array{k: string, v: mixed, e: float}|null
     */
    private function readLocked($handle): ?array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if ($raw === false || $raw === '') {
            return null;
        }

        $record = @unserialize($raw);
        if (!is_array($record) || !array_key_exists('v', $record)) {
            return null;
        }

        $expire = (float) ($record['e'] ?? 0);
        if ($expire > 0 && $expire < microtime(true)) {
            return null;
        }

        return ['k' => (string) ($record['k'] ?? ''), 'v' => $record['v'], 'e' => $expire];
    }

    public function get(string $key): mixed
    {
        return $this->locked($key, fn (?array $r): array => [
            ['keep' => true],
            $r === null ? null : $this->decodeValue($r['v']),
        ]);
    }

    public function mget(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function set(string $key, mixed $value, int $ttlMs = 0): bool
    {
        return $this->locked($key, static fn (?array $r): array => [
            ['write' => $value, 'ttlMs' => $ttlMs],
            true,
        ]);
    }

    public function setIfAbsent(string $key, mixed $value, int $ttlMs = 0): bool
    {
        return $this->locked($key, static fn (?array $r): array => $r === null
            ? [['write' => $value, 'ttlMs' => $ttlMs], true]
            : [['keep' => true], false]);
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttlMs = 0): bool
    {
        return $this->locked($key, function (?array $r) use ($expected, $value, $ttlMs): array {
            if ($r === null || $this->decodeValue($r['v']) !== $expected) {
                return [['keep' => true], false];
            }

            // ttlMs 为 0 时保留原有过期时刻，避免续期语义外的意外「转永久」
            $keepTtl = $ttlMs <= 0 && $r['e'] > 0
                ? (int) max(1, ($r['e'] - microtime(true)) * 1000)
                : $ttlMs;

            return [['write' => $value, 'ttlMs' => $keepTtl], true];
        });
    }

    public function compareAndDelete(string $key, mixed $expected): bool
    {
        return $this->locked($key, function (?array $r) use ($expected): array {
            if ($r === null || $this->decodeValue($r['v']) !== $expected) {
                return [['keep' => true], false];
            }

            return [['delete' => true], true];
        });
    }

    public function delete(string $key): bool
    {
        return $this->locked($key, static fn (?array $r): array => [['delete' => true], true]);
    }

    public function exists(string $key): bool
    {
        return $this->locked($key, static fn (?array $r): array => [['keep' => true], $r !== null]);
    }

    public function increment(string $key, int $step = 1, int $ttlMs = 0): int
    {
        return $this->locked($key, function (?array $r) use ($step, $ttlMs): array {
            if ($r === null) {
                return [['write' => $step, 'ttlMs' => $ttlMs], $step];
            }

            $current = $this->decodeValue($r['v']);
            $next    = (is_int($current) ? $current : (int) $current) + $step;
            $keepTtl = $r['e'] > 0 ? (int) max(1, ($r['e'] - microtime(true)) * 1000) : 0;

            return [['write' => $next, 'ttlMs' => $keepTtl], $next];
        });
    }

    public function expire(string $key, int $ttlMs): bool
    {
        return $this->locked($key, function (?array $r) use ($ttlMs): array {
            if ($r === null) {
                return [['keep' => true], false];
            }

            // 原值回写，只换 TTL
            return [['write' => $this->decodeValue($r['v']), 'ttlMs' => $ttlMs], true];
        });
    }

    public function keys(string $prefix = ''): array
    {
        $found = [];

        foreach (glob($this->path . '/*.kv') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }

            $record = @unserialize($raw);
            if (!is_array($record) || !isset($record['k'])) {
                continue;
            }

            $expire = (float) ($record['e'] ?? 0);
            if ($expire > 0 && $expire < microtime(true)) {
                continue;
            }

            $key = (string) $record['k'];
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    public function flush(): int
    {
        $count = 0;
        foreach (glob($this->path . '/*.kv') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    public function close(): void
    {
        // 文件后端无长连接，无需释放
    }
}
