<?php

declare(strict_types=1);

namespace Kode\Process;

/**
 * 标准响应格式
 *
 * 统一的 API 响应结构，使用 code 替代 result。
 * 值对象语义：实例创建后不可变，所有 with* 方法返回新实例。
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
final readonly class Response implements \JsonSerializable
{
    public const int CODE_SUCCESS = 0;
    public const int CODE_ERROR = 1;
    public const int CODE_TIMEOUT = 2;
    public const int CODE_NOT_FOUND = 3;
    public const int CODE_INVALID = 4;
    public const int CODE_UNAUTHORIZED = 5;
    public const int CODE_FORBIDDEN = 6;
    public const int CODE_OVERLOADED = 7;
    public const int CODE_SHUTDOWN = 8;
    public const int CODE_RATE_LIMITED = 9;
    public const int CODE_MAINTENANCE = 10;
    public const int CODE_DUPLICATE = 11;
    public const int CODE_TOO_LARGE = 12;
    public const int CODE_UNSUPPORTED = 13;

    /** @var array<int, string> */
    private const array MESSAGES = [
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

    public int $code;
    public string $message;
    public mixed $data;
    /** @var array<string, mixed> */
    public array $meta;
    public float $time;
    public ?float $duration;

    /**
     * @param array<string, mixed> $meta
     */
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
        return new self($code, $message ?: (self::MESSAGES[$code] ?? 'unknown'), $data);
    }

    /**
     * 返回状态码对应的默认文案
     */
    public static function messageFor(int $code): string
    {
        return self::MESSAGES[$code] ?? 'unknown';
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

    #[\Override]
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

    /**
     * 从 JSON 字符串还原响应
     *
     * 先用 json_validate() 校验，避免对非法输入调用 json_decode 产生的
     * 解析开销与静默 null，从而把「格式非法」和「合法的 null」区分开。
     */
    public static function fromJson(string $json): self
    {
        if (!json_validate($json)) {
            return self::invalid('响应 JSON 格式非法');
        }

        $data = json_decode($json, true);

        return self::fromArray(is_array($data) ? $data : []);
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
