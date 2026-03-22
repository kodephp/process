<?php

declare(strict_types=1);

namespace Kode\Process;

/**
 * 标准响应格式
 * 
 * 统一的 API 响应结构，使用 code 替代 result
 * 
 * PHP 8.1+: 使用 readonly 属性
 * PHP 8.5+: 支持管道操作符、fpow、array_find 等新特性
 * 
 * 响应格式:
 * {
 *   "code": 0,           // 状态码，0=成功，非0=错误
 *   "message": "success", // 消息
 *   "data": {},          // 数据
 *   "meta": {},          // 元数据
 *   "time": 1234567890.0 // 时间戳
 * }
 */
final class Response implements \JsonSerializable
{
    public const CODE_SUCCESS = 0;
    public const CODE_ERROR = 1;
    public const CODE_TIMEOUT = 2;
    public const CODE_NOT_FOUND = 3;
    public const CODE_INVALID = 4;
    public const CODE_UNAUTHORIZED = 5;
    public const CODE_FORBIDDEN = 6;
    public const CODE_OVERLOADED = 7;
    public const CODE_SHUTDOWN = 8;
    public const CODE_RATE_LIMITED = 9;
    public const CODE_MAINTENANCE = 10;
    public const CODE_DUPLICATE = 11;
    public const CODE_TOO_LARGE = 12;
    public const CODE_UNSUPPORTED = 13;

    private static array $messages = [
        self::CODE_SUCCESS => 'success',
        self::CODE_ERROR => 'error',
        self::CODE_TIMEOUT => 'timeout',
        self::CODE_NOT_FOUND => 'not found',
        self::CODE_INVALID => 'invalid parameters',
        self::CODE_UNAUTHORIZED => 'unauthorized',
        self::CODE_FORBIDDEN => 'forbidden',
        self::CODE_OVERLOADED => 'system overloaded',
        self::CODE_SHUTDOWN => 'system shutdown',
        self::CODE_RATE_LIMITED => 'rate limited',
        self::CODE_MAINTENANCE => 'maintenance mode',
        self::CODE_DUPLICATE => 'duplicate entry',
        self::CODE_TOO_LARGE => 'request too large',
        self::CODE_UNSUPPORTED => 'unsupported operation',
    ];

    public readonly int $code;
    public readonly string $message;
    public readonly mixed $data;
    public readonly array $meta;
    public readonly float $time;
    public readonly ?float $duration;

    public function __construct(
        int $code,
        string $message,
        mixed $data = null,
        array $meta = [],
        float $time = 0.0,
        ?float $duration = null,
    ) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
        $this->meta = $meta;
        $this->time = $time > 0 ? $time : microtime(true);
        $this->duration = $duration;
    }

    public static function ok(mixed $data = null, string $message = 'success'): self
    {
        return new self(self::CODE_SUCCESS, $message, $data);
    }

    public static function success(mixed $data = null, string $message = 'success'): self
    {
        return self::ok($data, $message);
    }

    public static function error(string $message = 'error', int $code = self::CODE_ERROR, mixed $data = null): self
    {
        return new self($code, $message, $data);
    }

    public static function fail(string $message = 'error', int $code = self::CODE_ERROR, mixed $data = null): self
    {
        return self::error($message, $code, $data);
    }

    public static function timeout(string $message = 'timeout', mixed $data = null): self
    {
        return new self(self::CODE_TIMEOUT, $message, $data);
    }

    public static function notFound(string $message = 'not found', mixed $data = null): self
    {
        return new self(self::CODE_NOT_FOUND, $message, $data);
    }

    public static function invalid(string $message = 'invalid parameters', mixed $data = null): self
    {
        return new self(self::CODE_INVALID, $message, $data);
    }

    public static function unauthorized(string $message = 'unauthorized'): self
    {
        return new self(self::CODE_UNAUTHORIZED, $message);
    }

    public static function forbidden(string $message = 'forbidden'): self
    {
        return new self(self::CODE_FORBIDDEN, $message);
    }

    public static function overloaded(string $message = 'system overloaded'): self
    {
        return new self(self::CODE_OVERLOADED, $message);
    }

    public static function shutdown(string $message = 'system shutdown'): self
    {
        return new self(self::CODE_SHUTDOWN, $message);
    }

    public static function rateLimited(string $message = 'rate limited', int $retryAfter = 60): self
    {
        return new self(self::CODE_RATE_LIMITED, $message, null, ['retry_after' => $retryAfter]);
    }

    public static function maintenance(string $message = 'maintenance mode'): self
    {
        return new self(self::CODE_MAINTENANCE, $message);
    }

    public static function duplicate(string $message = 'duplicate entry', mixed $data = null): self
    {
        return new self(self::CODE_DUPLICATE, $message, $data);
    }

    public static function tooLarge(string $message = 'request too large'): self
    {
        return new self(self::CODE_TOO_LARGE, $message);
    }

    public static function unsupported(string $message = 'unsupported operation'): self
    {
        return new self(self::CODE_UNSUPPORTED, $message);
    }

    public static function fromCode(int $code, string $message = '', mixed $data = null): self
    {
        $message = $message ?: (self::$messages[$code] ?? 'unknown');
        return new self($code, $message, $data);
    }

    public function withData(mixed $data): self
    {
        return new self($this->code, $this->message, $data, $this->meta, $this->time, $this->duration);
    }

    public function withMessage(string $message): self
    {
        return new self($this->code, $message, $this->data, $this->meta, $this->time, $this->duration);
    }

    public function withMeta(string $key, mixed $value): self
    {
        return new self(
            $this->code,
            $this->message,
            $this->data,
            [...$this->meta, $key => $value],
            $this->time,
            $this->duration
        );
    }

    public function withMetas(array $metas): self
    {
        return new self(
            $this->code,
            $this->message,
            $this->data,
            [...$this->meta, ...$metas],
            $this->time,
            $this->duration
        );
    }

    public function withDuration(float $seconds): self
    {
        return new self($this->code, $this->message, $this->data, $this->meta, $this->time, $seconds);
    }

    public function withTiming(): self
    {
        return $this->withMeta('timing', [
            'start' => $this->time,
            'end' => microtime(true),
            'duration' => microtime(true) - $this->time,
        ]);
    }

    public function isSuccess(): bool
    {
        return $this->code === self::CODE_SUCCESS;
    }

    public function isError(): bool
    {
        return $this->code !== self::CODE_SUCCESS;
    }

    public function isTimeout(): bool
    {
        return $this->code === self::CODE_TIMEOUT;
    }

    public function isNotFound(): bool
    {
        return $this->code === self::CODE_NOT_FOUND;
    }

    public function isInvalid(): bool
    {
        return $this->code === self::CODE_INVALID;
    }

    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
        ];

        if (!empty($this->meta)) {
            $result['meta'] = $this->meta;
        }

        if ($this->duration !== null) {
            $result['duration'] = $this->duration;
        }

        $result['time'] = $this->time;

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags);
    }

    public function toPrettyJson(): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['code'] ?? self::CODE_ERROR,
            $data['message'] ?? 'unknown',
            $data['data'] ?? null,
            $data['meta'] ?? [],
            $data['time'] ?? 0.0,
            $data['duration'] ?? null,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        return self::fromArray($data ?? []);
    }

    public function throwOnError(): self
    {
        if ($this->isError()) {
            throw new Exceptions\ProcessException($this->message, $this->code);
        }
        return $this;
    }

    public static function wrap(callable $callback): self
    {
        $start = microtime(true);

        try {
            $result = $callback();
            return self::ok($result)->withDuration(microtime(true) - $start);
        } catch (\Throwable $e) {
            return self::error($e->getMessage())->withDuration(microtime(true) - $start);
        }
    }

    public static function try(callable $callback): self
    {
        return self::wrap($callback);
    }

    public function pipe(callable ...$callbacks): self
    {
        $response = $this;

        foreach ($callbacks as $callback) {
            $response = $callback($response);
        }

        return $response;
    }

    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    public function when(bool $condition, callable $callback): self
    {
        if ($condition) {
            return $callback($this);
        }
        return $this;
    }

    public function unless(bool $condition, callable $callback): self
    {
        if (!$condition) {
            return $callback($this);
        }
        return $this;
    }

    public function onSuccess(callable $callback): self
    {
        if ($this->isSuccess()) {
            $callback($this);
        }
        return $this;
    }

    public function onError(callable $callback): self
    {
        if ($this->isError()) {
            $callback($this);
        }
        return $this;
    }
}
