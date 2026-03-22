<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Process\Response;

/**
 * 响应格式测试
 */
class ResponseTest extends TestCase
{
    public function testSuccessResponse(): void
    {
        $response = Response::ok(['user_id' => 123]);

        $this->assertEquals(Response::CODE_SUCCESS, $response->code);
        $this->assertEquals('success', $response->message);
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isError());
        $this->assertEquals(['user_id' => 123], $response->data);
    }

    public function testErrorResponse(): void
    {
        $response = Response::error('用户不存在', Response::CODE_NOT_FOUND);

        $this->assertEquals(Response::CODE_NOT_FOUND, $response->code);
        $this->assertEquals('用户不存在', $response->message);
        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->isError());
        $this->assertTrue($response->isNotFound());
    }

    public function testTimeoutResponse(): void
    {
        $response = Response::timeout('请求超时');

        $this->assertEquals(Response::CODE_TIMEOUT, $response->code);
        $this->assertTrue($response->isTimeout());
    }

    public function testInvalidResponse(): void
    {
        $response = Response::invalid('参数错误');

        $this->assertEquals(Response::CODE_INVALID, $response->code);
        $this->assertTrue($response->isInvalid());
    }

    public function testWithMeta(): void
    {
        $response = Response::ok(['id' => 1])
            ->withMeta('job_id', 'job_123')
            ->withMeta('worker_id', 1);

        $this->assertEquals([
            'job_id' => 'job_123',
            'worker_id' => 1,
        ], $response->meta);
    }

    public function testWithMetas(): void
    {
        $response = Response::ok()->withMetas([
            'page' => 1,
            'per_page' => 10,
        ]);

        $this->assertEquals([
            'page' => 1,
            'per_page' => 10,
        ], $response->meta);
    }

    public function testToArray(): void
    {
        $response = Response::ok(['name' => 'test'], '操作成功');
        $array = $response->toArray();

        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('time', $array);
        $this->assertEquals(0, $array['code']);
        $this->assertEquals('操作成功', $array['message']);
    }

    public function testToJson(): void
    {
        $response = Response::ok(['id' => 1]);
        $json = $response->toJson();

        $this->assertJson($json);
        $this->assertStringContainsString('"code":0', $json);
        $this->assertStringContainsString('"message":"success"', $json);
    }

    public function testFromJson(): void
    {
        $json = '{"code":0,"message":"success","data":{"id":1},"time":1234567890.0}';
        $response = Response::fromJson($json);

        $this->assertEquals(Response::CODE_SUCCESS, $response->code);
        $this->assertEquals('success', $response->message);
        $this->assertEquals(['id' => 1], $response->data);
    }

    public function testFromCode(): void
    {
        $response = Response::fromCode(Response::CODE_NOT_FOUND, '资源不存在');

        $this->assertEquals(Response::CODE_NOT_FOUND, $response->code);
        $this->assertEquals('资源不存在', $response->message);
    }

    public function testWrap(): void
    {
        $response = Response::wrap(function () {
            return ['result' => 'ok'];
        });

        $this->assertTrue($response->isSuccess());
        $this->assertEquals(['result' => 'ok'], $response->data);
        $this->assertNotNull($response->duration);
    }

    public function testWrapWithError(): void
    {
        $response = Response::wrap(function () {
            throw new \RuntimeException('测试错误');
        });

        $this->assertTrue($response->isError());
        $this->assertEquals('测试错误', $response->message);
    }

    public function testRateLimited(): void
    {
        $response = Response::rateLimited('请求过于频繁', 60);

        $this->assertEquals(Response::CODE_RATE_LIMITED, $response->code);
        $this->assertEquals(['retry_after' => 60], $response->meta);
    }

    public function testAllCodeConstants(): void
    {
        $this->assertEquals(0, Response::CODE_SUCCESS);
        $this->assertEquals(1, Response::CODE_ERROR);
        $this->assertEquals(2, Response::CODE_TIMEOUT);
        $this->assertEquals(3, Response::CODE_NOT_FOUND);
        $this->assertEquals(4, Response::CODE_INVALID);
        $this->assertEquals(5, Response::CODE_UNAUTHORIZED);
        $this->assertEquals(6, Response::CODE_FORBIDDEN);
        $this->assertEquals(7, Response::CODE_OVERLOADED);
        $this->assertEquals(8, Response::CODE_SHUTDOWN);
        $this->assertEquals(9, Response::CODE_RATE_LIMITED);
        $this->assertEquals(10, Response::CODE_MAINTENANCE);
        $this->assertEquals(11, Response::CODE_DUPLICATE);
        $this->assertEquals(12, Response::CODE_TOO_LARGE);
        $this->assertEquals(13, Response::CODE_UNSUPPORTED);
    }
}
