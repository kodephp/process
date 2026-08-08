<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 跨运行时统一请求对象。
 *
 * 断言集中在两件事：字段解析是否正确，以及惰性求值是否真的没在没人问的时候干活。
 */
final class RequestTest extends TestCase
{
    private const string RAW_GET =
        "GET /users?page=2&kw=abc HTTP/1.1\r\n"
        . "Host: example.com\r\n"
        . "User-Agent: kode/1.0\r\n"
        . "Accept-Encoding: gzip, deflate\r\n"
        . "Cookie: sid=abc123; theme=dark\r\n"
        . "\r\n";

    private const string RAW_POST_FORM =
        "POST /login HTTP/1.1\r\n"
        . "Host: example.com\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . "Content-Length: 25\r\n"
        . "\r\n"
        . "user=alice&pass=s3cr3t&x=1";

    private const string RAW_POST_JSON =
        "POST /api HTTP/1.1\r\n"
        . "Host: example.com\r\n"
        . "Content-Type: application/json\r\n"
        . "\r\n"
        . '{"name":"kode","n":7}';

    // ------------------------------------------------------------ 请求行

    public function testParsesRequestLine(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertSame('GET', $req->method());
        $this->assertSame('/users?page=2&kw=abc', $req->uri());
        $this->assertSame('/users', $req->path());
        $this->assertSame('HTTP/1.1', $req->protocol());
        $this->assertSame('page=2&kw=abc', $req->queryString());
        $this->assertTrue($req->isMethod('get'));
        $this->assertTrue($req->isMethod('GET'));
        $this->assertFalse($req->isMethod('POST'));
    }

    public function testMalformedRequestLineFallsBackToDefaults(): void
    {
        $req = Request::fromRaw("BOGUS\r\n\r\n");

        $this->assertSame('BOGUS', $req->method());
        $this->assertSame('/', $req->uri());
        $this->assertSame('HTTP/1.1', $req->protocol());
        $this->assertSame('/', $req->path());
    }

    /**
     * 路径穿越串必须在到达业务之前就被拍平，否则任何拼路径的代码都是漏洞。
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pathNormalizationCases')]
    public function testNormalizesPath(string $uri, string $expected): void
    {
        $req = Request::fromRaw("GET {$uri} HTTP/1.1\r\nHost: a\r\n\r\n");

        $this->assertSame($expected, $req->path());
    }

    /** @return array<string, array{string, string}> */
    public static function pathNormalizationCases(): array
    {
        return [
            '普通路径'      => ['/a/b/c', '/a/b/c'],
            '重复斜杠'      => ['//a///b', '/a/b'],
            '当前目录段'    => ['/a/./b', '/a/b'],
            '上级目录段'    => ['/a/b/../c', '/a/c'],
            '穿越到根之上'  => ['/../../etc/passwd', '/etc/passwd'],
            '结尾斜杠保留'  => ['/a/b/', '/a/b/'],
            '根路径'        => ['/', '/'],
            '带查询串'      => ['/a/../b?x=1', '/b'],
        ];
    }

    public function testRawPathKeepsOriginalForm(): void
    {
        $req = Request::fromRaw("GET /a/b/../c?x=1 HTTP/1.1\r\nHost: a\r\n\r\n");

        $this->assertSame('/a/b/../c', $req->rawPath());
        $this->assertSame('/a/c', $req->path());
    }

    // -------------------------------------------------------------- 头部

    public function testHeaderKeysAreLowercasedAndLookupIsCaseInsensitive(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertSame(['host', 'user-agent', 'accept-encoding', 'cookie'], array_keys($req->headers()));
        $this->assertSame('example.com', $req->header('Host'));
        $this->assertSame('example.com', $req->header('HOST'));
        $this->assertSame('example.com', $req->header('host'));
        $this->assertNull($req->header('X-Missing'));
        $this->assertSame('fallback', $req->header('X-Missing', 'fallback'));
        $this->assertTrue($req->hasHeader('User-Agent'));
        $this->assertFalse($req->hasHeader('X-Missing'));
    }

    public function testRawHeaderScanMatchesParsedLookup(): void
    {
        $raw = "GET / HTTP/1.1\r\nHost: a\r\nCONNECTION: keep-alive\r\nX-Odd:  spaced  \r\n\r\n";

        // 未解析头部时走定向扫描
        $scan = Request::fromRaw($raw);
        $this->assertSame('keep-alive', $scan->rawHeader('Connection'));
        $this->assertSame('spaced', $scan->rawHeader('X-Odd'));
        $this->assertSame('', $scan->rawHeader('X-Absent'));

        // 已解析头部后退化为哈希查找，结果必须一致
        $parsed = Request::fromRaw($raw);
        $parsed->headers();
        $this->assertSame('keep-alive', $parsed->rawHeader('Connection'));
        $this->assertSame('spaced', $parsed->rawHeader('X-Odd'));
        $this->assertSame('', $parsed->rawHeader('X-Absent'));
    }

    public function testHeaderConvenienceAccessors(): void
    {
        $req = Request::fromRaw(self::RAW_POST_JSON);

        $this->assertSame('application/json', $req->contentType());
        $this->assertSame('example.com', $req->host());
        $this->assertTrue($req->isJson());
        $this->assertFalse($req->isAjax());
        $this->assertFalse($req->isSecure());
        $this->assertSame(0, $req->contentLength());
    }

    public function testBearerTokenParsing(): void
    {
        $with = Request::fromRaw("GET / HTTP/1.1\r\nAuthorization: Bearer abc.def\r\n\r\n");
        $this->assertSame('abc.def', $with->bearerToken());

        $basic = Request::fromRaw("GET / HTTP/1.1\r\nAuthorization: Basic eGY=\r\n\r\n");
        $this->assertNull($basic->bearerToken());

        $none = Request::fromRaw("GET / HTTP/1.1\r\n\r\n");
        $this->assertNull($none->bearerToken());
    }

    /**
     * 反代头可以伪造，默认不采信是安全默认值，不是功能缺失。
     */
    public function testForwardedHeadersOnlyTrustedWhenAsked(): void
    {
        $req = Request::fromRaw("GET / HTTP/1.1\r\nX-Forwarded-For: 1.2.3.4, 5.6.7.8\r\n\r\n");
        $req->setAttribute('remote_addr', '10.0.0.1');

        $this->assertSame('10.0.0.1', $req->ip());
        $this->assertSame('1.2.3.4', $req->ip(true));
    }

    public function testHeaderFloodIsCapped(): void
    {
        $lines = '';
        for ($i = 0; $i < Request::MAX_HEADERS + 50; $i++) {
            $lines .= "X-H{$i}: v\r\n";
        }

        $req = Request::fromRaw("GET / HTTP/1.1\r\n{$lines}\r\n");

        $this->assertCount(Request::MAX_HEADERS, $req->headers());
    }

    // -------------------------------------------------------- 查询与表单

    public function testQueryParsing(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertSame(['page' => '2', 'kw' => 'abc'], $req->get());
        $this->assertSame('2', $req->get('page'));
        $this->assertNull($req->get('missing'));
        $this->assertSame('def', $req->get('missing', 'def'));
    }

    public function testFormBodyParsing(): void
    {
        $req = Request::fromRaw(self::RAW_POST_FORM);

        $this->assertSame('alice', $req->post('user'));
        $this->assertSame('s3cr3t', $req->post('pass'));
        $this->assertSame(['user' => 'alice', 'pass' => 's3cr3t', 'x' => '1'], $req->post());
    }

    public function testJsonBodyParsing(): void
    {
        $req = Request::fromRaw(self::RAW_POST_JSON);

        $this->assertSame(['name' => 'kode', 'n' => 7], $req->json());
        $this->assertSame('kode', $req->post('name'));
        $this->assertSame('{"name":"kode","n":7}', $req->body());
        $this->assertSame($req->body(), $req->rawBody());
    }

    public function testInvalidJsonReturnsNullInsteadOfThrowing(): void
    {
        $req = Request::fromRaw("POST / HTTP/1.1\r\nContent-Type: application/json\r\n\r\n{not json");

        $this->assertNull($req->json());
        $this->assertSame([], $req->post());
    }

    public function testInputPrefersPostOverQuery(): void
    {
        $raw = "POST /x?k=fromQuery HTTP/1.1\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n\r\n"
            . 'k=fromPost&only=post';

        $req = Request::fromRaw($raw);

        $this->assertSame('fromPost', $req->input('k'));
        $this->assertSame('post', $req->input('only'));
        $this->assertSame('dft', $req->input('nope', 'dft'));
        $this->assertTrue($req->has('k'));
        $this->assertFalse($req->has('nope'));
        $this->assertSame(['k' => 'fromPost', 'only' => 'post'], $req->all());
    }

    public function testCookieParsing(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertSame(['sid' => 'abc123', 'theme' => 'dark'], $req->cookies());
        $this->assertSame('dark', $req->cookie('theme'));
        $this->assertNull($req->cookie('absent'));
    }

    // ------------------------------------------------------------ 惰性

    /**
     * 只回字符串的 handler 不应触发任何解析——这正是契约丰富度不吃吞吐的原因。
     */
    public function testNothingIsParsedUntilAccessed(): void
    {
        $req = Request::fromRaw(self::RAW_POST_JSON);
        $ref = new \ReflectionObject($req);

        $untouched = [
            'lineParsed' => false,
            'headers'    => null,
            'query'      => null,
            'post'       => null,
            'body'       => null,
            'cookies'    => null,
        ];

        foreach ($untouched as $name => $expected) {
            $this->assertSame($expected, $ref->getProperty($name)->getValue($req), "{$name} 不应被提前求值");
        }

        // 取一次 path 只应展开请求行，不应连带解析头部与 body
        $req->path();
        $this->assertTrue($ref->getProperty('lineParsed')->getValue($req));
        $this->assertNull($ref->getProperty('headers')->getValue($req));
        $this->assertNull($ref->getProperty('body')->getValue($req));
    }

    public function testRawHeaderDoesNotForceHeaderParsing(): void
    {
        $req = Request::fromRaw(self::RAW_GET);
        $ref = new \ReflectionObject($req);

        $this->assertSame('gzip, deflate', $req->rawHeader('Accept-Encoding'));
        $this->assertNull($ref->getProperty('headers')->getValue($req), 'rawHeader 不应触发整块头部解析');
    }

    /**
     * keep-alive 判定每请求都要问一次协议版本，isHttp10() 必须给出与 protocol() 相同的答案，
     * 又不能为此触发请求行解析（那会连带做一次路径规范化）。
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('http10Cases')]
    public function testIsHttp10MatchesProtocolWithoutParsing(string $raw, bool $expected): void
    {
        $req = Request::fromRaw($raw);
        $ref = new \ReflectionObject($req);

        $this->assertSame($expected, $req->isHttp10());
        $this->assertFalse(
            $ref->getProperty('lineParsed')->getValue($req),
            'isHttp10() 不应触发请求行解析'
        );

        // 与完整解析的结论必须一致
        $this->assertSame($expected, Request::fromRaw($raw)->protocol() === 'HTTP/1.0');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function http10Cases(): array
    {
        return [
            'HTTP/1.1'      => ["GET / HTTP/1.1\r\nHost: a\r\n\r\n", false],
            'HTTP/1.0'      => ["GET / HTTP/1.0\r\nHost: a\r\n\r\n", true],
            '长路径 1.0'    => ["POST /a/very/long/path?x=1 HTTP/1.0\r\nHost: a\r\n\r\n", true],
            '长路径 1.1'    => ["POST /a/very/long/path?x=1 HTTP/1.1\r\nHost: a\r\n\r\n", false],
            '缺协议段'      => ["GET /some/path\r\nHost: a\r\n\r\n", false],
            'HTTP/2'        => ["GET / HTTP/2\r\nHost: a\r\n\r\n", false],
        ];
    }

    /**
     * 请求行残缺时退回完整解析，语义不能变。
     */
    public function testIsHttp10FallsBackOnShortRequestLine(): void
    {
        $this->assertFalse(Request::fromRaw("GET /\r\n\r\n")->isHttp10());
        $this->assertTrue(Request::fromRaw("GET / HTTP/1.0\r\n\r\n")->isHttp10());
    }

    /**
     * 头部已解析、或来源不是原始报文时，isHttp10() 走 protocol() 通道。
     */
    public function testIsHttp10OnNonRawSources(): void
    {
        $this->assertTrue(Request::fromArray(['protocol' => 'HTTP/1.0'])->isHttp10());
        $this->assertFalse(Request::fromArray(['protocol' => 'HTTP/1.1'])->isHttp10());

        // 已解析过请求行也要给同样的答案
        $req = Request::fromRaw("GET / HTTP/1.0\r\nHost: a\r\n\r\n");
        $req->path();
        $this->assertTrue($req->isHttp10());
    }

    // ------------------------------------------------- 数组兼容与序列化

    public function testArrayAccessMirrorsAccessors(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertSame('GET', $req['method']);
        $this->assertSame('/users', $req['path']);
        $this->assertSame('/users?page=2&kw=abc', $req['uri']);
        $this->assertSame('HTTP/1.1', $req['protocol']);
        $this->assertSame('example.com', $req['headers']['host']);
        $this->assertSame('2', $req['get']['page']);
        $this->assertSame($req['get'], $req['query']);
        $this->assertSame('', $req['body']);
        $this->assertSame([], $req['files']);
        $this->assertSame(0, $req['stream']);
        $this->assertTrue(isset($req['path']));
    }

    public function testUnknownOffsetsBehaveAsAttributes(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertNull($req['user']);
        $this->assertFalse(isset($req['user']));

        $req['user'] = ['id' => 9];

        $this->assertTrue(isset($req['user']));
        $this->assertSame(['id' => 9], $req['user']);
        $this->assertSame(['id' => 9], $req->attribute('user'));

        unset($req['user']);
        $this->assertFalse(isset($req['user']));
    }

    public function testAttributesRoundTrip(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertSame($req, $req->setAttribute('trace', 'x-1'));
        $this->assertSame('x-1', $req->attribute('trace'));
        $this->assertSame('dft', $req->attribute('none', 'dft'));
        $this->assertSame(['trace' => 'x-1'], $req->attributes());
    }

    public function testToArrayKeepsLegacyShape(): void
    {
        $arr = Request::fromRaw(self::RAW_POST_FORM)->toArray();

        $this->assertSame(
            ['method', 'uri', 'path', 'query', 'protocol', 'headers', 'body', 'get', 'post'],
            array_keys($arr)
        );
        $this->assertSame('POST', $arr['method']);
        $this->assertSame('/login', $arr['path']);
        $this->assertSame('alice', $arr['post']['user']);
    }

    public function testIterableCountableAndJsonSerializable(): void
    {
        $req = Request::fromRaw(self::RAW_GET);

        $this->assertCount(9, $req);
        $this->assertSame($req->toArray(), iterator_to_array($req));

        $json = json_decode((string) json_encode($req), true);
        $this->assertSame('/users', $json['path']);
    }

    public function testStringableGivesRequestLine(): void
    {
        $this->assertSame('GET /users?page=2&kw=abc HTTP/1.1', (string) Request::fromRaw(self::RAW_GET));
    }

    public function testRawReturnsOriginalMessage(): void
    {
        $this->assertSame(self::RAW_POST_JSON, Request::fromRaw(self::RAW_POST_JSON)->raw());
    }

    // -------------------------------------------------------- 其它来源

    public function testFromArrayCarriesHttp2Fields(): void
    {
        $req = Request::fromArray([
            'method'   => 'GET',
            'uri'      => '/h2?x=1',
            'path'     => '/h2',
            'protocol' => 'HTTP/2',
            'headers'  => ['Host' => 'a.example', 'X-Up' => 'V'],
            'body'     => '',
            'get'      => ['x' => '1'],
            'post'     => [],
            'scheme'   => 'https',
            'stream'   => 3,
        ]);

        $this->assertSame('HTTP/2', $req->protocol());
        $this->assertSame('/h2', $req->path());
        $this->assertSame('https', $req->scheme());
        $this->assertTrue($req->isSecure());
        $this->assertSame(3, $req->streamId());
        // 头名一律小写，与 HTTP/1.1 来源保持一致
        $this->assertSame(['host' => 'a.example', 'x-up' => 'V'], $req->headers());
        $this->assertSame('a.example', $req->header('HOST'));
        $this->assertSame('1', $req->get('x'));
    }

    public function testFromArrayRebuildsRawMessage(): void
    {
        $req = Request::fromArray([
            'method'   => 'POST',
            'uri'      => '/r',
            'protocol' => 'HTTP/1.1',
            'headers'  => ['Content-Type' => 'text/plain'],
            'body'     => 'hi',
        ]);

        $this->assertSame(
            "POST /r HTTP/1.1\r\ncontent-type: text/plain\r\n\r\nhi",
            $req->raw()
        );
    }

    public function testNativeIsNullForRawSource(): void
    {
        $this->assertNull(Request::fromRaw(self::RAW_GET)->native());
    }
}
