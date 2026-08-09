<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\Exceptions\GlobalDataException;

/**
 * Workerman\Table 后端（机会性启用）
 *
 * 旧版 Workerman（v3）自带 {@see \Workerman\Table}，它**就是 Swoole\Table 的子类**，
 * 因此同样依赖 ext-swoole，且必须在 fork 之前创建。新版 Workerman（v4/v5）已移除该类，
 * 跨进程共享改走网络 GlobalData / Redis 等外部存储。
 *
 * 本适配器在 `class_exists('Workerman\Table')` 为真时被 {@see \Kode\Process\SharedTable::auto()}
 * 选中，调用语义与 {@see SwooleTable} / {@see SharedMemoryTable} 完全一致。
 * 由于 Workerman\Table ≡ Swoole\Table，其基准表现与 Swoole\Table 基本持平
 * （见 benchmarks/real-benchmark.php 的 Swoole 分支即可代表）。
 *
 * 存储布局与 {@see SwooleTable} 相同（v / n / e / f 四列）。
 */
final class WorkermanTable implements TableInterface
{
    private const int FLAG_SERIALIZED = 0;
    private const int FLAG_INT = 1;
    private const int FLAG_FLOAT = 2;

    private object $table;
    private ?object $lock = null;
    private int $rows;
    private int $valueSize;
    private bool $closed = false;

    /** @var bool|list<class-string> 反序列化时放行的类 */
    private bool|array $allowedClasses;

    /**
     * @param int $rows      最大行数（Workerman 会向上取整到 2 的幂）
     * @param int $valueSize 单值序列化后的最大字节数
     * @param bool|list<class-string> $allowedClasses 反序列化白名单，语义同 {@see SwooleTable}。
     *        默认 false，禁止从共享内存还原任意对象，避免 __wakeup/__destruct 对象注入。
     */
    public function __construct(int $rows = 65536, int $valueSize = 8192, bool|array $allowedClasses = false)
    {
        if (!self::isSupported()) {
            throw GlobalDataException::unsupported('workerman');
        }

        $this->rows = $rows;
        $this->valueSize = $valueSize;
        $this->allowedClasses = $allowedClasses;

        /** @var class-string $tableClass */
        $tableClass = '\Workerman\Table';
        $table = new $tableClass($rows);
        $table->column('v', $tableClass::TYPE_STRING, $valueSize);
        $table->column('n', $tableClass::TYPE_FLOAT);
        $table->column('e', $tableClass::TYPE_INT, 8);
        $table->column('f', $tableClass::TYPE_INT, 1);

        if ($table->create() === false) {
            throw new GlobalDataException('Workerman\Table 创建失败（内存不足或列定义非法）', context: [
                'rows' => $rows,
                'value_size' => $valueSize,
            ]);
        }
        $this->table = $table;

        // Workerman\Table 依赖 ext-swoole，因此 Swoole\Lock 必然可用；
        // 没有它的话 add/replace/cas/increment 只是「检查后再写」，跨进程并不原子。
        if (class_exists('\Swoole\Lock')) {
            /** @var class-string $lockClass */
            $lockClass = '\Swoole\Lock';
            $this->lock = new $lockClass($lockClass::MUTEX);
        }
    }

    public static function isSupported(): bool
    {
        return class_exists('\Workerman\Table') && extension_loaded('swoole');
    }

    public function backend(): string
    {
        return 'workerman';
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->assertOpen();
        $this->table->set($key, $this->encode($value, $ttl));
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->assertOpen();
        $this->acquire();
        try {
            if ($this->table->exist($key)) {
                return false;
            }
            $this->table->set($key, $this->encode($value, $ttl));
        } finally {
            $this->release();
        }

        return true;
    }

    public function replace(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->assertOpen();
        $this->acquire();
        try {
            if (!$this->table->exist($key)) {
                return false;
            }
            $this->table->set($key, $this->encode($value, $ttl));
        } finally {
            $this->release();
        }

        return true;
    }

    public function setMultiple(array $items, int $ttl = 0): void
    {
        foreach ($items as $k => $v) {
            $this->set((string) $k, $v, $ttl);
        }
    }

    public function get(string $key): mixed
    {
        $this->assertOpen();
        $row = $this->table->get($key, null);
        if ($row === null || $row === false) {
            return null;
        }
        if ($this->expired($row)) {
            $this->table->del($key);

            return null;
        }

        return $this->decode($row);
    }

    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get((string) $k);
        }

        return $out;
    }

    public function exists(string $key): bool
    {
        $this->assertOpen();

        return $this->table->exist($key);
    }

    public function delete(string $key): bool
    {
        $this->assertOpen();

        return (bool) $this->table->del($key);
    }

    public function increment(string $key, int|float $step = 1): int|float
    {
        $this->assertOpen();
        $this->acquire();
        try {
            if (!$this->table->exist($key) || $this->expired($this->table->get($key) ?? [])) {
                $this->table->set($key, [
                    'v' => '',
                    'n' => 0.0,
                    'e' => 0,
                    'f' => is_float($step) ? self::FLAG_FLOAT : self::FLAG_INT,
                ]);
            } elseif (is_float($step) && (int) ($this->table->get($key, 'f') ?? self::FLAG_INT) === self::FLAG_INT) {
                $this->table->set($key, ['f' => self::FLAG_FLOAT]);
            }

            $next = $this->table->inc($key, 'n', $step);
            $isFloat = (int) ($this->table->get($key, 'f') ?? self::FLAG_INT) === self::FLAG_FLOAT;
        } finally {
            $this->release();
        }

        return $isFloat ? (float) $next : (int) $next;
    }

    public function decrement(string $key, int|float $step = 1): int|float
    {
        return $this->increment($key, -$step);
    }

    public function cas(string $key, mixed $oldValue, mixed $newValue): bool
    {
        $this->assertOpen();
        $this->acquire();
        try {
            $row = $this->table->get($key, null);
            if ($row === null || $row === false || $this->expired($row)) {
                return false;
            }
            if ($this->decode($row) !== $oldValue) {
                return false;
            }
            $this->table->set($key, $this->encode($newValue, $this->remainingTtl($row)));
        } finally {
            $this->release();
        }

        return true;
    }

    public function keys(): array
    {
        $this->assertOpen();
        $keys = [];
        foreach ($this->table as $k => $_) {
            $keys[] = (string) $k;
        }

        return $keys;
    }

    public function count(): int
    {
        $this->assertOpen();

        return $this->table->count();
    }

    public function clear(): void
    {
        $this->assertOpen();
        foreach ($this->keys() as $k) {
            $this->table->del($k);
        }
    }

    public function destroy(): void
    {
        if ($this->closed) {
            return;
        }
        $this->clear();
        if (method_exists($this->table, 'destroy')) {
            $this->table->destroy();
        }
        $this->closed = true;
    }

    public function stats(): array
    {
        return [
            'backend' => 'workerman',
            'rows' => $this->rows,
            'value_size' => $this->valueSize,
            'keys' => $this->closed ? 0 : $this->table->count(),
            'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
        ];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function encode(mixed $value, int $ttl): array
    {
        $exp = $ttl > 0 ? time() + $ttl : 0;

        if (is_int($value)) {
            return ['v' => '', 'n' => (float) $value, 'e' => $exp, 'f' => self::FLAG_INT];
        }
        if (is_float($value)) {
            return ['v' => '', 'n' => $value, 'e' => $exp, 'f' => self::FLAG_FLOAT];
        }

        $payload = serialize($value);
        if (strlen($payload) > $this->valueSize) {
            throw new GlobalDataException(
                sprintf('值序列化后 %d 字节，超过 Workerman\Table 列长度 %d', strlen($payload), $this->valueSize),
                context: ['limit' => $this->valueSize],
            );
        }

        return ['v' => $payload, 'n' => 0.0, 'e' => $exp, 'f' => self::FLAG_SERIALIZED];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function decode(array $row): mixed
    {
        return match ((int) ($row['f'] ?? self::FLAG_SERIALIZED)) {
            self::FLAG_INT => (int) ($row['n'] ?? 0),
            self::FLAG_FLOAT => (float) ($row['n'] ?? 0),
            default => SafeUnserialize::value((string) ($row['v'] ?? ''), $this->allowedClasses),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function expired(array $row): bool
    {
        $exp = (int) ($row['e'] ?? 0);

        return $exp > 0 && time() >= $exp;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function remainingTtl(array $row): int
    {
        $exp = (int) ($row['e'] ?? 0);

        return $exp > 0 ? max(1, $exp - time()) : 0;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw GlobalDataException::closed();
        }
    }

    private function acquire(): void
    {
        $this->lock?->lock();
    }

    private function release(): void
    {
        $this->lock?->unlock();
    }
}
