<?php

declare(strict_types=1);

namespace Kode\Process\Http;

/**
 * 跨运行时统一的 HTTP 请求对象。
 *
 * ## 为什么需要它
 *
 * 在此之前，`on('message')` 拿到的请求随运行时而变：Native 给数组、Swoole 给
 * `Swoole\Http\Request`、Workerman 给 `Workerman\Protocols\Http\Request`。
 * 三者字段名、大小写、访问方式各不相同，同一份业务代码换个运行时就崩——
 * 「切换底层零改动」的承诺在 HTTP 场景下并不成立。
 *
 * 本类是三个运行时共同的交付物：无论底层是谁，业务拿到的都是同一个类型、
 * 同一套方法、同一份键名。
 *
 * ## 惰性解析
 *
 * 构造时**不做任何解析**，只记录来源。请求行、头部、查询串、表单体各自独立，
 * 谁被访问才解析谁，且只解析一次。返回 "Hello, World" 的 handler 完全不碰请求，
 * 于是一个字节都不会被解析——契约的丰富度不以吞吐为代价。
 *
 * ## 头部大小写
 *
 * `headers()` 的键**一律小写**。这不是随意选择：HTTP/2（RFC 7540 §8.1.2）强制
 * 小写字段名，Swoole 与 Workerman 也都返回小写。统一到小写是三方唯一的公共解，
 * 而 `header()` 访问器本身大小写不敏感，业务写哪种都能取到。
 *
 * ## 兼容旧数组写法
 *
 * 实现了 ArrayAccess，`$request['path']`、`$request['headers']` 等既有写法照常工作。
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class Request implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable, \Stringable
{
    /** 头部条数上限，超出部分丢弃（防头部洪水撑爆内存） */
    public const int MAX_HEADERS = 128;

    /** 请求体超过这个字节数时，定向扫描才值得先切出头块再搜（否则那次 substr 是白拷贝） */
    private const int SCAN_BODY_LIMIT = 2048;

    private const int SRC_RAW       = 0;
    private const int SRC_ARRAY     = 1;
    private const int SRC_SWOOLE    = 2;
    private const int SRC_WORKERMAN = 3;

    /** ArrayAccess 支持的内置键，其余键落到 attributes */
    private const array RESERVED = [
        'method' => true, 'uri' => true, 'path' => true, 'protocol' => true,
        'headers' => true, 'query' => true, 'get' => true, 'post' => true,
        'body' => true, 'cookie' => true, 'cookies' => true, 'files' => true,
        'scheme' => true, 'stream' => true,
    ];

    private int $source;

    /** 原始报文（SRC_RAW）；其余来源为空串 */
    private string $raw = '';

    /** 头块结束位置（"\r\n\r\n" 的偏移），-1 = 尚未定位。每请求只算一次 */
    private int $headEnd = -1;

    /** 底层框架的请求对象（SRC_SWOOLE / SRC_WORKERMAN） */
    private ?object $native = null;

    /** 预解析数据（SRC_ARRAY，如 HTTP/2 会话产出） */
    private array $preset = [];

    // ---- 惰性字段：null / false 表示尚未解析 ----

    private bool $lineParsed = false;
    private string $method = 'GET';
    private string $uri = '/';
    private string $protocol = 'HTTP/1.1';
    private string $path = '';
    private string $queryString = '';

    /** @var array<string, string>|null */
    private ?array $headers = null;
    /** @var array<string, mixed>|null */
    private ?array $query = null;
    /** @var array<string, mixed>|null */
    private ?array $post = null;
    /** @var array<string, mixed>|null */
    private ?array $cookies = null;
    /** @var array<string, mixed>|null */
    private ?array $files = null;
    private ?string $body = null;

    /** @var array<string, mixed> 业务自定义附加数据（中间件传值） */
    private array $attributes = [];

    private string $scheme = 'http';
    private int $streamId = 0;

    private function __construct(int $source)
    {
        $this->source = $source;
    }

    // --------------------------------------------------------------- 构造

    /**
     * HTTP/1.x 原始报文（Native 运行时）。
     *
     * `$headerEnd` 是头块结束偏移。协议层的 `input()` 为了算报文长度本来就要找一次
     * `\r\n\r\n`，把结果顺手带进来，请求对象就不必再找第二遍。
     */
    public static function fromRaw(string $raw, int $headerEnd = -1): self
    {
        $req          = new self(self::SRC_RAW);
        $req->raw     = $raw;
        $req->headEnd = $headerEnd;
        return $req;
    }

    /**
     * 已解析的字段数组（HTTP/2 会话、测试、手工构造）。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $req         = new self(self::SRC_ARRAY);
        $req->preset = $data;
        return $req;
    }

    /** Swoole\Http\Request */
    public static function fromSwoole(object $native): self
    {
        $req         = new self(self::SRC_SWOOLE);
        $req->native = $native;
        return $req;
    }

    /** Workerman\Protocols\Http\Request */
    public static function fromWorkerman(object $native): self
    {
        $req         = new self(self::SRC_WORKERMAN);
        $req->native = $native;
        return $req;
    }

    // ----------------------------------------------------------- 请求行

    public function method(): string
    {
        $this->parseLine();
        return $this->method;
    }

    /** 完整请求目标，含查询串 */
    public function uri(): string
    {
        $this->parseLine();
        return $this->uri;
    }

    /**
     * 规范化后的路径。
     *
     * 会折叠重复斜杠并消解 `.` / `..` 段（RFC 3986 §5.2.4）。这不是美化：
     * `/static/../../etc/passwd` 这类穿越串在路由匹配前就被拍平，
     * 业务再怎么拼路径也拼不出上级目录。要原样路径用 {@see rawPath()}。
     */
    public function path(): string
    {
        $this->parseLine();
        return $this->path;
    }

    /** 未经规范化的原始路径 */
    public function rawPath(): string
    {
        $this->parseLine();
        $pos = strpos($this->uri, '?');
        return $pos === false ? $this->uri : substr($this->uri, 0, $pos);
    }

    /** 如 `HTTP/1.1`、`HTTP/2` */
    public function protocol(): string
    {
        $this->parseLine();
        return $this->protocol;
    }

    /**
     * 是否 HTTP/1.0 —— 只回答这一个是非题，不触发请求行解析。
     *
     * keep-alive 判定在 `Connection` 头缺省时要落到协议版本上，而缺省恰恰是常态：
     * HTTP/1.1 默认持久连接，客户端通常不发 `Connection`。若为此调用 `protocol()`，
     * 就会连带解析出方法、URI 并做一次路径规范化，实测每请求约 240ns——
     * 纯粹为一个版本号买单。
     *
     * 原始报文里协议版本恒为请求行的最后一段，切出末尾 8 字节比较即可。
     * 请求行残缺或来源不是原始报文时，退回完整解析，语义完全一致。
     */
    public function isHttp10(): bool
    {
        if ($this->lineParsed || $this->source !== self::SRC_RAW) {
            return $this->protocol() === 'HTTP/1.0';
        }

        $lineEnd = strpos($this->raw, "\r\n");

        if ($lineEnd === false || $lineEnd < 8) {
            return $this->protocol() === 'HTTP/1.0';
        }

        return substr($this->raw, $lineEnd - 8, 8) === 'HTTP/1.0';
    }

    /** `?` 之后的原始查询串，不含问号 */
    public function queryString(): string
    {
        $this->parseLine();
        return $this->queryString;
    }

    /** `http` 或 `https` */
    public function scheme(): string
    {
        $this->parseLine();
        return $this->scheme;
    }

    /** HTTP/2 流 ID；HTTP/1.x 恒为 0 */
    public function streamId(): int
    {
        $this->parseLine();
        return $this->streamId;
    }

    public function isMethod(string $method): bool
    {
        return strcasecmp($this->method(), $method) === 0;
    }

    // ------------------------------------------------------------- 头部

    /**
     * 全部头部，键为小写。
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        if ($this->headers !== null) {
            return $this->headers;
        }

        $this->headers = match ($this->source) {
            self::SRC_RAW       => self::parseHeaderBlock($this->raw),
            self::SRC_ARRAY     => self::lowerKeys((array)($this->preset['headers'] ?? [])),
            self::SRC_SWOOLE    => self::lowerKeys((array)($this->native->header ?? [])),
            self::SRC_WORKERMAN => self::lowerKeys((array)$this->native->header()),
            default             => [],
        };

        return $this->headers;
    }

    /** 取单个头部，名字大小写不敏感 */
    public function header(string $name, ?string $default = null): ?string
    {
        $headers = $this->headers();
        $key     = strtolower($name);
        return isset($headers[$key]) ? (string)$headers[$key] : $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers()[strtolower($name)]);
    }

    /**
     * 只取一个头部、且不愿为此解析整个头块时用它。
     *
     * 直接在原始报文上做一次定向扫描（与 `scanContentLength` 同样的手法：
     * 先大小写敏感命中标准写法，未命中才在头部区间内回退不敏感搜索）。
     * 运行时热路径上判定 gzip / keep-alive / h2c 走的就是这条，
     * 一个只回字符串的 handler 因此永远不会触发头部解析。
     *
     * 头部已解析过、或来源不是原始报文时，自动退化为哈希查找。
     */
    public function rawHeader(string $name): string
    {
        if ($this->headers !== null || $this->source !== self::SRC_RAW) {
            return (string)($this->headers()[strtolower($name)] ?? '');
        }

        $headEnd = $this->headerEnd();
        $needle  = "\r\n" . $name . ':';

        // 只做一次大小写不敏感搜索。
        //
        // 这里曾经是「先 strpos 命中标准写法，未命中再 stripos 回退」，出发点是
        // 「大小写折叠很贵」。实测推翻了这个前提：PHP 8 的 stripos 与 strpos 成本几乎相同
        // （40B 报文 25.7 vs 25.6ns；523B 真实浏览器请求 78.6 vs 74.2ns）。
        // 于是 strpos 前置在命中时白省不了多少，在未命中时却要多扫一整遍报文——
        // 而「客户端没发这个头」恰恰是热路径上的常态。一次搜索反而更快也更简单。
        if (strlen($this->raw) - $headEnd > self::SCAN_BODY_LIMIT) {
            // 请求体明显大于头部（上传）：先切出头块，避免在 body 上做无谓搜索
            $pos = stripos(substr($this->raw, 0, $headEnd), $needle);
        } else {
            $pos = stripos($this->raw, $needle);
            if ($pos !== false && $pos >= $headEnd) {
                $pos = false;
            }
        }

        if ($pos === false) {
            return '';
        }

        $start   = $pos + strlen($needle);
        $lineEnd = strpos($this->raw, "\r\n", $start);
        if ($lineEnd === false || $lineEnd > $headEnd) {
            return '';
        }

        return trim(substr($this->raw, $start, $lineEnd - $start));
    }

    public function contentType(): string
    {
        return $this->header('content-type', '') ?? '';
    }

    public function contentLength(): int
    {
        return (int)($this->header('content-length', '0') ?? '0');
    }

    public function host(): string
    {
        return $this->header('host', '') ?? '';
    }

    public function userAgent(): string
    {
        return $this->header('user-agent', '') ?? '';
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    public function isAjax(): bool
    {
        return strcasecmp($this->header('x-requested-with', '') ?? '', 'XMLHttpRequest') === 0;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->contentType()), 'json');
    }

    /** `Authorization: Bearer xxx` 中的 token，取不到返回 null */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '') ?? '';
        return stripos($auth, 'bearer ') === 0 ? trim(substr($auth, 7)) : null;
    }

    /**
     * 客户端 IP。默认只信任直连地址；`$trustProxy` 为真时才采信
     * `X-Forwarded-For` / `X-Real-IP`——反代头是可以伪造的，
     * 不在可信网络里就不该拿它做鉴权。
     */
    public function ip(bool $trustProxy = false): string
    {
        if ($trustProxy) {
            $forwarded = $this->header('x-forwarded-for', '') ?? '';
            if ($forwarded !== '') {
                $first = strpos($forwarded, ',');
                return trim($first === false ? $forwarded : substr($forwarded, 0, $first));
            }
            $real = $this->header('x-real-ip', '') ?? '';
            if ($real !== '') {
                return $real;
            }
        }

        return (string)($this->attributes['remote_addr'] ?? '');
    }

    // ------------------------------------------------------- 查询与表单

    /**
     * 查询参数。不传 `$key` 返回全部。
     *
     * @return mixed|array<string, mixed>
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($this->query === null) {
            $this->query = match ($this->source) {
                self::SRC_ARRAY     => (array)($this->preset['get'] ?? $this->preset['query'] ?? []),
                self::SRC_SWOOLE    => (array)($this->native->get ?? []),
                self::SRC_WORKERMAN => (array)$this->native->get(),
                default             => self::parseQueryString($this->queryString()),
            };
        }

        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /**
     * 表单参数（`application/x-www-form-urlencoded` 与 JSON 体）。
     *
     * @return mixed|array<string, mixed>
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($this->post === null) {
            $this->post = match ($this->source) {
                self::SRC_ARRAY     => (array)($this->preset['post'] ?? []),
                self::SRC_SWOOLE    => (array)($this->native->post ?? []),
                self::SRC_WORKERMAN => (array)$this->native->post(),
                default             => self::parseBody($this->body(), $this->contentType()),
            };
        }

        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    /** 先查表单再查查询串 */
    public function input(string $key, mixed $default = null): mixed
    {
        $post = $this->post();
        if (is_array($post) && array_key_exists($key, $post)) {
            return $post[$key];
        }
        $query = $this->get();
        return is_array($query) && array_key_exists($key, $query) ? $query[$key] : $default;
    }

    /** @return array<string, mixed> 查询串与表单合并，表单优先 */
    public function all(): array
    {
        return array_merge((array)$this->get(), (array)$this->post());
    }

    public function has(string $key): bool
    {
        return $this->input($key, $this) !== $this;
    }

    /** 原始请求体 */
    public function body(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $this->body = match ($this->source) {
            self::SRC_RAW       => self::sliceBody($this->raw),
            self::SRC_ARRAY     => (string)($this->preset['body'] ?? ''),
            self::SRC_SWOOLE    => (string)$this->native->rawContent(),
            self::SRC_WORKERMAN => (string)$this->native->rawBody(),
            default             => '',
        };

        return $this->body;
    }

    /** body() 的别名，贴合 PSR / Workerman 的命名习惯 */
    public function rawBody(): string
    {
        return $this->body();
    }

    /** 把请求体按 JSON 解析；非法 JSON 返回 null，不抛异常 */
    public function json(bool $assoc = true): mixed
    {
        $body = $this->body();
        if ($body === '' || !json_validate($body)) {
            return null;
        }
        return json_decode($body, $assoc);
    }

    /** @return array<string, mixed> */
    public function cookies(): array
    {
        if ($this->cookies !== null) {
            return $this->cookies;
        }

        $this->cookies = match ($this->source) {
            self::SRC_ARRAY     => (array)($this->preset['cookie'] ?? $this->preset['cookies'] ?? []),
            self::SRC_SWOOLE    => (array)($this->native->cookie ?? []),
            self::SRC_WORKERMAN => (array)$this->native->cookie(),
            default             => self::parseCookies($this->header('cookie', '') ?? ''),
        };

        return $this->cookies;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies()[$key] ?? $default;
    }

    /** @return array<string, mixed> 上传文件，Native 暂不解析 multipart，返回空数组 */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $this->files = match ($this->source) {
            self::SRC_ARRAY     => (array)($this->preset['files'] ?? []),
            self::SRC_SWOOLE    => (array)($this->native->files ?? []),
            self::SRC_WORKERMAN => (array)($this->native->file() ?? []),
            default             => [],
        };

        return $this->files;
    }

    // --------------------------------------------------------- 附加数据

    /** 中间件之间传值用，不参与协议解析 */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    // ------------------------------------------------------------- 输出

    /**
     * 展开为数组。键与历史版本 Native 交付的结构完全一致。
     *
     * 注意这会强制解析所有字段，热路径上别用。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method'   => $this->method(),
            'uri'      => $this->uri(),
            'path'     => $this->path(),
            'query'    => $this->get(),
            'protocol' => $this->protocol(),
            'headers'  => $this->headers(),
            'body'     => $this->body(),
            'get'      => $this->get(),
            'post'     => $this->post(),
        ];
    }

    /** 原始报文。非 SRC_RAW 来源会按当前字段重建一份等价报文 */
    public function raw(): string
    {
        if ($this->source === self::SRC_RAW) {
            return $this->raw;
        }

        $out = $this->method() . ' ' . $this->uri() . ' ' . $this->protocol() . "\r\n";
        foreach ($this->headers() as $name => $value) {
            $out .= $name . ': ' . $value . "\r\n";
        }

        return $out . "\r\n" . $this->body();
    }

    /** 底层框架的原始请求对象，需要用 Swoole / Workerman 专有能力时的逃生舱 */
    public function native(): ?object
    {
        return $this->native;
    }

    public function __toString(): string
    {
        return $this->method() . ' ' . $this->uri() . ' ' . $this->protocol();
    }

    // -------------------------------------------------- 接口：数组式访问

    public function offsetExists(mixed $offset): bool
    {
        $key = (string)$offset;
        return isset(self::RESERVED[$key]) || isset($this->attributes[$key]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ((string)$offset) {
            'method'            => $this->method(),
            'uri'               => $this->uri(),
            'path'              => $this->path(),
            'protocol'          => $this->protocol(),
            'headers'           => $this->headers(),
            'query', 'get'      => $this->get(),
            'post'              => $this->post(),
            'body'              => $this->body(),
            'cookie', 'cookies' => $this->cookies(),
            'files'             => $this->files(),
            'scheme'            => $this->scheme(),
            'stream'            => $this->streamId(),
            default             => $this->attributes[(string)$offset] ?? null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            return;
        }
        $this->attributes[(string)$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string)$offset]);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    public function count(): int
    {
        return count($this->toArray());
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ------------------------------------------------------------- 解析

    /** 请求行：方法 / URI / 协议版本，顺带切出 path 与查询串 */
    private function parseLine(): void
    {
        if ($this->lineParsed) {
            return;
        }
        $this->lineParsed = true;

        switch ($this->source) {
            case self::SRC_RAW:
                $lineEnd = strpos($this->raw, "\r\n");
                $line    = $lineEnd === false ? $this->raw : substr($this->raw, 0, $lineEnd);
                $parts   = explode(' ', $line, 3);

                $this->method   = $parts[0] !== '' ? $parts[0] : 'GET';
                $this->uri      = ($parts[1] ?? '') !== '' ? $parts[1] : '/';
                $this->protocol = ($parts[2] ?? '') !== '' ? $parts[2] : 'HTTP/1.1';
                break;

            case self::SRC_ARRAY:
                $this->method   = (string)($this->preset['method'] ?? 'GET');
                $this->uri      = (string)($this->preset['uri'] ?? $this->preset['path'] ?? '/');
                $this->protocol = (string)($this->preset['protocol'] ?? 'HTTP/1.1');
                $this->scheme   = (string)($this->preset['scheme'] ?? 'http');
                $this->streamId = (int)($this->preset['stream'] ?? 0);
                break;

            case self::SRC_SWOOLE:
                $server         = (array)($this->native->server ?? []);
                $this->method   = (string)($server['request_method'] ?? 'GET');
                $query          = (string)($server['query_string'] ?? '');
                $uri            = (string)($server['request_uri'] ?? '/');
                $this->uri      = $query === '' ? $uri : $uri . '?' . $query;
                $this->protocol = (string)($server['server_protocol'] ?? 'HTTP/1.1');
                break;

            case self::SRC_WORKERMAN:
                $this->method   = (string)$this->native->method();
                $this->uri      = (string)$this->native->uri();
                $version        = (string)$this->native->protocolVersion();
                $this->protocol = str_starts_with($version, 'HTTP/') ? $version : 'HTTP/' . $version;
                break;
        }

        $pos = strpos($this->uri, '?');
        if ($pos === false) {
            $rawPath           = $this->uri;
            $this->queryString = '';
        } else {
            $rawPath           = substr($this->uri, 0, $pos);
            $this->queryString = substr($this->uri, $pos + 1);
        }

        $this->path = self::normalizePath($rawPath);
    }

    /**
     * 消解 `.` / `..` 与重复斜杠（RFC 3986 §5.2.4）。
     *
     * 绝大多数请求路径里既没有点段也没有连续斜杠，先用两次 strpos 放行，
     * 只有真正含可疑片段的路径才付出拆分重组的代价。
     */
    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (!str_contains($path, '//') && !str_contains($path, '/.')) {
            return $path[0] === '/' ? $path : '/' . $path;
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        $normalized = '/' . implode('/', $out);

        // 保留结尾斜杠语义：`/a/b/` 与 `/a/b` 对路由是两回事
        if ($normalized !== '/' && str_ends_with($path, '/')) {
            $normalized .= '/';
        }

        return $normalized;
    }

    /** 头块结束偏移，算一次记下来 */
    private function headerEnd(): int
    {
        if ($this->headEnd < 0) {
            $pos           = strpos($this->raw, "\r\n\r\n");
            $this->headEnd = $pos === false ? strlen($this->raw) : $pos;
        }

        return $this->headEnd;
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaderBlock(string $raw): array
    {
        $headEnd = strpos($raw, "\r\n\r\n");
        $head    = $headEnd === false ? $raw : substr($raw, 0, $headEnd);

        $lineEnd = strpos($head, "\r\n");
        if ($lineEnd === false) {
            return [];
        }

        $headers = [];
        $count   = 0;

        foreach (explode("\r\n", substr($head, $lineEnd + 2)) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            if (++$count > self::MAX_HEADERS) {
                break;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    private static function sliceBody(string $raw): string
    {
        $headEnd = strpos($raw, "\r\n\r\n");
        return $headEnd === false ? '' : substr($raw, $headEnd + 4);
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function lowerKeys(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower((string)$name)] = is_array($value)
                ? implode(', ', array_map(strval(...), $value))
                : (string)$value;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function parseQueryString(string $query): array
    {
        if ($query === '') {
            return [];
        }
        parse_str($query, $out);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function parseBody(string $body, string $contentType): array
    {
        if ($body === '') {
            return [];
        }

        $type = strtolower($contentType);

        if ($type === '' || str_contains($type, 'x-www-form-urlencoded')) {
            parse_str($body, $out);
            return $out;
        }

        if (str_contains($type, 'json') && json_validate($body)) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @return array<string, string> */
    private static function parseCookies(string $line): array
    {
        if ($line === '') {
            return [];
        }

        $out = [];
        foreach (explode(';', $line) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $out[trim(substr($pair, 0, $eq))] = urldecode(trim(substr($pair, $eq + 1)));
        }

        return $out;
    }
}
