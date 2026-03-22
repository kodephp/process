<?php

declare(strict_types=1);

namespace Kode\Process\Protocol;

use Kode\Process\Response;

final class ProtocolFactory
{
    private static array $protocols = [];
    private static array $customProtocols = [];

    public const HTTP = 'http';
    public const WEBSOCKET = 'websocket';
    public const TCP = 'tcp';
    public const TEXT = 'text';
    public const SSL = 'ssl';
    public const UDP = 'udp';

    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::register(self::HTTP, HttpProtocol::class);
        self::register(self::WEBSOCKET, WebSocketProtocol::class);
        self::register(self::TCP, TcpProtocol::class);
        self::register(self::TEXT, TextProtocol::class);

        self::$initialized = true;
    }

    public static function register(string $name, string|ProtocolInterface $protocol): void
    {
        $name = strtolower($name);

        if ($protocol instanceof ProtocolInterface) {
            self::$protocols[$name] = $protocol;
        } else {
            self::$customProtocols[$name] = $protocol;
        }
    }

    public static function create(string $name, array $options = []): ProtocolInterface
    {
        self::init();

        $name = strtolower($name);

        if (isset(self::$protocols[$name])) {
            return self::$protocols[$name];
        }

        if (isset(self::$customProtocols[$name])) {
            $class = self::$customProtocols[$name];
            $instance = new $class(...$options);

            if (!$instance instanceof ProtocolInterface) {
                throw new \InvalidArgumentException("协议类 {$class} 必须实现 ProtocolInterface");
            }

            self::$protocols[$name] = $instance;
            return $instance;
        }

        throw new \InvalidArgumentException("未知的协议: {$name}");
    }

    public static function get(string $name): ProtocolInterface
    {
        return self::create($name);
    }

    public static function has(string $name): bool
    {
        self::init();
        $name = strtolower($name);

        return isset(self::$protocols[$name]) || isset(self::$customProtocols[$name]);
    }

    public static function available(): array
    {
        self::init();

        return array_unique(
            array_merge(
                array_keys(self::$protocols),
                array_keys(self::$customProtocols)
            )
        );
    }

    public static function fromPort(int $port): ?string
    {
        return match (true) {
            $port === 80 || $port === 8080 => self::HTTP,
            $port === 443 => self::HTTP,
            $port === 8443 => self::HTTP,
            default => null,
        };
    }

    public static function fromScheme(string $scheme): ?string
    {
        return match (strtolower($scheme)) {
            'http', 'https' => self::HTTP,
            'ws', 'wss' => self::WEBSOCKET,
            'tcp' => self::TCP,
            'udp' => self::UDP,
            'text' => self::TEXT,
            'ssl', 'tls' => self::SSL,
            default => null,
        };
    }

    public static function detect(string $data): ?string
    {
        if (strlen($data) < 3) {
            return self::TEXT;
        }

        $prefix = substr($data, 0, 3);

        if ($prefix === 'GET' || $prefix === 'POS' || $prefix === 'PUT' || $prefix === 'DEL' || $prefix === 'HEA' || $prefix === 'PAT' || $prefix === 'OPT') {
            return self::HTTP;
        }

        $firstByte = ord($data[0]);

        if ($firstByte >= 0x81 && $firstByte <= 0x8A) {
            return self::WEBSOCKET;
        }

        if (strpos($data, "\n") !== false) {
            return self::TEXT;
        }

        return self::TCP;
    }

    public static function clear(): void
    {
        self::$protocols = [];
        self::$customProtocols = [];
        self::$initialized = false;
    }
}
