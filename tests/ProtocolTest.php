<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Process\Protocol\ProtocolInterface;
use Kode\Process\Protocol\LengthPrefix;
use Kode\Process\Protocol\TextProtocol;
use Kode\Process\Protocol\TcpProtocol;
use Kode\Process\Protocol\WebSocketProtocol;
use Kode\Process\Protocol\BinaryFile;

/**
 * 协议系统测试
 */
final class ProtocolTest extends TestCase
{
    public function testLengthPrefixProtocol(): void
    {
        $this->assertSame('length-prefix', LengthPrefix::getName());

        $data = ['message' => 'hello', 'code' => 200];
        $encoded = LengthPrefix::encode($data);
        
        $this->assertNotEmpty($encoded);
        $this->assertGreaterThan(4, strlen($encoded));

        $input = LengthPrefix::input($encoded);
        $this->assertSame(strlen($encoded), $input);

        $decoded = LengthPrefix::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testLengthPrefixProtocolWithPartialData(): void
    {
        $data = ['test' => 'value'];
        $encoded = LengthPrefix::encode($data);
        
        $partial = substr($encoded, 0, 2);
        $input = LengthPrefix::input($partial);
        $this->assertSame(0, $input);
    }

    public function testLengthPrefixProtocolWithStringData(): void
    {
        $data = 'plain string data';
        $encoded = LengthPrefix::encode($data);
        
        $decoded = LengthPrefix::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testTextProtocol(): void
    {
        $this->assertSame('text', TextProtocol::getName());

        $data = ['type' => 'message', 'content' => 'hello'];
        $encoded = TextProtocol::encode($data);
        
        $this->assertStringEndsWith("\n", $encoded);

        $input = TextProtocol::input($encoded);
        $this->assertSame(strlen($encoded), $input);

        $decoded = TextProtocol::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testTextProtocolWithPlainString(): void
    {
        $data = 'plain text message';
        $encoded = TextProtocol::encode($data);
        
        $decoded = TextProtocol::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testTextProtocolWithPartialData(): void
    {
        $partial = "hello world";
        $input = TextProtocol::input($partial);
        $this->assertSame(0, $input);
    }

    public function testTcpProtocol(): void
    {
        $this->assertSame('tcp', TcpProtocol::getName());

        $data = 'raw tcp data';
        $encoded = TcpProtocol::encode($data);
        $this->assertSame($data, $encoded);

        $input = TcpProtocol::input($encoded);
        $this->assertSame(strlen($encoded), $input);

        $decoded = TcpProtocol::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testTcpProtocolWithArray(): void
    {
        $data = ['key' => 'value'];
        $encoded = TcpProtocol::encode($data);
        
        $decoded = TcpProtocol::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testWebSocketProtocolGetName(): void
    {
        $this->assertSame('websocket', WebSocketProtocol::getName());
    }

    public function testWebSocketProtocolEncodeText(): void
    {
        $data = 'hello websocket';
        $encoded = WebSocketProtocol::encode($data);
        
        $this->assertNotEmpty($encoded);
        $this->assertGreaterThan(strlen($data), strlen($encoded));
    }

    public function testWebSocketProtocolEncodeArray(): void
    {
        $data = ['type' => 'message', 'content' => 'hello'];
        $encoded = WebSocketProtocol::encode($data);
        
        $this->assertNotEmpty($encoded);
    }

    public function testWebSocketProtocolDecode(): void
    {
        $data = 'hello';
        $encoded = WebSocketProtocol::encode($data);
        
        $decoded = WebSocketProtocol::decode($encoded);
        
        $this->assertIsArray($decoded);
        $this->assertSame('message', $decoded['type']);
        $this->assertSame($data, $decoded['data']);
    }

    public function testWebSocketProtocolPingPong(): void
    {
        $ping = WebSocketProtocol::encodePing('heartbeat');
        $this->assertNotEmpty($ping);

        $pong = WebSocketProtocol::encodePong('heartbeat');
        $this->assertNotEmpty($pong);
    }

    public function testWebSocketProtocolClose(): void
    {
        $close = WebSocketProtocol::encodeClose(1000, 'Normal closure');
        $this->assertNotEmpty($close);
    }

    public function testBinaryFileProtocol(): void
    {
        $this->assertSame('binary-file', BinaryFile::getName());

        $data = [
            'name' => 'test.txt',
            'data' => 'Hello World'
        ];
        $encoded = BinaryFile::encode($data);
        
        $this->assertNotEmpty($encoded);

        $input = BinaryFile::input($encoded);
        $this->assertSame(strlen($encoded), $input);

        $decoded = BinaryFile::decode($encoded);
        $this->assertSame($data['name'], $decoded['name']);
        $this->assertSame($data['data'], $decoded['data']);
    }

    public function testBinaryFileProtocolWithPartialData(): void
    {
        $partial = pack('N', 100) . pack('C', 5);
        $input = BinaryFile::input($partial);
        $this->assertSame(0, $input);
    }

    public function testProtocolInterfaceContract(): void
    {
        $protocols = [
            new LengthPrefix(),
            new TextProtocol(),
            new TcpProtocol(),
            new WebSocketProtocol(),
            new BinaryFile(),
        ];

        foreach ($protocols as $protocol) {
            $this->assertInstanceOf(ProtocolInterface::class, $protocol);
            $this->assertNotEmpty($protocol::getName());
        }
    }
}
