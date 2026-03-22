<?php

declare(strict_types=1);

namespace Kode\Process\Async;

final class HttpResponse
{
    private int $statusCode;
    private string $body;
    private array $headers;
    private float $duration;
    private array $info;
    private mixed $parsedBody = null;

    public function __construct(
        int $statusCode,
        string $body,
        array $headers,
        float $duration,
        array $info = []
    ) {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
        $this->duration = $duration;
        $this->info = $info;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getInfo(): array
    {
        return $this->info;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    public function withParsedBody(mixed $body): self
    {
        $new = clone $this;
        $new->parsedBody = $body;
        return $new;
    }

    public function isOk(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    public function isSuccessful(): bool
    {
        return $this->isOk();
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    public function json(): mixed
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        $decoded = json_decode($this->body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON 解析失败: ' . json_last_error_msg());
        }

        return $decoded;
    }

    public function toArray(): array
    {
        return [
            'status_code' => $this->statusCode,
            'body' => $this->body,
            'headers' => $this->headers,
            'duration' => $this->duration,
            'parsed_body' => $this->parsedBody,
        ];
    }

    public function __toString(): string
    {
        return $this->body;
    }
}
