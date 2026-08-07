<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Store;

use Kode\Process\Exceptions\ClusterException;
use Kode\Process\GlobalData\Client;
use Throwable;

/**
 * 基于本包网络版 GlobalData 的协调存储——**零外部依赖的分布式后端**。
 *
 * 不需要 Redis、不需要 etcd、不需要 ZooKeeper：随便挑一台机器跑起 GlobalData 服务，
 * 集群里所有节点连上去就得到了一套完整的协调原语。
 *
 * ```php
 * // 任选一台机器（或独立部署）启动协调服务
 * (new Kode\Process\GlobalData\Server('0.0.0.0:2207'))->start();
 *
 * // 各业务节点
 * $store = new GlobalDataStore(['address' => '10.0.0.5:2207']);
 * ```
 *
 * 原子性保证：GlobalData 服务端单线程串行处理请求，`add` / `cas` / `casdel` /
 * `increment` 在服务端一次性完成，因此跨节点的锁与选举是安全的。
 *
 * 取舍：单点服务，TTL 精度为**秒**级（毫秒参数会向上取整）。追求高可用与毫秒精度
 * 请改用 {@see RedisStore}。
 *
 * @since 5.0.0
 */
final class GlobalDataStore implements StoreInterface
{
    use ValueCodec;

    private readonly Client $client;

    private readonly string $prefix;

    /**
     * @param array{address?: string, prefix?: string, timeout?: int, client?: Client} $options
     */
    public function __construct(array $options = [])
    {
        $this->prefix = (string) ($options['prefix'] ?? 'kode:cluster:');

        if (isset($options['client']) && $options['client'] instanceof Client) {
            $this->client = $options['client'];
            return;
        }

        $address = (string) ($options['address'] ?? '127.0.0.1:2207');

        try {
            $client = new Client($address);
            if (isset($options['timeout'])) {
                $client->setTimeout((int) $options['timeout']);
            }
            // 探活：连不上时立即失败，而不是等到第一次业务调用
            $client->stats();
        } catch (Throwable $e) {
            throw ClusterException::backendUnavailable(
                'globaldata',
                sprintf('无法连接 GlobalData 服务 %s（%s）', $address, $e->getMessage())
            );
        }

        $this->client = $client;
    }

    /**
     * 是否可用。
     *
     * 依赖 ext-sockets（GlobalData 网络层基于 socket_* 实现）。真正的连通性
     * 在构造时探测。
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('sockets') && class_exists(Client::class);
    }

    public function name(): string
    {
        return 'globaldata';
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): mixed
    {
        return $this->decodeValue($this->client->get($this->k($key)));
    }

    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $map = [];
        foreach ($keys as $key) {
            $map[$this->k($key)] = $key;
        }

        $result = [];
        foreach ($this->client->getMulti(array_keys($map)) as $full => $value) {
            if ($value !== null && isset($map[$full])) {
                $result[$map[$full]] = $this->decodeValue($value);
            }
        }

        return $result;
    }

    public function set(string $key, mixed $value, int $ttlMs = 0): bool
    {
        return $this->client->set($this->k($key), $this->encodeValue($value), $this->ttlToSeconds($ttlMs));
    }

    public function setIfAbsent(string $key, mixed $value, int $ttlMs = 0): bool
    {
        return $this->client->add($this->k($key), $this->encodeValue($value), $this->ttlToSeconds($ttlMs));
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttlMs = 0): bool
    {
        $full = $this->k($key);
        $old  = $this->encodeValue($expected);
        $new  = $this->encodeValue($value);

        return $ttlMs > 0
            ? $this->client->casWithTtl($full, $old, $new, $this->ttlToSeconds($ttlMs))
            : $this->client->cas($full, $old, $new);
    }

    public function compareAndDelete(string $key, mixed $expected): bool
    {
        return $this->client->casDelete($this->k($key), $this->encodeValue($expected));
    }

    public function delete(string $key): bool
    {
        return $this->client->delete($this->k($key));
    }

    public function exists(string $key): bool
    {
        return $this->client->exists($this->k($key));
    }

    public function increment(string $key, int $step = 1, int $ttlMs = 0): int
    {
        $value = $this->client->increment($this->k($key), $step, $this->ttlToSeconds($ttlMs));

        return $value === false ? 0 : (int) $value;
    }

    public function expire(string $key, int $ttlMs): bool
    {
        return $this->client->expire($this->k($key), $this->ttlToSeconds($ttlMs));
    }

    public function keys(string $prefix = ''): array
    {
        $cut   = strlen($this->prefix);
        $found = [];

        foreach ($this->client->keys($this->prefix . $prefix . '*') as $full) {
            $found[] = substr((string) $full, $cut);
        }

        return $found;
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
        $this->client->close();
    }
}
