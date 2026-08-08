<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

use Kode\Process\Http\Request;

/**
 * HTTP/1.1 协议实现
 */
final class HttpProtocol implements ProtocolInterface
{
    private const string EOF = "\r\n\r\n";
    private const string HEADER_EOF = "\r\n";

    /** 请求报文总长度上限 */
    public const int MAX_LENGTH = 10485760;

    /** 自动 gzip 压缩的最小响应体字节数（过小压缩收益不抵开销） */
    public const int GZIP_MIN_SIZE = 1024;

    /** 请求体超过这个字节数时，头部定向扫描才值得先切出头块再搜 */
    private const int SCAN_BODY_LIMIT = 2048;

    /** @var array<int, string> */
    private const array STATUS_TEXTS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    #[\Override]
    public static function getName(): string
    {
        return 'http';
    }

    /**
     * 计算完整请求报文长度
     *
     * @return int 0=报文未接收完整；-1=超长应拒绝；>0=完整报文字节数
     */
    #[\Override]
    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, self::EOF);

        if ($pos === false) {
            return strlen($buffer) > self::MAX_LENGTH ? -1 : 0;
        }

        $headerLength = $pos + 4;
        $contentLength = self::scanContentLength($buffer, $pos);

        if ($contentLength <= 0) {
            return $headerLength;
        }

        $totalLength = $headerLength + $contentLength;

        if ($totalLength > self::MAX_LENGTH) {
            return -1;
        }

        return strlen($buffer) < $totalLength ? 0 : $totalLength;
    }

    /**
     * 只扫描 Content-Length 字段，避免为了拿一个数字而解析整个头部。
     *
     * 该方法在每个请求的每次收包上都会被调用，是最热的路径之一，因此只做**一次**搜索：
     *  1. 这里曾经「先 strpos 命中标准写法，未命中再 stripos 回退」，前提是「大小写折叠很贵」。
     *     实测推翻了它：PHP 8 的 stripos 与 strpos 成本几乎相同（40B 报文 25.7 vs 25.6ns）。
     *     于是前置的 strpos 在 GET 这类无体请求上纯属多扫一遍报文，而无体请求正是常态。
     *  2. 扫描范围仍限制在头部——否则上传大文件时，每收一个包都要在整个 body 上搜一遍。
     */
    private static function scanContentLength(string $buffer, int $headerEnd): int
    {
        if (strlen($buffer) - $headerEnd > self::SCAN_BODY_LIMIT) {
            // 上传：body 远大于头部，先切出头块再搜才划算
            $pos = stripos(substr($buffer, 0, $headerEnd), "\r\ncontent-length:");
        } else {
            // 体量不大：直接整串搜，用偏移判断命中是否落在头块内，
            // 省掉一次为限定范围而做的 substr 拷贝
            $pos = stripos($buffer, "\r\ncontent-length:");
            if ($pos !== false && $pos >= $headerEnd) {
                $pos = false;
            }
        }

        if ($pos === false) {
            return 0;
        }

        $valueStart = $pos + 17;
        $lineEnd = strpos($buffer, self::HEADER_EOF, $valueStart);

        if ($lineEnd === false || $lineEnd > $headerEnd) {
            return 0;
        }

        return (int) trim(substr($buffer, $valueStart, $lineEnd - $valueStart));
    }

    /** 纯字符串响应体走的固定头前缀，避免每请求重建数组再遍历拼接 */
    private const string DEFAULT_HEAD = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: ";

    #[\Override]
    public static function encode(mixed $data, mixed $connection = null): string
    {
        // 裸字符串视为响应体，自动补全状态行与必需头部。
        // 已经是完整响应报文（以 "HTTP/" 开头）时原样透传，避免二次包装。
        if (is_string($data)) {
            if (str_starts_with($data, 'HTTP/')) {
                return $data;
            }

            // 最热的一条路径：直接拼定长前缀，省掉数组构建 + 头部遍历
            return self::DEFAULT_HEAD . strlen($data) . self::EOF . $data;
        }

        if (!is_array($data)) {
            return '';
        }

        $status = (int) ($data['status'] ?? 200);
        $headers = $data['headers'] ?? ['Content-Type' => 'text/html; charset=utf-8'];
        $body = (string) ($data['body'] ?? '');

        if (!isset($headers['Content-Length']) && !isset($headers['content-length'])) {
            $headers['Content-Length'] = strlen($body);
        }

        $response = 'HTTP/1.1 ' . $status . ' ' . self::getStatusText($status) . self::HEADER_EOF;

        foreach ($headers as $name => $value) {
            $response .= $name . ': ' . $value . self::HEADER_EOF;
        }

        return $response . self::HEADER_EOF . $body;
    }

    /**
     * 交付统一的 {@see Request} 对象。
     *
     * 这里刻意不做任何解析：Request 内部按字段惰性求值，业务碰哪个字段才解析哪个。
     * 一个只回字符串的 handler 因此一个字节都不会被解析，而需要完整请求的业务
     * 拿到的字段与三个运行时完全一致。
     *
     * 旧的数组写法（`$request['path']` 等）通过 ArrayAccess 原样兼容。
     */
    #[\Override]
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        return Request::fromRaw($buffer);
    }

    /**
     * 一次性解析出全部字段的数组形式，供不便持有对象的场景使用。
     *
     * @return array{
     *     method: string, uri: string, path: string, query: array<string, mixed>,
     *     protocol: string, headers: array<string, string>, body: string,
     *     get: array<string, mixed>, post: array<string, mixed>
     * }
     */
    public static function parse(string $buffer): array
    {
        /** @phpstan-ignore-next-line 结构由 Request::toArray() 保证 */
        return Request::fromRaw($buffer)->toArray();
    }

    public static function getStatusText(int $status): string
    {
        return self::STATUS_TEXTS[$status] ?? 'Unknown';
    }

    /**
     * 解析一份完整的 HTTP/1.1 响应报文。
     *
     * 用于把业务直接写出的 1.1 报文桥接到别的承载协议（如 HTTP/2 的 HEADERS + DATA），
     * 从而让同一份 handler 在两种协议版本下都能工作。头名统一转小写，方便下游查找。
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function parseResponse(string $raw): array
    {
        $headerEnd = strpos($raw, self::EOF);
        $headerPart = $headerEnd !== false ? substr($raw, 0, $headerEnd) : $raw;
        $body = $headerEnd !== false ? substr($raw, $headerEnd + 4) : '';

        $lineEnd = strpos($headerPart, self::HEADER_EOF);
        $statusLine = $lineEnd === false ? $headerPart : substr($headerPart, 0, $lineEnd);
        $rest = $lineEnd === false ? '' : substr($headerPart, $lineEnd + 2);

        // "HTTP/1.1 200 OK" → 取中间的状态码
        $parts = explode(' ', $statusLine, 3);
        $status = isset($parts[1]) ? (int) $parts[1] : 200;

        $headers = [];

        if ($rest !== '') {
            foreach (explode(self::HEADER_EOF, $rest) as $line) {
                $colon = strpos($line, ':');

                if ($colon === false) {
                    continue;
                }

                $name = strtolower(trim(substr($line, 0, $colon)));
                $value = trim(substr($line, $colon + 1));

                // 同名头合并，语义与 HTTP/2 的头列表一致
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
            }
        }

        return [
            'status' => $status >= 100 && $status <= 599 ? $status : 200,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * 生成 chunked 响应的头部块（状态行 + Transfer-Encoding: chunked + 自定义头）。
     *
     * @param array<string, string> $headers 自定义响应头；未给 Content-Type 时补默认 text/html
     */
    public static function beginChunked(int $status = 200, array $headers = []): string
    {
        $headers['Transfer-Encoding'] = 'chunked';
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }

        $resp = 'HTTP/1.1 ' . $status . ' ' . self::getStatusText($status) . self::HEADER_EOF;
        foreach ($headers as $name => $value) {
            $resp .= $name . ': ' . $value . self::HEADER_EOF;
        }

        return $resp . self::HEADER_EOF;
    }

    /**
     * 生成单个 chunk 帧（不含终止块）。空数据返回空串，交由调用方决定是否发送。
     */
    public static function chunkFrame(string $data): string
    {
        if ($data === '') {
            return '';
        }

        return dechex(strlen($data)) . self::HEADER_EOF . $data . self::HEADER_EOF;
    }

    /** chunked 终止块（0\r\n\r\n） */
    public static function chunkEnd(): string
    {
        return '0' . self::HEADER_EOF . self::HEADER_EOF;
    }

    /**
     * 判断请求是否接受 gzip 压缩。
     *
     * 兼容 `Accept-Encoding: gzip, deflate` 与普通 `gzip`；忽略 `q=0`（显式拒绝）。
     */
    public static function acceptsGzip(string $header): bool
    {
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $part) {
            $part = strtolower(trim($part));
            if ($part === 'gzip' || str_starts_with($part, 'gzip;')) {
                // gzip;q=0 表示拒绝；q=0.8 等仍接受（注意 q=0 不能误匹配 q=0.8）
                return !preg_match('/q\s*=\s*0(\.0+)?(?=$|;)/', $part);
            }
        }
        return false;
    }

    /**
     * 生成 gzip 压缩的完整 HTTP 响应报文。
     *
     * 入参语义与 {@see encode} 一致：字符串视为响应体；数组为
     * `['status'=>int, 'headers'=>array, 'body'=>string]`；以 `HTTP/` 开头的完整
     * 报文原样透传（避免对已压缩响应二次压缩）。
     *
     * 自动附加 `Content-Encoding: gzip` 与压缩后的 `Content-Length`。压缩失败时
     * 安全回退为非压缩响应（{@see encode}）。
     *
     * @param string|array<string, mixed> $data
     * @param int $level 压缩级别：-1=Z_DEFAULT_STRATEGY(6)、0=不压缩、1~9
     */
    public static function encodeCompressed(mixed $data, int $level = -1): string
    {
        if (is_string($data) && str_starts_with($data, 'HTTP/')) {
            return $data;
        }
        if (is_string($data)) {
            $data = ['body' => $data];
        }
        if (!is_array($data)) {
            return '';
        }

        $status  = (int)($data['status'] ?? 200);
        $headers = $data['headers'] ?? ['Content-Type' => 'text/html; charset=utf-8'];
        $body    = (string)($data['body'] ?? '');

        $encoded = @gzencode($body, $level);
        if ($encoded === false || $encoded === '') {
            return self::encode($data);
        }

        $headers['Content-Encoding'] = 'gzip';
        $headers['Content-Length']   = strlen($encoded);

        $resp = 'HTTP/1.1 ' . $status . ' ' . self::getStatusText($status) . self::HEADER_EOF;
        foreach ($headers as $name => $value) {
            $resp .= $name . ': ' . $value . self::HEADER_EOF;
        }

        return $resp . self::HEADER_EOF . $encoded;
    }
}
