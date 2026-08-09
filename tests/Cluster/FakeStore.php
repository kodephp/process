<?php

declare(strict_types=1);

namespace Kode\Process\Tests\Cluster;

use Kode\Process\Cluster\Store\StoreInterface;

/**
 * 纯内存的协调存储替身。
 *
 * 用来把「后端故障」「TTL 到期」这类难以在真实存储上稳定复现的边界
 * 做成确定性的单元测试。
 *
 * @internal 仅供测试
 */
final class FakeStore implements StoreInterface
{
    /** increment() 是否模拟后端故障（返回 false）。 */
    public bool $failIncrement = false;

    /** @var array<string, array{v: mixed, e: float}> 键 => 值 + 绝对过期时刻（0 表示永久） */
    private array $data = [];

    public static function isAvailable(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake';
    }

    private function expiry(int $ttlMs): float
    {
        return $ttlMs > 0 ? microtime(true) + $ttlMs / 1000 : 0.0;
    }

    /** 惰性过期：读的时候才判断，行为与真实后端一致。 */
    private function alive(string $key): bool
    {
        $row = $this->data[$key] ?? null;

        if ($row === null) {
            return false;
        }

        if ($row['e'] > 0.0 && $row['e'] <= microtime(true)) {
            unset($this->data[$key]);

            return false;
        }

        return true;
    }

    public function get(string $key): mixed
    {
        return $this->alive($key) ? $this->data[$key]['v'] : null;
    }

    public function mget(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if ($this->alive($key)) {
                $result[$key] = $this->data[$key]['v'];
            }
        }

        return $result;
    }

    public function set(string $key, mixed $value, int $ttlMs = 0): bool
    {
        $this->data[$key] = ['v' => $value, 'e' => $this->expiry($ttlMs)];

        return true;
    }

    public function setIfAbsent(string $key, mixed $value, int $ttlMs = 0): bool
    {
        if ($this->alive($key)) {
            return false;
        }

        return $this->set($key, $value, $ttlMs);
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttlMs = 0): bool
    {
        if (!$this->alive($key) || $this->data[$key]['v'] !== $expected) {
            return false;
        }

        return $this->set($key, $value, $ttlMs);
    }

    public function compareAndDelete(string $key, mixed $expected): bool
    {
        if (!$this->alive($key) || $this->data[$key]['v'] !== $expected) {
            return false;
        }

        unset($this->data[$key]);

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function exists(string $key): bool
    {
        return $this->alive($key);
    }

    public function increment(string $key, int $step = 1, int $ttlMs = 0): int|false
    {
        if ($this->failIncrement) {
            return false;
        }

        $current = $this->alive($key) ? (int) $this->data[$key]['v'] : 0;
        $next    = $current + $step;

        $this->data[$key] = [
            'v' => $next,
            'e' => $current === 0 ? $this->expiry($ttlMs) : $this->data[$key]['e'],
        ];

        return $next;
    }

    public function expire(string $key, int $ttlMs): bool
    {
        if (!$this->alive($key)) {
            return false;
        }

        $this->data[$key]['e'] = $this->expiry($ttlMs);

        return true;
    }

    public function keys(string $prefix = ''): array
    {
        $found = [];
        foreach (array_keys($this->data) as $key) {
            if (str_starts_with($key, $prefix) && $this->alive($key)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    public function flush(): int
    {
        $count      = count($this->data);
        $this->data = [];

        return $count;
    }

    public function close(): void
    {
    }
}
