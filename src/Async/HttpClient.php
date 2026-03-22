<?php

declare(strict_types=1);

namespace Kode\Process\Async;

use Kode\Process\Response;

final class HttpClient
{
    private string $baseUrl;
    private float $timeout;
    private array $headers = [];
    private array $options = [];

    public function __construct(string $baseUrl = '', float $timeout = 30.0)
    {
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
    }

    public static function create(string $baseUrl = '', float $timeout = 30.0): self
    {
        return new self($baseUrl, $timeout);
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withTimeout(float $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    public function get(string $url, array $params = [], array $headers = []): Promise
    {
        $url = $this->buildUrl($url, $params);

        return $this->request('GET', $url, [], $headers);
    }

    public function post(string $url, array $data = [], array $headers = []): Promise
    {
        return $this->request('POST', $this->buildUrl($url), $data, $headers);
    }

    public function put(string $url, array $data = [], array $headers = []): Promise
    {
        return $this->request('PUT', $this->buildUrl($url), $data, $headers);
    }

    public function patch(string $url, array $data = [], array $headers = []): Promise
    {
        return $this->request('PATCH', $this->buildUrl($url), $data, $headers);
    }

    public function delete(string $url, array $headers = []): Promise
    {
        return $this->request('DELETE', $this->buildUrl($url), [], $headers);
    }

    public function head(string $url, array $headers = []): Promise
    {
        return $this->request('HEAD', $this->buildUrl($url), [], $headers);
    }

    public function request(string $method, string $url, array $data = [], array $headers = []): Promise
    {
        return new Promise(function ($resolve, $reject) use ($method, $url, $data, $headers) {
            $url = $this->resolveUrl($url);
            $allHeaders = array_merge($this->headers, $headers);

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            if (!empty($allHeaders)) {
                $headerLines = [];

                foreach ($allHeaders as $name => $value) {
                    $headerLines[] = "{$name}: {$value}";
                }

                curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
            }

            if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $contentType = $allHeaders['Content-Type'] ?? $allHeaders['content-type'] ?? null;

                if ($contentType === 'application/json') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                }
            }

            if ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }

            foreach ($this->options as $option => $value) {
                curl_setopt($ch, $option, $value);
            }

            $startTime = microtime(true);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $info = curl_getinfo($ch);

            curl_close($ch);

            $duration = microtime(true) - $startTime;

            if ($errno !== 0) {
                $reject(new \RuntimeException("HTTP 请求失败: {$error}", $errno));
                return;
            }

            $result = new HttpResponse(
                (int) $info['http_code'],
                $response,
                $this->parseHeaders($info),
                $duration,
                $info
            );

            $resolve($result);
        });
    }

    public function json(string $method, string $url, array $data = [], array $headers = []): Promise
    {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        return $this->request($method, $url, $data, $headers)->then(function (HttpResponse $response) {
            $body = $response->getBody();

            if (empty($body)) {
                return $response;
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('JSON 解析失败: ' . json_last_error_msg());
            }

            return $response->withParsedBody($decoded);
        });
    }

    public function upload(string $url, array $files, array $data = [], array $headers = []): Promise
    {
        $postData = [];

        foreach ($files as $name => $file) {
            if (is_string($file)) {
                $postData[$name] = new \CURLFile($file);
            } elseif (is_array($file)) {
                $postData[$name] = new \CURLFile(
                    $file['path'],
                    $file['mime'] ?? null,
                    $file['name'] ?? null
                );
            }
        }

        foreach ($data as $key => $value) {
            $postData[$key] = $value;
        }

        return $this->request('POST', $this->buildUrl($url), $postData, $headers);
    }

    public function download(string $url, string $destination, array $headers = []): Promise
    {
        return new Promise(function ($resolve, $reject) use ($url, $destination, $headers) {
            $url = $this->resolveUrl($url);

            $fp = fopen($destination, 'w');

            if ($fp === false) {
                $reject(new \RuntimeException("无法打开文件: {$destination}"));
                return;
            }

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FILE => $fp,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            if (!empty($headers)) {
                $headerLines = [];

                foreach ($headers as $name => $value) {
                    $headerLines[] = "{$name}: {$value}";
                }

                curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
            }

            curl_exec($ch);

            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $info = curl_getinfo($ch);

            curl_close($ch);
            fclose($fp);

            if ($errno !== 0) {
                unlink($destination);
                $reject(new \RuntimeException("下载失败: {$error}"));
                return;
            }

            $resolve([
                'path' => $destination,
                'size' => filesize($destination),
                'url' => $url,
                'status' => (int) $info['http_code'],
            ]);
        });
    }

    public function concurrent(array $requests, int $concurrency = 5): Promise
    {
        return Async::each($requests, function ($request) {
            $method = $request['method'] ?? 'GET';
            $url = $request['url'];
            $data = $request['data'] ?? [];
            $headers = $request['headers'] ?? [];

            return $this->request($method, $url, $data, $headers);
        }, $concurrency);
    }

    private function buildUrl(string $url, array $params = []): string
    {
        $url = $this->resolveUrl($url);

        if (!empty($params)) {
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $url .= $separator . http_build_query($params);
        }

        return $url;
    }

    private function resolveUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function parseHeaders(array $info): array
    {
        return [
            'content_type' => $info['content_type'] ?? null,
            'size' => $info['size_download'] ?? 0,
            'speed' => $info['speed_download'] ?? 0,
            'total_time' => $info['total_time'] ?? 0,
            'redirect_count' => $info['redirect_count'] ?? 0,
            'effective_url' => $info['url'] ?? '',
        ];
    }
}
