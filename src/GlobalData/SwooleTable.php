<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\Exceptions\GlobalDataException;

/**
 * Swoole Table 后端（机会性启用，不强制安装）
 *
 * 当运行环境**已经**装了 ext-swoole 时，本适配器把 `Swoole\Table` 包装成
 * {@see TableInterface}，与共享内存后端调用方式完全一致。没装 swoole 时
 * {@see self::isSupported()} 返回 false，{@see GlobalData::auto()} 会自动
 * 退回零安装的 {@see SharedMemoryTable}——本库不会为此要求你安装任何组件。
 *
 * 关键约束（Swoole\Table 本身的语义，非本类引入）：
 *  - **必须在 fork 之前创建**，子进程继承的是同一块内存；运行期新建的表不共享给已有子进程；
 *  - 行数在创建时固定（$rows），写满后 set 失败；
 *  - 字符串列长度固定（$valueSize），超长值会被截断，本类会显式抛错而不是静默截断。
 *
 * 存储布局（单行四列）：
 *  - v STRING 序列化后的值（f=0 时有效）
 *  - n FLOAT  数值（f=1/2 时有效，用于 incr/decr 原子自增）
 *  - e INT    过期时间戳，0 表示永不过期
 *  - f INT    值类型标记：0=序列化、1=整数、2=浮点
 */
final class SwooleTable implements TableInterface
{
    private const int FLAG_SERIALIZED = 0;
    private const int FLAG_INT = 1;
    private const int FLAG_FLOAT = 2;

    private object $table;
    private ?object $lock = null;
    private int $rows;
    private int $valueSize;
    private bool $closed = false;

    /**
     * @param int $rows      最大行数（会被 Swoole 向上取整到 2 的幂）
     * @param int $valueSize 单值序列化后的最大字节数
     */
    public function __construct(int $rows = 65536, int $valueSize = 8192)
    {
        if (!self::isSupported()) {
            throw GlobalDataException::unsupported('swoole');
        }

        $this->rows = $rows;
        $this->valueSize = $valueSize;

        /** @var class-string $tableClass */
        $tableClass = '\Swoole\Table';
        $table = new $tableClass($rows);
        $table->column('v', $tableClass::TYPE_STRING, $valueSize);
        $table->column('n', $tableClass::TYPE_FLOAT);
        $table->column('e', $tableClass::TYPE_INT, 8);
        $table->column('f', $tableClass::TYPE_INT, 1);

        if ($table->create() === false) {
            throw new GlobalDataException('Swoole\Table 创建失败（内存不足或列定义非法）', context: [
                'rows' => $rows,
                'value_size' => $valueSize,
            ]);
        }
        $this->table = $table;

        if (class_exists('\Swoole\Lock')) {
            /** @var class-string $lockClass */
            $lockClass = '\Swoole\Lock';
            $this->lock = new $lockClass($lockClass::MUTEX);
        }
    }

    public static function isSupported(): bool
    {
        return extension_loaded('swoole') && class_exists('\Swoole\Table');
    }

    public function backend(): string
    {
        return 'swoole';
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
            if ($this->existsUnlocked($key)) {
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
            if (!$this->existsUnlocked($key)) {
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
        $row = $this->table->get($key);
        if ($row === false) {
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

        return $this->existsUnlocked($key);
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
            $row = $this->table->get($key);
            if ($row === false || $this->expired($row) || (int) $row['f'] === self::FLAG_SERIALIZED) {
                // 不存在 / 已过期 / 非数值：按 0 重新计数
                $this->table->set($key, [
                    'v' => '',
                    'n' => 0.0,
                    'e' => 0,
                    'f' => is_float($step) ? self::FLAG_FLOAT : self::FLAG_INT,
                ]);
            } elseif (is_float($step) && (int) $row['f'] === self::FLAG_INT) {
                $this->table->set($key, ['f' => self::FLAG_FLOAT]);
            }

            $next = $this->table->incr($key, 'n', $step);
            $isFloat = (int) $this->table->get($key, 'f') === self::FLAG_FLOAT;
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
            $row = $this->table->get($key);
            if ($row === false || $this->expired($row)) {
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
        foreach ($this->table as $k => $row) {
            if (!$this->expired($row)) {
                $keys[] = (string) $k;
            }
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
        $this->acquire();
        try {
            foreach ($this->keysUnlocked() as $k) {
                $this->table->del($k);
            }
        } finally {
            $this->release();
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
            'backend' => 'swoole',
            'rows' => $this->rows,
            'value_size' => $this->valueSize,
            'keys' => $this->closed ? 0 : $this->table->count(),
            'memory' => method_exists($this->table, 'getMemorySize') ? $this->table->getMemorySize() : null,
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
                sprintf('值序列化后 %d 字节，超过 Swoole\Table 列长度 %d', strlen($payload), $this->valueSize),
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
        return match ((int) $row['f']) {
            self::FLAG_INT => (int) $row['n'],
            self::FLAG_FLOAT => (float) $row['n'],
            default => unserialize((string) $row['v']),
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

    private function existsUnlocked(string $key): bool
    {
        $row = $this->table->get($key);
        if ($row === false) {
            return false;
        }
        if ($this->expired($row)) {
            $this->table->del($key);

            return false;
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function keysUnlocked(): array
    {
        $keys = [];
        foreach ($this->table as $k => $_) {
            $keys[] = (string) $k;
        }

        return $keys;
    }

    private function acquire(): void
    {
        $this->lock?->lock();
    }

    private function release(): void
    {
        $this->lock?->unlock();
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw GlobalDataException::closed();
        }
    }
}
