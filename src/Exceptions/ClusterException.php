<?php

declare(strict_types=1);

namespace Kode\Process\Exceptions;

use RuntimeException;

/**
 * 集群（分布式）子系统异常。
 *
 * @since 5.0.0
 */
class ClusterException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public static function backendUnavailable(string $backend, string $hint = ''): self
    {
        return new self(
            sprintf('集群存储后端 %s 不可用%s', $backend, $hint !== '' ? '：' . $hint : ''),
            0,
            null,
            ['backend' => $backend]
        );
    }

    public static function noBackend(): self
    {
        return new self(
            '没有可用的集群存储后端。请安装 ext-redis，或启动本包的网络版 GlobalData 服务，'
            . '或改用 file 后端（Cluster::make(\'file\', [\'path\' => \'/共享目录\'])）'
        );
    }

    public static function unknownBackend(string $backend, array $known = []): self
    {
        return new self(
            sprintf('未知的集群存储后端 %s，已注册：%s', $backend, implode(', ', $known)),
            0,
            null,
            ['backend' => $backend, 'known' => $known]
        );
    }

    public static function lockFailed(string $key, float $waited): self
    {
        return new self(
            sprintf('获取分布式锁 %s 超时（已等待 %.3f 秒）', $key, $waited),
            0,
            null,
            ['key' => $key, 'waited' => $waited]
        );
    }

    public static function nodeNotFound(string $id): self
    {
        return new self(sprintf('集群节点 %s 不存在或已下线', $id), 0, null, ['id' => $id]);
    }

    public static function emptyNodeSet(): self
    {
        return new self('负载均衡器中没有可用节点');
    }

    public static function rpcFailed(string $address, string $reason): self
    {
        return new self(
            sprintf('调用节点 %s 失败：%s', $address, $reason),
            0,
            null,
            ['address' => $address, 'reason' => $reason]
        );
    }

    public static function clockBackwards(int $offsetMs): self
    {
        return new self(
            sprintf('检测到系统时钟回拨 %d 毫秒，为避免 ID 重复已拒绝生成', $offsetMs),
            0,
            null,
            ['offset_ms' => $offsetMs]
        );
    }
}
