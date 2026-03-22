<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ClusterManager
{
    private static ?self $instance = null;

    private array $nodes = [];
    private ?Node $currentNode = null;
    private ?Node $masterNode = null;
    private float $heartbeatInterval = 5.0;
    private float $heartbeatTimeout = 30.0;
    private int $electTimeout = 5000;
    private bool $running = false;
    private LoggerInterface $logger;
    private array $config;
    private $socket = null;

    private function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getInstance(array $config = [], ?LoggerInterface $logger = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config, $logger);
        }

        return self::$instance;
    }

    public function init(array $config): self
    {
        $this->config = array_merge([
            'node_id' => $this->generateNodeId(),
            'host' => '0.0.0.0',
            'port' => 9500,
            'role' => Node::ROLE_WORKER,
            'nodes' => [],
            'heartbeat_interval' => 5.0,
            'heartbeat_timeout' => 30.0,
        ], $config);

        $this->heartbeatInterval = $this->config['heartbeat_interval'];
        $this->heartbeatTimeout = $this->config['heartbeat_timeout'];

        $this->currentNode = new Node(
            $this->config['node_id'],
            $this->config['host'],
            $this->config['port'],
            $this->config['role']
        );

        foreach ($this->config['nodes'] as $nodeConfig) {
            $this->addNode(Node::fromArray($nodeConfig));
        }

        return $this;
    }

    public function start(): Response
    {
        if ($this->running) {
            return Response::error('集群管理器已启动');
        }

        $this->running = true;

        $this->bindSocket();

        $this->logger->info('集群管理器已启动', [
            'node_id' => $this->currentNode?->getId(),
            'address' => $this->currentNode?->getAddress(),
        ]);

        return Response::ok([
            'node_id' => $this->currentNode?->getId(),
            'role' => $this->currentNode?->getRole(),
        ]);
    }

    public function stop(): void
    {
        $this->running = false;

        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }

        $this->logger->info('集群管理器已停止');
    }

    public function addNode(Node $node): self
    {
        $this->nodes[$node->getId()] = $node;

        if ($node->isMaster()) {
            $this->masterNode = $node;
        }

        return $this;
    }

    public function removeNode(string $nodeId): self
    {
        unset($this->nodes[$nodeId]);

        if ($this->masterNode?->getId() === $nodeId) {
            $this->masterNode = null;
        }

        return $this;
    }

    public function getNode(string $nodeId): ?Node
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function getAliveNodes(): array
    {
        return array_filter($this->nodes, fn(Node $node) => $node->isAlive());
    }

    public function getCurrentNode(): ?Node
    {
        return $this->currentNode;
    }

    public function getMasterNode(): ?Node
    {
        return $this->masterNode;
    }

    public function isMaster(): bool
    {
        return $this->currentNode?->isMaster() ?? false;
    }

    public function isWorker(): bool
    {
        return $this->currentNode?->isWorker() ?? false;
    }

    public function sendHeartbeat(): void
    {
        if ($this->currentNode === null) {
            return;
        }

        $this->currentNode->updateHeartbeat();
        $this->currentNode->setLoad($this->calculateLoad());

        $message = json_encode([
            'type' => 'heartbeat',
            'node' => $this->currentNode->toArray(),
            'time' => microtime(true),
        ]);

        foreach ($this->nodes as $node) {
            $this->sendToNode($node, $message);
        }
    }

    public function receiveHeartbeat(array $data): void
    {
        if (!isset($data['node']['id'])) {
            return;
        }

        $nodeId = $data['node']['id'];

        if (isset($this->nodes[$nodeId])) {
            $this->nodes[$nodeId]->updateHeartbeat();
            $this->nodes[$nodeId]->setLoad($data['node']['load'] ?? 0);
        } else {
            $this->addNode(Node::fromArray($data['node']));
        }

        if (isset($data['node']['role']) && $data['node']['role'] === Node::ROLE_MASTER) {
            $this->masterNode = $this->nodes[$nodeId];
        }
    }

    public function checkNodesHealth(): array
    {
        $now = microtime(true);
        $unhealthy = [];

        foreach ($this->nodes as $node) {
            $elapsed = $now - $node->getLastHeartbeat();

            if ($elapsed > $this->heartbeatTimeout) {
                $node->setAlive(false);
                $unhealthy[] = $node->getId();

                $this->logger->warning('节点不健康', [
                    'node_id' => $node->getId(),
                    'elapsed' => $elapsed,
                ]);
            }
        }

        return $unhealthy;
    }

    public function electMaster(): ?Node
    {
        $candidates = [];

        foreach ($this->nodes as $node) {
            if ($node->isAlive()) {
                $candidates[] = $node;
            }
        }

        if ($this->currentNode !== null && $this->currentNode->isAlive()) {
            $candidates[] = $this->currentNode;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (Node $a, Node $b) {
            if ($a->getLoad() !== $b->getLoad()) {
                return $a->getLoad() <=> $b->getLoad();
            }

            return strcmp($a->getId(), $b->getId());
        });

        $newMaster = $candidates[0];
        $newMaster->setRole(Node::ROLE_MASTER);
        $this->masterNode = $newMaster;

        if ($this->currentNode?->getId() === $newMaster->getId()) {
            $this->currentNode->setRole(Node::ROLE_MASTER);
        }

        $this->logger->info('选举新主节点', ['master_id' => $newMaster->getId()]);

        return $newMaster;
    }

    public function broadcast(string $event, array $data = []): void
    {
        $message = json_encode([
            'type' => 'broadcast',
            'event' => $event,
            'data' => $data,
            'from' => $this->currentNode?->getId(),
            'time' => microtime(true),
        ]);

        foreach ($this->nodes as $node) {
            $this->sendToNode($node, $message);
        }
    }

    public function sendToNode(Node $node, string $message): bool
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            return false;
        }

        socket_set_nonblock($socket);

        $result = @socket_connect($socket, $node->getHost(), $node->getPort());

        if (!$result && socket_last_error($socket) !== SOCKET_EINPROGRESS) {
            socket_close($socket);
            return false;
        }

        $sent = @socket_write($socket, $message . "\n");
        socket_close($socket);

        return $sent !== false;
    }

    public function getStatus(): array
    {
        return [
            'current_node' => $this->currentNode?->toArray(),
            'master_node' => $this->masterNode?->toArray(),
            'nodes' => array_map(fn(Node $n) => $n->toArray(), $this->nodes),
            'alive_count' => count($this->getAliveNodes()),
            'total_count' => count($this->nodes),
            'is_master' => $this->isMaster(),
        ];
    }

    public function selectNode(string $strategy = 'round_robin'): ?Node
    {
        $aliveNodes = array_values($this->getAliveNodes());

        if (empty($aliveNodes)) {
            return null;
        }

        return match ($strategy) {
            'random' => $aliveNodes[array_rand($aliveNodes)],
            'least_load' => $this->selectLeastLoadedNode($aliveNodes),
            default => $this->selectRoundRobinNode($aliveNodes),
        };
    }

    private function selectRoundRobinNode(array $nodes): Node
    {
        static $index = 0;
        $node = $nodes[$index % count($nodes)];
        $index++;
        return $node;
    }

    private function selectLeastLoadedNode(array $nodes): Node
    {
        usort($nodes, fn(Node $a, Node $b) => $a->getLoad() <=> $b->getLoad());
        return $nodes[0];
    }

    private function calculateLoad(): float
    {
        $load = 0.0;

        $load += memory_get_usage(true) / (128 * 1024 * 1024) * 0.3;

        $sysLoad = sys_getloadavg();
        $load += ($sysLoad[0] ?? 0) / 4 * 0.4;

        $processManager = GlobalProcessManager::getInstance();
        $workerCount = $processManager->getWorkerCount();
        $load += min($workerCount / 16, 1.0) * 0.3;

        return min($load, 1.0);
    }

    private function bindSocket(): void
    {
        if ($this->currentNode === null) {
            return;
        }

        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->socket === false) {
            $this->logger->error('无法创建 Socket');
            return;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        $bound = socket_bind(
            $this->socket,
            $this->currentNode->getHost(),
            $this->currentNode->getPort()
        );

        if (!$bound) {
            $this->logger->error('无法绑定 Socket', [
                'address' => $this->currentNode->getAddress(),
            ]);
            return;
        }

        socket_listen($this->socket, 128);
        socket_set_nonblock($this->socket);

        $this->logger->info('Socket 已绑定', [
            'address' => $this->currentNode->getAddress(),
        ]);
    }

    private function generateNodeId(): string
    {
        return 'node_' . substr(md5(uniqid('', true)), 0, 12);
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->stop();
        }

        self::$instance = null;
    }
}
