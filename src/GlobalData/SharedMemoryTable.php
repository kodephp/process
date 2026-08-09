<?php

declare(strict_types=1);

namespace Kode\Process\GlobalData;

use Kode\Process\Exceptions\GlobalDataException;

/**
 * 基于共享内存的跨进程共享数据表（Swoole Table 式的纯 PHP 实现，零安装）
 *
 * 同一个共享内存键（key）下，同主机的所有进程共享同一份数据：
 *  - 值以原生形式落地（无信封包裹），读取 / 写入直接走 shm_get_var / shm_put_var，
 *    序列化开销与裸 sysvshm 同量级；
 *  - TTL 过期时间维护在共享目录中，热路径（已存在的键、无 TTL）跳过目录写，
 *    因此 set/get 接近裸 sysvshm 吞吐；
 *  - 自增 / CAS 在 System V 信号量保护下完成，保证原子性；
 *  - 支持 TTL（惰性过期）、add / replace、批量读写，能力对齐 Swoole Table；
 *  - 仅依赖 PHP 内置的 sysvshm / sysvsem 扩展，无需安装任何第三方组件。
 *
 * 适用场景：同主机多进程间的计数、配置、状态共享、限流计数器等。
 * 不适用：跨主机的分布式共享（请使用 GlobalData\Client / GlobalData\Server 网络模型）。
 */
final class SharedMemoryTable implements TableInterface
{
    private const int DIR_VAR = 1;

    /**
     * 全局失效计数器所在的变量槽。
     *
     * 值槽 id 自 RESERVED_LOW 起单调递增、恒为正数，因此负数槽位永远不会与之冲突，
     * 也不需要为它保留 RESERVED_LOW 之上的空间（保持与既有共享内存段布局兼容）。
     */
    private const int EPOCH_VAR = -1;

    private const int RESERVED_LOW = 2;

    private \SysvSharedMemory $shm;
    private ?\SysvSemaphore $sem = null;
    private int $key;
    private int $size;
    private bool $closed = false;

    /** @var array<string, array{v: int, exp: int, g: int}> 进程内缓存：键→[varId, 过期时间, 代次] */
    private array $cache = [];

    /** 本进程缓存所对应的全局失效计数；与共享内存中的值不一致时整体作废 */
    private int $cacheEpoch = -1;

    public function __construct(int $key, int $size = 4 * 1024 * 1024)
    {
        if (!extension_loaded('sysvshm')) {
            throw GlobalDataException::unsupported('sysvshm');
        }
        if (!extension_loaded('sysvsem')) {
            throw GlobalDataException::unsupported('sysvsem');
        }

        $this->key = $key;
        $this->size = $size;

        // macOS 的 SysV 共享内存总量极小（kern.sysv.shmall≈1024 页≈4MB），
        // 申请过大时按降序重试更小容量，保证「零安装、开箱即用」，而非直接抛错。
        // Linux 上首试即成功，不会进入重试。
        $attempts = [$size];
        if ($size > 1024 * 1024) {
            $attempts[] = 1024 * 1024;
        }
        $attempts[] = 512 * 1024;
        $attempts[] = 256 * 1024;
        $attempts = array_values(array_unique($attempts));

        $shm = false;
        foreach ($attempts as $trySize) {
            $shm = @shm_attach($key, $trySize, 0600);
            if ($shm !== false) {
                $this->size = $trySize;
                break;
            }
        }
        if ($shm === false) {
            throw GlobalDataException::attachFailed($key);
        }
        $this->shm = $shm;

        $semKey = ($key & 0x7FFFFFFF) ^ 0x5BD1E995;
        $sem = @sem_get($semKey, 1, 0600, true);
        if ($sem === false) {
            throw GlobalDataException::semaphoreFailed($semKey);
        }
        $this->sem = $sem;
    }

    /**
     * 通过文件路径 + 项目字符生成共享内存键（同主机所有进程传入相同参数即可共享）。
     */
    public static function open(string $path, string $project = 'g', int $size = 4 * 1024 * 1024): self
    {
        $key = @ftok($path, $project);
        if ($key === -1 || $key === 0) {
            throw GlobalDataException::attachFailed(0, "ftok 失败: {$path}");
        }
        return new self($key, $size);
    }

    public static function isSupported(): bool
    {
        return extension_loaded('sysvshm') && extension_loaded('sysvsem');
    }

    public function backend(): string
    {
        return 'sysvshm';
    }

    /**
     * 写入键值；ttl > 0 时设置秒级生存时间（惰性过期）。
     * 值以原生形式存储，无信封包裹，热路径（已存在且无 TTL）不触碰共享目录。
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->assertOpen();
        $varId = $this->allocateVarId($key);
        $newExp = $ttl > 0 ? time() + $ttl : 0;
        shm_put_var($this->shm, $varId, $value);
        $this->refreshExp($key, $varId, $newExp);
    }

    /**
     * 仅当键不存在时写入；存在返回 false。
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($this->exists($key)) {
            return false;
        }
        $this->set($key, $value, $ttl);
        return true;
    }

    /**
     * 仅当键已存在时写入；不存在返回 false。
     */
    public function replace(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->exists($key)) {
            return false;
        }
        $this->set($key, $value, $ttl);
        return true;
    }

    /**
     * 批量写入。
     * @param array<string, mixed> $items
     */
    public function setMultiple(array $items, int $ttl = 0): void
    {
        foreach ($items as $k => $v) {
            $this->set($k, $v, $ttl);
        }
    }

    /**
     * 读取键值；键不存在或已过期返回 null。
     *
     * 注意：null 只表示「键不存在 / 已过期」。若存进去的值本身就是 false，这里会如实返回 false，
     * 需要区分「不存在」与「值为假」时请配合 {@see exists()}。
     */
    public function get(string $key): mixed
    {
        $this->assertOpen();
        $meta = $this->readMeta($key);
        if ($meta === null) {
            return null;
        }
        if ($meta['exp'] > 0 && time() >= $meta['exp']) {
            $this->delete($key);
            return null;
        }
        // 值槽可能已被其他进程移除：先探测再取值，
        // 避免 shm_get_var 失败时抛告警并返回 false，与「存的就是 false」混为一谈。
        if (!shm_has_var($this->shm, $meta['v'])) {
            unset($this->cache[$key]);
            return null;
        }
        return shm_get_var($this->shm, $meta['v']);
    }

    /**
     * 批量读取。
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($k);
        }
        return $out;
    }

    public function exists(string $key): bool
    {
        $this->assertOpen();
        $meta = $this->readMeta($key);
        if ($meta === null) {
            return false;
        }
        if ($meta['exp'] > 0 && time() >= $meta['exp']) {
            $this->delete($key);
            return false;
        }
        if (!shm_has_var($this->shm, $meta['v'])) {
            unset($this->cache[$key]);
            return false;
        }
        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertOpen();
        $this->lock();
        try {
            $dir = $this->readDirLocked();
            if (!isset($dir['keys'][$key])) {
                unset($this->cache[$key]);
                return false;
            }
            $varId = (int) $dir['keys'][$key]['v'];
            @shm_remove_var($this->shm, $varId);
            unset($dir['keys'][$key]);
            $this->writeDirLocked($dir);
            unset($this->cache[$key]);
            // 其他进程的进程内缓存可能仍指向该槽位，递增失效计数令其整体作废
            $this->cacheEpoch = $this->bumpEpochLocked();
        } finally {
            $this->unlock();
        }
        return true;
    }

    /**
     * 原子自增；返回自增后的值。过期键按 0 重新计数。
     */
    public function increment(string $key, int|float $step = 1): int|float
    {
        $this->assertOpen();
        $this->lock();
        try {
            $this->syncCache();
            if (isset($this->cache[$key])) {
                $varId = $this->cache[$key]['v'];
                $exp = $this->cache[$key]['exp'];
                $fresh = false;
            } else {
                $dir = $this->readDirLocked();
                $fresh = !isset($dir['keys'][$key]);
                $varId = $this->allocateVarIdLocked($key);
                $exp = $this->cache[$key]['exp'];
            }
            if ($fresh) {
                // 全新键：值槽尚未写入，直接以 0 起算，避免读取未写入槽位产生告警
                $current = 0;
                $exp = 0;
            } elseif ($exp > 0 && time() >= $exp) {
                // 已过期：以 0 重新计数，且不读取可能已被删除的槽位
                $current = 0;
                $exp = 0;
            } else {
                // 槽位可能已被其他进程移除，探测后再取值，避免读未写入槽位产生告警
                $current = shm_has_var($this->shm, $varId) ? shm_get_var($this->shm, $varId) : 0;
                if (!is_numeric($current)) {
                    $current = 0;
                }
            }
            $next = $current + $step;
            shm_put_var($this->shm, $varId, $next);
            if ($exp !== $this->cache[$key]['exp']) {
                $this->updateExpLocked($key, $varId, $exp);
            }
        } finally {
            $this->unlock();
        }
        return $next;
    }

    public function decrement(string $key, int|float $step = 1): int|float
    {
        return $this->increment($key, -$step);
    }

    /**
     * 比较并交换：仅当当前值等于 $oldValue 时写入 $newValue。
     * 成功返回 true，键不存在或值不匹配返回 false。
     */
    public function cas(string $key, mixed $oldValue, mixed $newValue): bool
    {
        $this->assertOpen();
        $this->lock();
        try {
            $dir = $this->readDirLocked();
            if (!isset($dir['keys'][$key])) {
                return false;
            }
            $entry = $this->normalizeEntry($dir['keys'][$key]);
            $varId = $entry['v'];
            if ($entry['exp'] > 0 && time() >= $entry['exp']) {
                return false;
            }
            if (!shm_has_var($this->shm, $varId)) {
                return false;
            }
            $current = shm_get_var($this->shm, $varId);
            if ($current !== $oldValue) {
                return false;
            }
            shm_put_var($this->shm, $varId, $newValue);
            $this->cache[$key] = $entry;
        } finally {
            $this->unlock();
        }
        return true;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        $this->assertOpen();
        $this->lock();
        try {
            $dir = $this->readDirLocked();
            return array_keys($dir['keys']);
        } finally {
            $this->unlock();
        }
    }

    public function count(): int
    {
        $this->assertOpen();
        $this->lock();
        try {
            $dir = $this->readDirLocked();
            return count($dir['keys']);
        } finally {
            $this->unlock();
        }
    }

    public function clear(): void
    {
        $this->assertOpen();
        $this->lock();
        try {
            $dir = $this->readDirLocked();
            foreach ($dir['keys'] as $entry) {
                @shm_remove_var($this->shm, (int) $entry['v']);
            }
            // 关键：绝不回退分配游标 _next。一旦回退，新键会复用刚被释放的槽位，
            // 而其他进程缓存中的旧键仍指向同一槽位，读出来就是别人的值。
            $dir['keys'] = [];
            $this->writeDirLocked($dir);
            $this->cache = [];
            $this->cacheEpoch = $this->bumpEpochLocked();
        } finally {
            $this->unlock();
        }
    }

    /**
     * 清空数据并销毁底层共享内存段（其他进程再 attach 将得到空表）。
     */
    public function destroy(): void
    {
        if ($this->closed) {
            return;
        }
        @shm_remove($this->shm);
        if ($this->sem !== null) {
            @sem_remove($this->sem);
            $this->sem = null;
        }
        $this->closed = true;
    }

    public function getKey(): int
    {
        return $this->key;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function stats(): array
    {
        return [
            'backend' => 'sysvshm',
            'key' => $this->key,
            'size' => $this->size,
            'keys' => $this->count(),
            'pid' => posix_getpid(),
        ];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        @shm_detach($this->shm);
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw GlobalDataException::closed();
        }
    }

    /**
     * 读取共享的全局失效计数。
     */
    private function readEpoch(): int
    {
        $epoch = @shm_get_var($this->shm, self::EPOCH_VAR);
        return is_int($epoch) ? $epoch : 0;
    }

    /**
     * 调用方须已持有锁：递增失效计数，宣告所有进程的进程内缓存作废。返回新值。
     */
    private function bumpEpochLocked(): int
    {
        $next = $this->readEpoch() + 1;
        @shm_put_var($this->shm, self::EPOCH_VAR, $next);
        return $next;
    }

    /**
     * 与共享的失效计数对齐：其他进程做过 delete() / clear() 时丢弃本进程缓存。
     *
     * 只读一个整数槽，比反序列化整份目录便宜得多，热路径开销可忽略。
     */
    private function syncCache(): void
    {
        $epoch = $this->readEpoch();
        if ($epoch !== $this->cacheEpoch) {
            $this->cache = [];
            $this->cacheEpoch = $epoch;
        }
    }

    /**
     * 归一化目录条目，兼容旧版本写入的、不带代次字段的条目。
     * @return array{v: int, exp: int, g: int}
     */
    private function normalizeEntry(array $entry): array
    {
        return [
            'v' => (int) $entry['v'],
            'exp' => (int) ($entry['exp'] ?? 0),
            'g' => (int) ($entry['g'] ?? 0),
        ];
    }

    /**
     * 返回 [varId, exp, g] 或 null（键不存在）。
     * 缓存与共享失效计数一致时跳过共享目录读取（热路径）。
     * @return array{v: int, exp: int, g: int}|null
     */
    private function readMeta(string $key): ?array
    {
        $this->syncCache();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $this->lock();
        try {
            $dir = $this->readDirLocked();
            if (!isset($dir['keys'][$key])) {
                return null;
            }
            $meta = $this->normalizeEntry($dir['keys'][$key]);
        } finally {
            $this->unlock();
        }
        $this->cache[$key] = $meta;
        return $meta;
    }

    private function allocateVarId(string $key): int
    {
        $this->syncCache();
        if (isset($this->cache[$key])) {
            return $this->cache[$key]['v'];
        }
        $this->lock();
        try {
            $varId = $this->allocateVarIdLocked($key);
        } finally {
            $this->unlock();
        }
        return $varId;
    }

    /**
     * 调用方须已持有锁。返回 varId，并保证 $this->cache[$key] 含最新 meta。
     */
    private function allocateVarIdLocked(string $key): int
    {
        $dir = $this->readDirLocked();
        if (!isset($dir['keys'][$key])) {
            $varId = (int) $dir['_next']++;
            // 单调递增的代次：键每次被重建都会换一个代次，缓存据此识别自己是否已过时
            $gen = (int) ($dir['_gen'] ?? 0) + 1;
            $dir['_gen'] = $gen;
            $dir['keys'][$key] = ['v' => $varId, 'exp' => 0, 'g' => $gen];
            $this->writeDirLocked($dir);
            $this->cache[$key] = ['v' => $varId, 'exp' => 0, 'g' => $gen];
        } else {
            $entry = $this->normalizeEntry($dir['keys'][$key]);
            $varId = $entry['v'];
            $this->cache[$key] = $entry;
        }
        return $varId;
    }

    /**
     * 若过期时间较缓存发生变化，则更新共享目录与缓存（热路径无变化则跳过）。
     */
    private function refreshExp(string $key, int $varId, int $newExp): void
    {
        if (($this->cache[$key]['exp'] ?? null) === $newExp) {
            return;
        }
        $this->lock();
        try {
            $this->updateExpLocked($key, $varId, $newExp);
        } finally {
            $this->unlock();
        }
    }

    /**
     * 调用方须已持有锁。
     */
    private function updateExpLocked(string $key, int $varId, int $newExp): void
    {
        $dir = $this->readDirLocked();
        if (!isset($dir['keys'][$key])) {
            // 键已被其他进程删除：不要把过期缓存写回去
            unset($this->cache[$key]);
            return;
        }
        $gen = (int) ($dir['keys'][$key]['g'] ?? 0);
        $dir['keys'][$key] = ['v' => $varId, 'exp' => $newExp, 'g' => $gen];
        $this->writeDirLocked($dir);
        $this->cache[$key] = ['v' => $varId, 'exp' => $newExp, 'g' => $gen];
    }

    /**
     * 调用方须已持有锁。
     */
    private function readDirLocked(): array
    {
        $dir = @shm_get_var($this->shm, self::DIR_VAR);
        if (!is_array($dir) || !isset($dir['_next']) || !isset($dir['keys'])) {
            return ['_next' => self::RESERVED_LOW, '_gen' => 0, 'keys' => []];
        }
        return $dir;
    }

    /**
     * 调用方须已持有锁。
     */
    private function writeDirLocked(array $dir): void
    {
        @shm_put_var($this->shm, self::DIR_VAR, $dir);
    }

    private function lock(): void
    {
        if ($this->sem !== null) {
            @sem_acquire($this->sem);
        }
    }

    private function unlock(): void
    {
        if ($this->sem !== null) {
            @sem_release($this->sem);
        }
    }
}
