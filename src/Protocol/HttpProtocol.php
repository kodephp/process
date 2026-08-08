<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

/**
 * HTTP/1.1 协议实现
 */
final class HttpProtocol implements ProtocolInterface
{
    private const string EOF = "\r\n\r\n";
    private const string HEADER_EOF = "\r\n";

    /** 请求报文总长度上限 */
    public const int MAX_LENGTH = 10485760;

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
     * 该方法在每个请求的每次收包上都会被调用，是最热的路径之一。
     */
    private static function scanContentLength(string $buffer, int $headerEnd): int
    {
        $pos = stripos($buffer, "\r\ncontent-length:");

        if ($pos === false || $pos >= $headerEnd) {
            return 0;
        }

        $valueStart = $pos + 17;
        $lineEnd = strpos($buffer, self::HEADER_EOF, $valueStart);

        if ($lineEnd === false || $lineEnd > $headerEnd) {
            return 0;
        }

        return (int) trim(substr($buffer, $valueStart, $lineEnd - $valueStart));
    }

    #[\Override]
    public static function encode(mixed $data, mixed $connection = null): string
    {
        // 裸字符串视为响应体，自动补全状态行与必需头部。
        // 已经是完整响应报文（以 "HTTP/" 开头）时原样透传，避免二次包装。
        if (is_string($data)) {
            if (str_starts_with($data, 'HTTP/')) {
                return $data;
            }
            $data = ['body' => $data];
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
     * @return array{
     *     method: string, uri: string, path: string, query: array<string, mixed>,
     *     protocol: string, headers: array<string, string>, body: string,
     *     get: array<string, mixed>, post: array<string, mixed>
     * }
     */
    #[\Override]
    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        return self::parseRequest($buffer);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseRequest(string $data): array
    {
        $headerEnd = strpos($data, self::EOF);
        $headerPart = $headerEnd !== false ? substr($data, 0, $headerEnd) : $data;
        $body = $headerEnd !== false ? substr($data, $headerEnd + 4) : '';

        $lines = explode(self::HEADER_EOF, $headerPart);
        $requestLine = array_shift($lines) ?? '';

        $parts = explode(' ', $requestLine, 3);
        $method = $parts[0] ?? 'GET';
        $uri = $parts[1] ?? '/';
        $protocol = $parts[2] ?? 'HTTP/1.1';

        $headers = self::parseHeaderLines($lines);

        $query = [];
        $queryPos = strpos($uri, '?');

        if ($queryPos !== false) {
            $path = substr($uri, 0, $queryPos);
            parse_str(substr($uri, $queryPos + 1), $query);
        } else {
            $path = $uri;
        }

        if ($path === '') {
            $path = '/';
        }

        return [
            'method' => $method,
            'uri' => $uri,
            'path' => $path,
            'query' => $query,
            'protocol' => $protocol,
            'headers' => $headers,
            'body' => $body,
            'get' => $query,
            'post' => self::parseBody($body, $headers),
        ];
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private static function parseHeaderLines(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $headers[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function parseBody(string $body, array $headers): array
    {
        if ($body === '') {
            return [];
        }

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $post);
            return $post;
        }

        if (str_contains($contentType, 'application/json')) {
            if (!json_validate($body)) {
                return [];
            }

            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public static function getStatusText(int $status): string
    {
        return self::STATUS_TEXTS[$status] ?? 'Unknown';
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
}
