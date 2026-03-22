<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

final class HttpProtocol implements ProtocolInterface
{
    private const EOF = "\r\n\r\n";
    private const HEADER_EOF = "\r\n";
    private const MAX_LENGTH = 10485760;
    private static array $cache = [];

    public static function getName(): string
    {
        return 'http';
    }

    public static function input(string $buffer, mixed $connection = null): int
    {
        $pos = strpos($buffer, self::EOF);

        if ($pos === false) {
            if (strlen($buffer) > self::MAX_LENGTH) {
                return -1;
            }
            return 0;
        }

        $headerLength = $pos + strlen(self::EOF);
        $headers = self::parseHeaders(substr($buffer, 0, $pos));
        $contentLength = (int)($headers['Content-Length'] ?? $headers['content-length'] ?? 0);

        if ($contentLength > 0) {
            $totalLength = $headerLength + $contentLength;
            
            if (strlen($buffer) < $totalLength) {
                return 0;
            }
            
            return $totalLength;
        }

        return $headerLength;
    }

    public static function encode(mixed $data, mixed $connection = null): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            $status = $data['status'] ?? 200;
            $headers = $data['headers'] ?? ['Content-Type' => 'text/html; charset=utf-8'];
            $body = $data['body'] ?? '';

            if (!isset($headers['Content-Length']) && !isset($headers['content-length'])) {
                $headers['Content-Length'] = strlen($body);
            }

            $response = "HTTP/1.1 {$status} " . self::getStatusText($status) . self::HEADER_EOF;

            foreach ($headers as $name => $value) {
                $response .= "{$name}: {$value}" . self::HEADER_EOF;
            }

            $response .= self::HEADER_EOF . $body;

            return $response;
        }

        return '';
    }

    public static function decode(string $buffer, mixed $connection = null): mixed
    {
        $hash = md5($buffer);
        
        if (isset(self::$cache[$hash])) {
            return self::$cache[$hash];
        }

        $request = self::parseRequest($buffer);
        self::$cache[$hash] = $request;

        if (count(self::$cache) > 1000) {
            self::$cache = array_slice(self::$cache, -500, null, true);
        }

        return $request;
    }

    private static function parseRequest(string $data): array
    {
        $headerEnd = strpos($data, self::EOF);
        $headerPart = $headerEnd !== false ? substr($data, 0, $headerEnd) : $data;
        $body = $headerEnd !== false ? substr($data, $headerEnd + strlen(self::EOF)) : '';

        $lines = explode(self::HEADER_EOF, $headerPart);
        $requestLine = array_shift($lines) ?? '';

        $parts = explode(' ', $requestLine, 3);
        $method = $parts[0] ?? 'GET';
        $uri = $parts[1] ?? '/';
        $protocol = $parts[2] ?? 'HTTP/1.1';

        $headers = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = [];
        $queryPos = strpos($uri, '?');
        
        if ($queryPos !== false) {
            parse_str(substr($uri, $queryPos + 1), $query);
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
            'post' => self::parseBody($body, $headers)
        ];
    }

    private static function parseHeaders(string $headerPart): array
    {
        $headers = [];
        $lines = explode(self::HEADER_EOF, $headerPart);
        array_shift($lines);

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return $headers;
    }

    private static function parseBody(string $body, array $headers): array
    {
        if (empty($body)) {
            return [];
        }

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($body, $post);
            return $post;
        }

        if (strpos($contentType, 'application/json') !== false) {
            return json_decode($body, true) ?? [];
        }

        return [];
    }

    private static function getStatusText(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown',
        };
    }
}
