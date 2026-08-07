<?php

declare(strict_types=1);

namespace Kode\Process\Cluster\Rpc;

/**
 * 节点间 RPC 的线格式：4 字节大端总长 + JSON 报文体。
 *
 * ```
 * ┌────────────┬───────────────────────────┐
 * │ 4B 总长(N) │  JSON  {"i":..,"m":..}     │
 * └────────────┴───────────────────────────┘
 * ```
 *
 * 与 {@see \Kode\Process\Protocol\LengthPrefix} 同构，但独立实现——
 * RPC 需要在裸 `tcp://` 上自行拆包，才能在 Native / Swoole / Workerman
 * 三种运行时上得到完全一致的行为，不依赖各家对 frame 协议的支持差异。
 *
 * @since 5.0.0
 */
final class RpcFrame
{
    /** 长度头字节数。 */
    public const HEAD_LEN = 4;

    /** 单帧上限 16 MB，防御恶意超长包。 */
    public const MAX_SIZE = 16 * 1024 * 1024;

    /**
     * 打包一条报文。
     *
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return pack('N', self::HEAD_LEN + strlen($body)) . $body;
    }

    /**
     * 从缓冲区头部切出一条完整报文。
     *
     * 缓冲区会被就地消费掉已解析的字节。
     *
     * @param  string $buffer 引用传入，解析后剩余部分回写
     * @return array<string, mixed>|null 数据不足返回 null
     * @throws \JsonException 报文体非法 JSON
     */
    public static function shift(string &$buffer): ?array
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return null;
        }

        $header = unpack('Nlen', $buffer);
        if ($header === false) {
            return null;
        }

        $total = $header['len'];

        // 长度非法：直接清空缓冲区，避免被卡死在坏帧上
        if ($total < self::HEAD_LEN || $total > self::MAX_SIZE) {
            $buffer = '';

            return null;
        }

        if (strlen($buffer) < $total) {
            return null;
        }

        $body   = substr($buffer, self::HEAD_LEN, $total - self::HEAD_LEN);
        $buffer = substr($buffer, $total);

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 构造请求报文。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function request(string $id, string $method, array $params = []): array
    {
        return ['i' => $id, 'm' => $method, 'p' => $params];
    }

    /**
     * 构造成功响应。
     *
     * @return array<string, mixed>
     */
    public static function ok(string $id, mixed $result): array
    {
        return ['i' => $id, 'o' => true, 'r' => $result];
    }

    /**
     * 构造失败响应。
     *
     * @return array<string, mixed>
     */
    public static function fail(string $id, string $error): array
    {
        return ['i' => $id, 'o' => false, 'e' => $error];
    }
}
