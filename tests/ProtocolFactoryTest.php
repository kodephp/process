<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Protocol\HttpProtocol;
use Kode\Process\Protocol\ProtocolFactory;
use Kode\Process\Protocol\ProtocolInterface;
use Kode\Process\Protocol\TcpProtocol;
use Kode\Process\Protocol\TextProtocol;
use Kode\Process\Protocol\WebSocketProtocol;
use PHPUnit\Framework\TestCase;

/**
 * 协议工厂测试
 */
final class ProtocolFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        // 工厂持有静态注册表，逐用例复位避免跨用例污染
        ProtocolFactory::clear();
    }

    public function testBuiltinProtocolsAreRegistered(): void
    {
        $available = ProtocolFactory::available();

        foreach ([ProtocolFactory::HTTP, ProtocolFactory::WEBSOCKET, ProtocolFactory::TCP, ProtocolFactory::TEXT] as $name) {
            $this->assertContains($name, $available);
            $this->assertTrue(ProtocolFactory::has($name));
        }
    }

    public function testCreateReturnsExpectedInstances(): void
    {
        $this->assertInstanceOf(HttpProtocol::class, ProtocolFactory::create(ProtocolFactory::HTTP));
        $this->assertInstanceOf(WebSocketProtocol::class, ProtocolFactory::create(ProtocolFactory::WEBSOCKET));
        $this->assertInstanceOf(TcpProtocol::class, ProtocolFactory::create(ProtocolFactory::TCP));
        $this->assertInstanceOf(TextProtocol::class, ProtocolFactory::create(ProtocolFactory::TEXT));
    }

    public function testClassForReturnsClassStringForBuiltins(): void
    {
        // 以类字符串注册的协议，classFor 返回原类名（供运行时实例化）
        $this->assertSame(HttpProtocol::class, ProtocolFactory::classFor(ProtocolFactory::HTTP));
        $this->assertSame(WebSocketProtocol::class, ProtocolFactory::classFor('websocket'));
    }

    public function testClassForReturnsClassStringForInstanceRegistration(): void
    {
        // 以实例注册的协议，classFor 返回实例的真实类名
        $instance = new TcpProtocol();
        ProtocolFactory::register('tcp-instance', $instance);
        $this->assertSame(TcpProtocol::class, ProtocolFactory::classFor('tcp-instance'));
    }

    public function testClassForReturnsNullForUnknown(): void
    {
        $this->assertNull(ProtocolFactory::classFor('no-such-proto' . uniqid()));
    }

    public function testCreateIsCaseInsensitive(): void
    {
        $this->assertInstanceOf(HttpProtocol::class, ProtocolFactory::create('HTTP'));
        $this->assertTrue(ProtocolFactory::has('WebSocket'));
    }

    public function testCreateReturnsSharedInstance(): void
    {
        $this->assertSame(
            ProtocolFactory::create(ProtocolFactory::HTTP),
            ProtocolFactory::get(ProtocolFactory::HTTP)
        );
    }

    public function testCreateUnknownProtocolThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未知的协议: gopher');

        ProtocolFactory::create('gopher');
    }

    public function testHasReturnsFalseForUnknownProtocol(): void
    {
        $this->assertFalse(ProtocolFactory::has('gopher'));
    }

    public function testRegisterInstance(): void
    {
        ProtocolFactory::register('custom', new TcpProtocol());

        $this->assertTrue(ProtocolFactory::has('custom'));
        $this->assertInstanceOf(TcpProtocol::class, ProtocolFactory::create('custom'));
    }

    public function testRegisterClassNameIsLazilyInstantiated(): void
    {
        ProtocolFactory::register('lazy', TextProtocol::class);

        $this->assertTrue(ProtocolFactory::has('lazy'));

        $first = ProtocolFactory::create('lazy');

        $this->assertInstanceOf(TextProtocol::class, $first);
        // 实例化后应被缓存
        $this->assertSame($first, ProtocolFactory::create('lazy'));
    }

    public function testClearResetsRegistry(): void
    {
        ProtocolFactory::register('custom', new TcpProtocol());
        ProtocolFactory::clear();

        $this->assertFalse(ProtocolFactory::has('custom'));
        // 内置协议在 has() 触发的 init() 中重新注册
        $this->assertTrue(ProtocolFactory::has(ProtocolFactory::HTTP));
    }

    // --- fromPort / fromScheme -------------------------------------------

    public function testFromPort(): void
    {
        $this->assertSame(ProtocolFactory::HTTP, ProtocolFactory::fromPort(80));
        $this->assertSame(ProtocolFactory::HTTP, ProtocolFactory::fromPort(8080));
        $this->assertSame(ProtocolFactory::HTTP, ProtocolFactory::fromPort(443));
        $this->assertSame(ProtocolFactory::HTTP, ProtocolFactory::fromPort(8443));
        $this->assertNull(ProtocolFactory::fromPort(9501));
    }

    public function testFromScheme(): void
    {
        $map = [
            'http' => ProtocolFactory::HTTP,
            'HTTPS' => ProtocolFactory::HTTP,
            'ws' => ProtocolFactory::WEBSOCKET,
            'wss' => ProtocolFactory::WEBSOCKET,
            'tcp' => ProtocolFactory::TCP,
            'udp' => ProtocolFactory::UDP,
            'text' => ProtocolFactory::TEXT,
            'tls' => ProtocolFactory::SSL,
        ];

        foreach ($map as $scheme => $expected) {
            $this->assertSame($expected, ProtocolFactory::fromScheme($scheme), "scheme={$scheme}");
        }

        $this->assertNull(ProtocolFactory::fromScheme('ftp'));
    }

    // --- detect() ---------------------------------------------------------

    public function testDetectHttpRequest(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'PATCH', 'OPTIONS'] as $verb) {
            $this->assertSame(
                ProtocolFactory::HTTP,
                ProtocolFactory::detect("{$verb} / HTTP/1.1\r\nHost: a\r\n\r\n"),
                "verb={$verb}"
            );
        }
    }

    /**
     * 回归：WebSocket 握手同样以 GET 开头，必须与普通 HTTP 请求区分开
     */
    public function testDetectWebSocketHandshake(): void
    {
        $handshake = "GET /chat HTTP/1.1\r\nHost: a\r\nUpgrade: websocket\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        $this->assertSame(ProtocolFactory::WEBSOCKET, ProtocolFactory::detect($handshake));
    }

    public function testDetectWebSocketFrame(): void
    {
        $this->assertSame(
            ProtocolFactory::WEBSOCKET,
            ProtocolFactory::detect(WebSocketProtocol::encode('hello'))
        );
        $this->assertSame(
            ProtocolFactory::WEBSOCKET,
            ProtocolFactory::detect(WebSocketProtocol::encodePing('hb'))
        );
    }

    public function testDetectTextByNewline(): void
    {
        $this->assertSame(ProtocolFactory::TEXT, ProtocolFactory::detect("hello world\n"));
    }

    public function testDetectTcpFallback(): void
    {
        $this->assertSame(ProtocolFactory::TCP, ProtocolFactory::detect("\x01\x02\x03\x04binary"));
    }

    public function testDetectShortBufferFallsBackToText(): void
    {
        $this->assertSame(ProtocolFactory::TEXT, ProtocolFactory::detect('ab'));
        $this->assertSame(ProtocolFactory::TEXT, ProtocolFactory::detect(''));
    }

    public function testDetectedProtocolIsResolvable(): void
    {
        $samples = [
            "GET / HTTP/1.1\r\nHost: a\r\n\r\n",
            WebSocketProtocol::encode('hi'),
            "line\n",
            "\x01\x02\x03binary",
        ];

        foreach ($samples as $sample) {
            $name = ProtocolFactory::detect($sample);

            $this->assertNotNull($name);
            $this->assertInstanceOf(ProtocolInterface::class, ProtocolFactory::create($name));
        }
    }
}
