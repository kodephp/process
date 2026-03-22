<?php

declare(strict_types=1);

namespace Kode\Process;

use Kode\Process\Exceptions\ProcessException;
use Kode\Process\Integration\IntegrationManager;
use Kode\Process\Signal\SignalDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Application
{
    public static function version(): string
    {
        return Version::get();
    }

    private static ?self $instance = null;

    private array $workers = [];
    private array $listeners = [];
    private array $config = [];
    private ?LoggerInterface $logger = null;
    private ?GlobalProcessManager $processManager = null;
    private ?SignalDispatcher $signalDispatcher = null;
    private bool $started = false;
    private bool $daemonized = false;
    private string $pidFile = '';
    private string $logFile = '';
    private array $bootstraps = [];
    private array $services = [];
    private ?IntegrationManager $integration = null;
    private array $clusterConfig = [];

    private function __construct()
    {
        $this->processManager = GlobalProcessManager::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function create(array $config = []): self
    {
        return self::getInstance()->configure($config);
    }

    public function configure(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        if (isset($config['logger'])) {
            $this->logger = $config['logger'];
        }

        if (isset($config['pid_file'])) {
            $this->pidFile = $config['pid_file'];
        }

        if (isset($config['log_file'])) {
            $this->logFile = $config['log_file'];
        }

        if (isset($config['daemonize'])) {
            $this->daemonized = (bool) $config['daemonize'];
        }

        if (isset($config['cluster'])) {
            $this->clusterConfig = $config['cluster'];
        }

        return $this;
    }

    public function listen(string $address, array $options = []): self
    {
        $parsed = $this->parseAddress($address);

        $listener = [
            'address' => $address,
            'protocol' => $parsed['protocol'],
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'transport' => $parsed['transport'],
            'options' => $options,
            'worker_count' => $options['count'] ?? $this->config['worker_count'] ?? 4,
            'callbacks' => [],
        ];

        $this->listeners[$address] = $listener;

        return $this;
    }

    public function http(string $address = 'http://0.0.0.0:8080', array $options = []): self
    {
        return $this->listen($address, $options);
    }

    public function websocket(string $address = 'websocket://0.0.0.0:8081', array $options = []): self
    {
        return $this->listen($address, $options);
    }

    public function tcp(string $address = 'tcp://0.0.0.0:9000', array $options = []): self
    {
        return $this->listen($address, $options);
    }

    public function text(string $address = 'text://0.0.0.0:9001', array $options = []): self
    {
        return $this->listen($address, $options);
    }

    public function on(string $event, callable $callback): self
    {
        $lastListener = array_key_last($this->listeners);

        if ($lastListener !== null) {
            $this->listeners[$lastListener]['callbacks'][$event] = $callback;
        }

        return $this;
    }

    public function onMessage(callable $callback): self
    {
        return $this->on('message', $callback);
    }

    public function onConnect(callable $callback): self
    {
        return $this->on('connect', $callback);
    }

    public function onClose(callable $callback): self
    {
        return $this->on('close', $callback);
    }

    public function onWorkerStart(callable $callback): self
    {
        return $this->on('worker_start', $callback);
    }

    public function onWorkerStop(callable $callback): self
    {
        return $this->on('worker_stop', $callback);
    }

    public function onMasterStart(callable $callback): self
    {
        $this->bootstraps['master_start'] = $callback;
        return $this;
    }

    public function onMasterStop(callable $callback): self
    {
        $this->bootstraps['master_stop'] = $callback;
        return $this;
    }

    public function onReload(callable $callback): self
    {
        $this->bootstraps['reload'] = $callback;
        return $this;
    }

    public function bootstrap(callable $callback): self
    {
        $this->bootstraps['bootstrap'] = $callback;
        return $this;
    }

    public function withFramework(?string $framework = null, array $config = []): self
    {
        $this->integration = IntegrationManager::getInstance();

        if ($framework !== null) {
            $this->integration->boot($framework, $config);
        } else {
            $this->integration->boot();
        }

        return $this;
    }

    public function cluster(array $config): self
    {
        $this->clusterConfig = array_merge([
            'enabled' => true,
            'nodes' => [],
            'discovery' => 'static',
            'heartbeat' => 5,
            'failover' => true,
        ], $config);

        return $this;
    }

    public function count(int $count): self
    {
        $this->config['worker_count'] = $count;
        return $this;
    }

    public function daemon(bool $enable = true): self
    {
        $this->daemonized = $enable;
        return $this;
    }

    public function pidFile(string $path): self
    {
        $this->pidFile = $path;
        return $this;
    }

    public function logFile(string $path): self
    {
        $this->logFile = $path;
        return $this;
    }

    public function name(string $name): self
    {
        $this->config['name'] = $name;
        return $this;
    }

    public function user(string $user, ?string $group = null): self
    {
        $this->config['user'] = $user;
        if ($group !== null) {
            $this->config['group'] = $group;
        }
        return $this;
    }

    public function maxRequest(int $count): self
    {
        $this->config['max_requests'] = $count;
        return $this;
    }

    public function maxMemory(int $bytes): self
    {
        $this->config['max_memory'] = $bytes;
        return $this;
    }

    public function service(string $name, object $service): self
    {
        $this->services[$name] = $service;
        $this->processManager->registerService($name, $service);
        return $this;
    }

    public function start(): void
    {
        if ($this->started) {
            throw new ProcessException('应用已启动');
        }

        $this->started = true;

        $this->initialize();

        if ($this->daemonized) {
            $this->daemonize();
        }

        $this->setupSignals();

        $this->processManager->setMasterPid(Process::getPid());
        $this->processManager->setState(GlobalProcessManager::STATE_STARTING);

        if (isset($this->bootstraps['master_start'])) {
            ($this->bootstraps['master_start'])($this);
        }

        $this->processManager->setState(GlobalProcessManager::STATE_RUNNING);

        $this->runWorkers();
    }

    public function stop(bool $graceful = true): void
    {
        if (!$this->started) {
            return;
        }

        $this->processManager->setState(GlobalProcessManager::STATE_STOPPING);

        if (isset($this->bootstraps['master_stop'])) {
            ($this->bootstraps['master_stop'])($this);
        }

        foreach ($this->workers as $worker) {
            $worker->stop();
        }

        $this->processManager->setState(GlobalProcessManager::STATE_STOPPED);
        $this->started = false;
    }

    public function reload(): void
    {
        if (isset($this->bootstraps['reload'])) {
            ($this->bootstraps['reload'])($this);
        }

        foreach ($this->workers as $worker) {
            $worker->reload();
        }
    }

    public function restart(): void
    {
        $this->stop(true);
        usleep(100000);
        $this->start();
    }

    public function getStatus(): array
    {
        return [
            'version' => Version::get(),
            'started' => $this->started,
            'daemonized' => $this->daemonized,
            'listeners' => count($this->listeners),
            'workers' => count($this->workers),
            'process_manager' => $this->processManager->getStatus(),
            'cluster' => $this->clusterConfig,
            'memory' => [
                'usage' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
            ],
        ];
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function getProcessManager(): GlobalProcessManager
    {
        return $this->processManager;
    }

    public function getIntegration(): ?IntegrationManager
    {
        return $this->integration;
    }

    public function getListeners(): array
    {
        return $this->listeners;
    }

    public static function run(): void
    {
        self::getInstance()->start();
    }

    public static function shutdown(bool $graceful = true): void
    {
        self::getInstance()->stop($graceful);
    }

    private function initialize(): void
    {
        if ($this->logger === null) {
            $this->logger = new NullLogger();
        }

        if (!empty($this->clusterConfig['enabled'])) {
            $this->initializeCluster();
        }

        if (isset($this->bootstraps['bootstrap'])) {
            ($this->bootstraps['bootstrap'])($this);
        }
    }

    private function initializeCluster(): void
    {
        $nodes = $this->clusterConfig['nodes'] ?? [];

        foreach ($nodes as $node) {
            $this->processManager->setShared("cluster.node.{$node['id']}", $node);
        }

        $this->logger?->info('集群初始化完成', ['nodes' => count($nodes)]);
    }

    private function setupSignals(): void
    {
        $this->signalDispatcher = new SignalDispatcher($this->logger);

        $app = $this;

        $this->signalDispatcher->on(Signal::TERM, function () use ($app) {
            $app->getLogger()->info('收到 SIGTERM，准备停止');
            $app->stop(true);
        });

        $this->signalDispatcher->on(Signal::INT, function () use ($app) {
            $app->getLogger()->info('收到 SIGINT，准备停止');
            $app->stop(true);
        });

        $this->signalDispatcher->on(Signal::QUIT, function () use ($app) {
            $app->getLogger()->info('收到 SIGQUIT，强制停止');
            $app->stop(false);
        });

        $this->signalDispatcher->on(Signal::HUP, function () use ($app) {
            $app->getLogger()->info('收到 SIGHUP，重新加载');
            $app->reload();
        });

        $this->signalDispatcher->on(Signal::USR1, function () use ($app) {
            $app->getLogger()->info('收到 SIGUSR1，重新加载');
            $app->reload();
        });

        $this->signalDispatcher->on(Signal::USR2, function () use ($app) {
            $app->getLogger()->info('收到 SIGUSR2，打印状态');
            print_r($app->getStatus());
        });
    }

    private function runWorkers(): void
    {
        $workerId = 0;

        foreach ($this->listeners as $address => $listener) {
            $workerCount = $listener['worker_count'] ?? $this->config['worker_count'] ?? 4;

            for ($i = 0; $i < $workerCount; $i++) {
                $worker = $this->createWorker(++$workerId, $listener);
                $this->workers[$workerId] = $worker;
                $worker->start();
            }
        }
    }

    private function createWorker(int $id, array $listener): Worker\WorkerProcess
    {
        $worker = new Worker\WorkerProcess($id, $this->logger);

        $worker->setMaxRequests($this->config['max_requests'] ?? 10000);
        $worker->setMaxMemory($this->config['max_memory'] ?? 128 * 1024 * 1024);

        if (isset($listener['callbacks']['message'])) {
            $worker->setCallback(function ($taskId, $data) use ($listener) {
                return ($listener['callbacks']['message'])($taskId, $data);
            });
        }

        return $worker;
    }

    private function daemonize(): void
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            throw new ProcessException('无法创建守护进程');
        }

        if ($pid > 0) {
            exit(0);
        }

        posix_setsid();

        $pid = pcntl_fork();

        if ($pid < 0) {
            throw new ProcessException('无法创建守护进程');
        }

        if ($pid > 0) {
            exit(0);
        }

        umask(0);

        if ($this->pidFile) {
            file_put_contents($this->pidFile, getmypid());
        }

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdin = fopen('/dev/null', 'r');
        $stdout = $this->logFile ? fopen($this->logFile, 'a') : fopen('/dev/null', 'a');
        $stderr = $this->logFile ? fopen($this->logFile, 'a') : fopen('/dev/null', 'a');
    }

    private function parseAddress(string $address): array
    {
        $defaults = [
            'protocol' => 'tcp',
            'transport' => 'tcp',
            'host' => '0.0.0.0',
            'port' => 8080,
        ];

        if (preg_match('/^(\w+):\/\/([^:]+):(\d+)$/', $address, $matches)) {
            $scheme = strtolower($matches[1]);
            $host = $matches[2];
            $port = (int) $matches[3];

            $protocol = $this->schemeToProtocol($scheme);
            $transport = $this->schemeToTransport($scheme);

            return [
                'protocol' => $protocol,
                'transport' => $transport,
                'host' => $host,
                'port' => $port,
            ];
        }

        return $defaults;
    }

    private function schemeToProtocol(string $scheme): string
    {
        return match ($scheme) {
            'http', 'https' => 'http',
            'ws', 'wss', 'websocket' => 'websocket',
            'text' => 'text',
            default => 'tcp',
        };
    }

    private function schemeToTransport(string $scheme): string
    {
        return match ($scheme) {
            'https', 'wss' => 'ssl',
            'udp' => 'udp',
            default => 'tcp',
        };
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->stop();
            self::$instance->processManager->reset();
        }

        self::$instance = null;
    }
}
