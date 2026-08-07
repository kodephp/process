<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Store;

use Kode\Process\Exceptions\ClusterException;
use Redis;
use RedisException;

/**
 * Redis 协调存储——生产环境多机集群的推荐后端。
 *
 * 需要 `ext-redis`。所有「读-比较-写」复合操作都下沉为服务端 Lua 脚本执行，
 * 保证跨节点的严格原子性（Redis 单线程执行脚本期间不会被其它命令穿插）。
 *
 * ```php
 * $store = new RedisStore(['host' => '10.0.0.5', 'port' => 6379, 'prefix' => 'app:']);
 * ```
 *
 * @since 5.0.0
 */
final class RedisStore implements StoreInterface
{
    use ValueCodec;

    /** 值匹配则写入新值并可选刷新 TTL。 */
    private const LUA_CAS_SET = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            if tonumber(ARGV[3]) > 0 then
                redis.call('SET', KEYS[1], ARGV[2], 'PX', tonumber(ARGV[3]))
            else
                redis.call('SET', KEYS[1], ARGV[2])
            end
            return 1
        end
        return 0
    LUA;

    /** 值匹配则删除——安全释放锁。 */
    private const LUA_CAS_DEL = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
    LUA;

    /** 自增，并仅在键此前无 TTL 时设置 TTL（滑动窗口限流）。 */
    private const LUA_INCR = <<<'LUA'
        local v = redis.call('INCRBY', KEYS[1], tonumber(ARGV[1]))
        if tonumber(ARGV[2]) > 0 and redis.call('PTTL', KEYS[1]) < 0 then
            redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[2]))
        end
        return v
    LUA;

    private readonly Redis $redis;

    private readonly string $prefix;

    /**
     * @param array{
     *     host?: string, port?: int, timeout?: float, auth?: string|array<int, string>,
     *     database?: int, prefix?: string, persistent?: bool, client?: Redis
     * } $options
     */
    public function __construct(array $options = [])
    {
        if (!self::isAvailable()) {
            throw ClusterException::backendUnavailable('redis', '请先安装 ext-redis（pecl install redis）');
        }

        $this->prefix = (string) ($options['prefix'] ?? 'kode:cluster:');

        if (isset($options['client']) && $options['client'] instanceof Redis) {
            $this->redis = $options['client'];
            return;
        }

        $redis = new Redis();

        try {
            $host    = (string) ($options['host'] ?? '127.0.0.1');
            $port    = (int) ($options['port'] ?? 6379);
            $timeout = (float) ($options['timeout'] ?? 2.0);

            $connected = ($options['persistent'] ?? false)
                ? $redis->pconnect($host, $port, $timeout)
                : $redis->connect($host, $port, $timeout);

            if (!$connected) {
                throw ClusterException::backendUnavailable('redis', sprintf('无法连接 %s:%d', $host, $port));
            }

            if (isset($options['auth'])) {
                $redis->auth($options['auth']);
            }
            if (isset($options['database'])) {
                $redis->select((int) $options['database']);
            }
        } catch (RedisException $e) {
            throw ClusterException::backendUnavailable('redis', $e->getMessage());
        }

        $this->redis = $redis;
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('redis') && class_exists(Redis::class);
    }

    public function name(): string
    {
        return 'redis';
    }

    /** 暴露底层连接，便于业务复用同一条链路。 */
    public function client(): Redis
    {
        return $this->redis;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): mixed
    {
        return $this->decodeValue($this->call(fn (): mixed => $this->redis->get($this->k($key))));
    }

    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $keys   = array_values($keys);
        $raw    = $this->call(fn (): mixed => $this->redis->mget(array_map($this->k(...), $keys)));
        $result = [];

        foreach (is_array($raw) ? $raw : [] as $i => $value) {
            if ($value !== false && $value !== null) {
                $result[$keys[$i]] = $this->decodeValue($value);
            }
        }

        return $result;
    }

    public function set(string $key, mixed $value, int $ttlMs = 0): bool
    {
        $encoded = $this->encodeValue($value);

        return (bool) $this->call(fn (): mixed => $ttlMs > 0
            ? $this->redis->set($this->k($key), $encoded, ['px' => $ttlMs])
            : $this->redis->set($this->k($key), $encoded));
    }

    public function setIfAbsent(string $key, mixed $value, int $ttlMs = 0): bool
    {
        $encoded = $this->encodeValue($value);
        $opts    = $ttlMs > 0 ? ['nx', 'px' => $ttlMs] : ['nx'];

        return (bool) $this->call(fn (): mixed => $this->redis->set($this->k($key), $encoded, $opts));
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttlMs = 0): bool
    {
        $result = $this->call(fn (): mixed => $this->redis->eval(
            self::LUA_CAS_SET,
            [$this->k($key), $this->encodeValue($expected), $this->encodeValue($value), (string) $ttlMs],
            1
        ));

        return (int) $result === 1;
    }

    public function compareAndDelete(string $key, mixed $expected): bool
    {
        $result = $this->call(fn (): mixed => $this->redis->eval(
            self::LUA_CAS_DEL,
            [$this->k($key), $this->encodeValue($expected)],
            1
        ));

        return (int) $result === 1;
    }

    public function delete(string $key): bool
    {
        $this->call(fn (): mixed => $this->redis->del($this->k($key)));

        return true;
    }

    public function exists(string $key): bool
    {
        return (int) $this->call(fn (): mixed => $this->redis->exists($this->k($key))) > 0;
    }

    public function increment(string $key, int $step = 1, int $ttlMs = 0): int
    {
        return (int) $this->call(fn (): mixed => $this->redis->eval(
            self::LUA_INCR,
            [$this->k($key), (string) $step, (string) $ttlMs],
            1
        ));
    }

    public function expire(string $key, int $ttlMs): bool
    {
        if ($ttlMs <= 0) {
            return (bool) $this->call(fn (): mixed => $this->redis->persist($this->k($key)));
        }

        return (bool) $this->call(fn (): mixed => $this->redis->pExpire($this->k($key), $ttlMs));
    }

    public function keys(string $prefix = ''): array
    {
        $pattern = $this->prefix . $prefix . '*';
        $cut     = strlen($this->prefix);
        $found   = [];
        $cursor  = null;

        // 用 SCAN 渐进遍历，避免 KEYS 在大库上阻塞整个 Redis
        do {
            $batch = $this->call(fn (): mixed => $this->redis->scan($cursor, $pattern, 500));
            foreach (is_array($batch) ? $batch : [] as $full) {
                $found[] = substr((string) $full, $cut);
            }
        } while ($cursor !== null && $cursor != 0);

        return array_values(array_unique($found));
    }

    public function flush(): int
    {
        $keys = $this->keys();
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return count($keys);
    }

    public function close(): void
    {
        try {
            $this->redis->close();
        } catch (RedisException) {
            // 连接可能已被服务端关闭，忽略
        }
    }

    /**
     * 统一把 RedisException 翻译成本包异常，避免调用方直连扩展异常类型。
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (RedisException $e) {
            throw new ClusterException('Redis 操作失败：' . $e->getMessage(), 0, $e);
        }
    }
}
