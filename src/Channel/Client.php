<?php

declare(strict_types=1);

namespace Kode\Process\Channel;

final class Client
{
    private static ?self $instance = null;
    private string $host;
    private int $port;
    private $socket = null;
    private bool $connected = false;
    private string $buffer = '';
    private array $callbacks = [];
    private array $queue = [];
    private int $retryCount = 3;
    private int $retryDelay = 100000;
    private bool $autoReconnect = true;

    private function __construct(string $host = '127.0.0.1', int $port = 2206)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public static function connect(string $host = '127.0.0.1', int $port = 2206): self
    {
        if (self::$instance === null) {
            self::$instance = new self($host, $port);
        }

        self::$instance->doConnect();
        
        return self::$instance;
    }

    private function doConnect(): bool
    {
        $retry = 0;

        while ($retry < $this->retryCount) {
            try {
                $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                
                if ($this->socket === false) {
                    throw new \RuntimeException('创建 Socket 失败');
                }

                socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
                socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);

                if (!@socket_connect($this->socket, $this->host, $this->port)) {
                    socket_close($this->socket);
                    $this->socket = null;
                    throw new \RuntimeException('连接失败');
                }

                socket_set_nonblock($this->socket);
                $this->connected = true;

                foreach ($this->callbacks as $event => $callback) {
                    $this->sendSubscribe($event);
                }

                foreach ($this->queue as $item) {
                    $this->doPublish($item['event'], $item['data']);
                }
                $this->queue = [];

                return true;
            } catch (\Throwable $e) {
                $retry++;
                usleep($this->retryDelay);
            }
        }

        $this->connected = false;
        return false;
    }

    public static function on(string $event, callable $callback): void
    {
        $instance = self::getInstance();
        $instance->callbacks[$event] = $callback;
        
        if ($instance->connected) {
            $instance->sendSubscribe($event);
        }
    }

    public static function subscribe(string $event, callable $callback): void
    {
        self::on($event, $callback);
    }

    public static function off(string $event): void
    {
        $instance = self::getInstance();
        unset($instance->callbacks[$event]);
        
        if ($instance->connected) {
            $instance->sendUnsubscribe($event);
        }
    }

    public static function unsubscribe(string $event): void
    {
        self::off($event);
    }

    public static function publish(string $event, mixed $data = null): bool
    {
        $instance = self::getInstance();
        return $instance->doPublish($event, $data);
    }

    public static function emit(string $event, mixed $data = null): bool
    {
        return self::publish($event, $data);
    }

    private function doPublish(string $event, mixed $data): bool
    {
        if (!$this->connected) {
            $this->queue[] = ['event' => $event, 'data' => $data];
            return false;
        }

        $message = json_encode([
            'type' => 'publish',
            'event' => $event,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE) . "\n";

        $result = @socket_write($this->socket, $message);
        
        return $result !== false;
    }

    private function sendSubscribe(string $event): void
    {
        if (!$this->connected) {
            return;
        }

        $message = json_encode([
            'type' => 'subscribe',
            'event' => $event
        ]) . "\n";

        @socket_write($this->socket, $message);
    }

    private function sendUnsubscribe(string $event): void
    {
        if (!$this->connected) {
            return;
        }

        $message = json_encode([
            'type' => 'unsubscribe',
            'event' => $event
        ]) . "\n";

        @socket_write($this->socket, $message);
    }

    public static function tick(): int
    {
        $instance = self::getInstance();
        
        if (!$instance->connected) {
            return 0;
        }

        $executed = 0;
        $buffer = '';

        while (true) {
            $chunk = @socket_read($instance->socket, 65535, PHP_NORMAL_READ);
            
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            if (strpos($buffer, "\n") !== false) {
                $messages = explode("\n", $buffer);
                $buffer = array_pop($messages);

                foreach ($messages as $message) {
                    if (empty(trim($message))) {
                        continue;
                    }

                    $data = json_decode($message, true);
                    
                    if ($data === null) {
                        continue;
                    }

                    if (($data['type'] ?? '') === 'event') {
                        $event = $data['event'] ?? '';
                        $eventData = $data['data'] ?? null;

                        if (isset($instance->callbacks[$event])) {
                            try {
                                ($instance->callbacks[$event])($eventData);
                                $executed++;
                            } catch (\Throwable $e) {
                                error_log("[Channel] 事件回调错误: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        $instance->buffer = $buffer;
        return $executed;
    }

    public static function ping(): bool
    {
        $instance = self::getInstance();
        
        if (!$instance->connected) {
            return false;
        }

        $message = json_encode(['type' => 'ping']) . "\n";
        return @socket_write($instance->socket, $message) !== false;
    }

    public static function isConnected(): bool
    {
        $instance = self::getInstance();
        return $instance->connected;
    }

    public static function reconnect(): bool
    {
        $instance = self::getInstance();
        $instance->disconnect();
        return $instance->doConnect();
    }

    public static function disconnect(): void
    {
        $instance = self::getInstance();
        $instance->connected = false;

        if ($instance->socket) {
            @socket_close($instance->socket);
            $instance->socket = null;
        }
    }

    public static function getSubscriptions(): array
    {
        $instance = self::getInstance();
        return array_keys($instance->callbacks);
    }

    public static function getStats(): array
    {
        $instance = self::getInstance();
        return [
            'host' => $instance->host,
            'port' => $instance->port,
            'connected' => $instance->connected,
            'subscriptions' => count($instance->callbacks),
            'queue_size' => count($instance->queue)
        ];
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    public static function setAutoReconnect(bool $enabled): void
    {
        $instance = self::getInstance();
        $instance->autoReconnect = $enabled;
    }

    public static function setRetryCount(int $count): void
    {
        $instance = self::getInstance();
        $instance->retryCount = $count;
    }

    public function __destruct()
    {
        $this->connected = false;
        
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }
}
