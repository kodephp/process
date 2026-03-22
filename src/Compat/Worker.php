<?php

declare(strict_types=1);

namespace Kode\Process\Compat;

use Kode\Process\Server;
use Kode\Process\Version;
use Kode\Process\Protocol\WebSocketProtocol;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Worker 兼容类
 * 
 * 兼容 Workerman 的 Worker 类 API，支持 HTTP、WebSocket、TCP、UDP、SSL 等协议
 * 
 * @example 基本使用
 * ```php
 * $worker = new Worker('websocket://0.0.0.0:8080');
 * $worker->count = 4;
 * $worker->onMessage = function ($connection, $data) { ... };
 * Worker::runAll();
 * ```
 * 
 * @example SSL/HTTPS
 * ```php
 * $worker = new Worker('http://0.0.0.0:443', [
 *     'ssl' => [
 *         'local_cert' => '/path/to/server.pem',
 *         'local_pk' => '/path/to/server.key',
 *     ]
 * ]);
 * $worker->transport = 'ssl';
 * Worker::runAll();
 * ```
 * 
 * @example UDP
 * ```php
 * $worker = new Worker('udp://0.0.0.0:9292');
 * $worker->onMessage = function ($connection, $data) { ... };
 * Worker::runAll();
 * ```
 * 
 * @example WebSocket
 * ```php
 * $worker = new Worker('websocket://0.0.0.0:8080');
 * $worker->onMessage = function ($connection, $data) {
 *     $connection->send('收到: ' . $data);
 * };
 * Worker::runAll();
 * ```
 */
class Worker
{
    public static function version(): string
    {
        return Version::get();
    }

    public static array $workers = [];

    public static array $pidMap = [];

    public static int $masterPid = 0;

    public static bool $daemonize = false;

    public static string $stdoutFile = '/dev/null';

    public static string $pidFile = '';

    public static string $logFile = '';

    public int $id = 0;

    public int $count = 4;

    public string $name = 'none';

    public string $user = '';

    public string $group = '';

    public bool $reloadable = true;

    public bool $reusePort = false;

    public int $maxRequest = 10000;

    public array $connections = [];

    /**
     * 传输层协议：tcp、udp、ssl、ws、wss
     */
    public string $transport = 'tcp';

    public $onWorkerStart = null;

    public $onWorkerStop = null;

    public $onConnect = null;

    public $onMessage = null;

    public $onClose = null;

    public $onBufferFull = null;

    public $onBufferDrain = null;

    public $onError = null;

    public $onWebSocketConnect = null;

    private string $socket = '';

    private array $contextOptions = [];

    private $serverSocket = null;

    private ?Server $server = null;

    private bool $running = false;

    private LoggerInterface $logger;

    private static int $idCounter = 0;

    private array $readBuffers = [];

    private array $writeBuffers = [];

    private array $clientSockets = [];

    private ?WebSocketProtocol $wsProtocol = null;

    private array $handshakeCompleted = [];

    public function __construct(string $socket = '', array $contextOption = [])
    {
        $this->socket = $socket;
        $this->contextOptions = $contextOption;
        $this->id = ++self::$idCounter;
        $this->logger = new NullLogger();

        $this->parseTransport($socket);

        if ($this->transport === 'ws' || $this->transport === 'wss') {
            $this->wsProtocol = new WebSocketProtocol();
        }

        self::$workers[$this->id] = $this;
    }

    private function parseTransport(string $socket): void
    {
        if (preg_match('/^(\w+):\/\//i', $socket, $matches)) {
            $scheme = strtolower($matches[1]);

            $this->transport = match ($scheme) {
                'udp' => 'udp',
                'ssl', 'https', 'wss' => 'ssl',
                'ws', 'websocket' => 'ws',
                'http', 'tcp', 'text', 'frame' => 'tcp',
                default => 'tcp'
            };
        }
    }

    public function __destruct()
    {
        unset(self::$workers[$this->id]);
    }

    public static function runAll(): void
    {
        foreach (self::$workers as $worker) {
            $worker->start();
        }
    }

    public static function stopAll(): void
    {
        foreach (self::$workers as $worker) {
            $worker->stop();
        }
    }

    public static function reloadAll(): void
    {
        foreach (self::$workers as $worker) {
            $worker->reload();
        }
    }

    public function start(): void
    {
        if ($this->transport === 'udp') {
            $this->startUdp();
            return;
        }

        if ($this->transport === 'ssl' || $this->transport === 'ws' || $this->transport === 'wss') {
            $this->startStreamServer();
            return;
        }

        $this->startTcp();
    }

    private function startTcp(): void
    {
        $config = [
            'worker_count' => $this->count,
            'daemonize' => self::$daemonize,
            'max_requests' => $this->maxRequest,
            'transport' => $this->transport,
        ];

        if (self::$pidFile) {
            $config['pid_file'] = self::$pidFile;
        }

        if (self::$logFile) {
            $config['log_file'] = self::$logFile;
        }

        if ($this->user) {
            $config['user'] = $this->user;
        }

        if ($this->group) {
            $config['group'] = $this->group;
        }

        if ($this->socket) {
            $parsed = $this->parseSocket($this->socket);
            $config = array_merge($config, $parsed);
        }

        $this->server = new Server($config, $this->logger);

        $worker = $this;

        $this->server->onWorkerStart(function () use ($worker) {
            if ($worker->onWorkerStart !== null) {
                ($worker->onWorkerStart)($worker);
            }
        });

        $this->server->onTask(function ($taskId, $data) use ($worker) {
            if ($worker->onMessage !== null) {
                $connection = new Connection($data, $worker);
                ($worker->onMessage)($connection, $data['message'] ?? $data);
            }

            return \Kode\Process\Response::ok();
        });

        $this->server->start();
    }

    private function startStreamServer(): void
    {
        $parsed = $this->parseSocket($this->socket);
        $host = $parsed['host'] ?? '0.0.0.0';
        $port = $parsed['port'] ?? 8080;

        $context = stream_context_create($this->contextOptions);
        $scheme = ($this->transport === 'ssl' || $this->transport === 'wss') ? 'ssl' : 'tcp';

        $socket = stream_socket_server(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("服务启动失败: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);

        $this->serverSocket = $socket;
        $this->running = true;
        $this->clientSockets = [(int)$socket => $socket];

        if ($this->onWorkerStart !== null) {
            ($this->onWorkerStart)($this);
        }

        echo "[Stream] {$this->name} 启动在 {$host}:{$port}\n";

        $this->eventLoop();
    }

    private function eventLoop(): void
    {
        while ($this->running) {
            $read = $this->clientSockets;
            $write = [];
            $except = [];

            foreach ($this->writeBuffers as $fd => $buffer) {
                if (!empty($buffer)) {
                    $write[] = $this->clientSockets[$fd] ?? null;
                }
            }
            $write = array_filter($write);

            if (empty($read) && empty($write)) {
                usleep(1000);
                continue;
            }

            $numChanged = @stream_select($read, $write, $except, 1);

            if ($numChanged === false) {
                continue;
            }

            foreach ($read as $socket) {
                $fd = (int)$socket;

                if ($fd === (int)$this->serverSocket) {
                    $this->acceptConnection();
                } else {
                    $this->readFromSocket($socket, $fd);
                }
            }

            foreach ($write as $socket) {
                $fd = (int)$socket;
                $this->writeToSocket($socket, $fd);
            }
        }
    }

    private function acceptConnection(): void
    {
        $client = @stream_socket_accept($this->serverSocket, 0);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);
        $fd = (int)$client;

        $this->clientSockets[$fd] = $client;
        $this->readBuffers[$fd] = '';
        $this->writeBuffers[$fd] = '';
        $this->handshakeCompleted[$fd] = false;

        $connection = new StreamConnection($client, $this);
        $this->connections[$fd] = $connection;

        if ($this->onConnect !== null) {
            ($this->onConnect)($connection);
        }
    }

    private function readFromSocket($socket, int $fd): void
    {
        $data = @fread($socket, 8192);

        if ($data === false || $data === '') {
            $this->closeConnection($fd);
            return;
        }

        $this->readBuffers[$fd] .= $data;

        if (($this->transport === 'ws' || $this->transport === 'wss') && $this->wsProtocol !== null) {
            $this->handleWebSocket($fd);
        } else {
            $this->handleTcp($fd);
        }
    }

    private function handleTcp(int $fd): void
    {
        $buffer = $this->readBuffers[$fd];

        if (!empty($buffer) && $this->onMessage !== null) {
            $connection = $this->connections[$fd] ?? null;
            if ($connection) {
                ($this->onMessage)($connection, $buffer);
            }
            $this->readBuffers[$fd] = '';
        }
    }

    private function handleWebSocket(int $fd): void
    {
        $buffer = $this->readBuffers[$fd];

        if (!$this->handshakeCompleted[$fd]) {
            if ($this->doHandshake($fd, $buffer)) {
                $this->handshakeCompleted[$fd] = true;
                $this->readBuffers[$fd] = '';

                if ($this->onWebSocketConnect !== null) {
                    $connection = $this->connections[$fd] ?? null;
                    if ($connection) {
                        ($this->onWebSocketConnect)($connection);
                    }
                }
            }
            return;
        }

        while (true) {
            $length = WebSocketProtocol::input($buffer, $this->connections[$fd] ?? null);

            if ($length === 0 || strlen($buffer) < $length) {
                break;
            }

            $frame = substr($buffer, 0, $length);
            $buffer = substr($buffer, $length);

            $decoded = WebSocketProtocol::decode($frame, $this->connections[$fd] ?? null);

            if ($decoded !== null && isset($decoded['type'])) {
                $connection = $this->connections[$fd] ?? null;
                if (!$connection) {
                    continue;
                }

                if ($decoded['type'] === 'ping') {
                    $connection->send(WebSocketProtocol::encodePong($decoded['data'] ?? ''));
                } elseif ($decoded['type'] === 'close') {
                    $this->closeConnection($fd);
                } elseif ($decoded['type'] === 'message' && $this->onMessage !== null) {
                    ($this->onMessage)($connection, $decoded['data']);
                }
            }
        }

        $this->readBuffers[$fd] = $buffer;
    }

    private function doHandshake(int $fd, string $buffer): bool
    {
        if (!str_contains($buffer, "\r\n\r\n")) {
            return false;
        }

        if (!preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $buffer, $matches)) {
            $this->closeConnection($fd);
            return false;
        }

        $key = $matches[1];
        $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n";
        $response .= "\r\n";

        $this->writeBuffers[$fd] .= $response;
        return true;
    }

    private function writeToSocket($socket, int $fd): void
    {
        $buffer = $this->writeBuffers[$fd] ?? '';

        if (empty($buffer)) {
            return;
        }

        $written = @fwrite($socket, $buffer);

        if ($written === false || $written === 0) {
            $this->closeConnection($fd);
            return;
        }

        $this->writeBuffers[$fd] = substr($buffer, $written);

        if (empty($this->writeBuffers[$fd]) && $this->onBufferDrain !== null) {
            $connection = $this->connections[$fd] ?? null;
            if ($connection) {
                ($this->onBufferDrain)($connection);
            }
        }
    }

    public function sendToConnection(int $fd, string $data): void
    {
        if (!isset($this->clientSockets[$fd])) {
            return;
        }

        if (($this->transport === 'ws' || $this->transport === 'wss') && $this->wsProtocol !== null) {
            $data = WebSocketProtocol::encode($data);
        }

        $this->writeBuffers[$fd] .= $data;

        if (!empty($this->writeBuffers[$fd]) && strlen($this->writeBuffers[$fd]) > 65535 && $this->onBufferFull !== null) {
            $connection = $this->connections[$fd] ?? null;
            if ($connection) {
                ($this->onBufferFull)($connection);
            }
        }
    }

    private function closeConnection(int $fd): void
    {
        if (isset($this->clientSockets[$fd])) {
            $socket = $this->clientSockets[$fd];
            if (is_resource($socket)) {
                @fclose($socket);
            }
            unset($this->clientSockets[$fd]);
        }

        $connection = $this->connections[$fd] ?? null;

        unset($this->connections[$fd]);
        unset($this->readBuffers[$fd]);
        unset($this->writeBuffers[$fd]);
        unset($this->handshakeCompleted[$fd]);

        if ($this->onClose !== null && $connection !== null) {
            ($this->onClose)($connection);
        }
    }

    private function startUdp(): void
    {
        $parsed = $this->parseSocket($this->socket);
        $host = $parsed['host'] ?? '0.0.0.0';
        $port = $parsed['port'] ?? 9292;

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, $host, $port);
        socket_set_nonblock($socket);

        $this->serverSocket = $socket;
        $this->running = true;

        if ($this->onWorkerStart !== null) {
            ($this->onWorkerStart)($this);
        }

        echo "[UDP] {$this->name} 启动在 {$host}:{$port}\n";

        while ($this->running) {
            $read = [$socket];
            $write = null;
            $except = null;

            $numChanged = @socket_select($read, $write, $except, 1);

            if ($numChanged === false || $numChanged === 0) {
                continue;
            }

            $from = '';
            $port = 0;
            $bytes = @socket_recvfrom($socket, $data, 65535, 0, $from, $port);

            if ($bytes === false || $bytes === 0) {
                continue;
            }

            if ($this->onMessage !== null) {
                $connection = new UdpConnection($socket, $from, $port, $this);
                ($this->onMessage)($connection, $data);
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;

        if ($this->serverSocket) {
            if (is_resource($this->serverSocket)) {
                if (get_resource_type($this->serverSocket) === 'stream') {
                    fclose($this->serverSocket);
                } else {
                    socket_close($this->serverSocket);
                }
            }
            $this->serverSocket = null;
        }

        foreach ($this->clientSockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        $this->clientSockets = [];

        if ($this->server !== null) {
            $this->server->stop();
        }
    }

    public function reload(): void
    {
        if ($this->server !== null) {
            $this->server->reload();
        }
    }

    public function pause(): void
    {
        $this->running = false;
    }

    public function resume(): void
    {
        $this->running = true;
    }

    private function parseSocket(string $socket): array
    {
        $result = [];

        if (preg_match('/^(\w+):\/\/(.+):(\d+)$/i', $socket, $matches)) {
            $result['transport'] = strtolower($matches[1]);
            $result['host'] = $matches[2];
            $result['port'] = (int) $matches[3];
        }

        return $result;
    }

    public function getSocket(): string
    {
        return $this->socket;
    }

    public function getWorkerCount(): int
    {
        return $this->count;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    public function broadcast(mixed $data): void
    {
        foreach ($this->connections as $connection) {
            $connection->send($data);
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public static function setStdoutFile(string $file): void
    {
        self::$stdoutFile = $file;
    }

    public static function setPidFile(string $file): void
    {
        self::$pidFile = $file;
    }

    public static function setLogFile(string $file): void
    {
        self::$logFile = $file;
    }

    public static function daemonize(bool $value = true): void
    {
        self::$daemonize = $value;
    }
}
