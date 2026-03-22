<?php

declare(strict_types=1);

namespace Kode\Process\Task;

/**
 * 异步任务处理器
 * 
 * 用于处理繁重的业务任务，避免阻塞主进程
 */
final class AsyncTask
{
    private string $host;
    private int $port;
    private int $workerCount;
    private array $workers = [];
    private bool $running = false;
    private $socket = null;
    private array $taskQueue = [];
    private array $callbacks = [];
    private int $taskId = 0;

    public function __construct(string $address = '0.0.0.0:12345', int $workers = 10)
    {
        $parts = explode(':', $address);
        $this->host = $parts[0] ?? '0.0.0.0';
        $this->port = (int)($parts[1] ?? 12345);
        $this->workerCount = $workers;
    }

    public function onTask(callable $callback): self
    {
        $this->callbacks['task'] = $callback;
        return $this;
    }

    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->socket === false) {
            throw new \RuntimeException('创建 Socket 失败');
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, $this->host, $this->port);
        socket_listen($this->socket, 128);
        socket_set_nonblock($this->socket);

        $this->running = true;
        echo "[TaskWorker] 启动 {$this->host}:{$this->port}，工作进程数: {$this->workerCount}\n";

        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->spawnWorker();
        }

        $this->loop();
    }

    private function spawnWorker(): void
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            return;
        }

        if ($pid === 0) {
            $this->runWorker();
            exit(0);
        }

        $this->workers[$pid] = true;
    }

    private function runWorker(): void
    {
        $worker = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_connect($worker, $this->host, $this->port);

        while ($this->running) {
            $data = @socket_read($worker, 65535);

            if ($data === false || empty($data)) {
                usleep(10000);
                continue;
            }

            $task = json_decode($data, true);

            if ($task === null) {
                continue;
            }

            $result = $this->executeTask($task);

            socket_write($worker, json_encode([
                'task_id' => $task['id'] ?? 0,
                'result' => $result
            ]));
        }
    }

    private function executeTask(array $task): mixed
    {
        if (!isset($this->callbacks['task'])) {
            return null;
        }

        try {
            return ($this->callbacks['task'])($task['data'] ?? [], $task['type'] ?? 'default');
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function loop(): void
    {
        while ($this->running) {
            $client = @socket_accept($this->socket);

            if ($client !== false) {
                $this->handleClient($client);
            }

            $this->checkWorkers();
            usleep(1000);
        }
    }

    private function handleClient($client): void
    {
        socket_set_nonblock($client);
        $buffer = '';

        while (true) {
            $chunk = @socket_read($client, 65535);

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

                    if ($data !== null) {
                        $this->dispatchTask($client, $data);
                    }
                }
            }
        }
    }

    private function dispatchTask($client, array $data): void
    {
        $taskId = ++$this->taskId;
        $task = [
            'id' => $taskId,
            'type' => $data['type'] ?? 'default',
            'data' => $data['data'] ?? $data
        ];

        $message = json_encode($task) . "\n";
        @socket_write($client, $message);
    }

    private function checkWorkers(): void
    {
        $status = null;
        $pid = pcntl_wait($status, WNOHANG);

        if ($pid > 0) {
            unset($this->workers[$pid]);
            $this->spawnWorker();
        }
    }

    public function stop(): void
    {
        $this->running = false;

        foreach ($this->workers as $pid => $true) {
            posix_kill($pid, SIGTERM);
        }

        if ($this->socket) {
            socket_close($this->socket);
        }
    }
}
