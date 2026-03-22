<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

final class Node implements NodeInterface
{
    public const ROLE_MASTER = 'master';
    public const ROLE_WORKER = 'worker';
    public const ROLE_CANDIDATE = 'candidate';

    private string $id;
    private string $host;
    private int $port;
    private string $role;
    private float $lastHeartbeat;
    private float $load;
    private array $metadata;
    private bool $alive;

    public function __construct(
        string $id,
        string $host,
        int $port,
        string $role = self::ROLE_WORKER,
        array $metadata = []
    ) {
        $this->id = $id;
        $this->host = $host;
        $this->port = $port;
        $this->role = $role;
        $this->metadata = $metadata;
        $this->lastHeartbeat = microtime(true);
        $this->load = 0.0;
        $this->alive = true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getAddress(): string
    {
        return "{$this->host}:{$this->port}";
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function isMaster(): bool
    {
        return $this->role === self::ROLE_MASTER;
    }

    public function isWorker(): bool
    {
        return $this->role === self::ROLE_WORKER;
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }

    public function setAlive(bool $alive): self
    {
        $this->alive = $alive;
        return $this;
    }

    public function getLastHeartbeat(): float
    {
        return $this->lastHeartbeat;
    }

    public function updateHeartbeat(): self
    {
        $this->lastHeartbeat = microtime(true);
        $this->alive = true;
        return $this;
    }

    public function getLoad(): float
    {
        return $this->load;
    }

    public function setLoad(float $load): self
    {
        $this->load = max(0.0, min(1.0, $load));
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'port' => $this->port,
            'address' => $this->getAddress(),
            'role' => $this->role,
            'alive' => $this->alive,
            'load' => $this->load,
            'last_heartbeat' => $this->lastHeartbeat,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        $node = new self(
            $data['id'],
            $data['host'],
            $data['port'],
            $data['role'] ?? self::ROLE_WORKER,
            $data['metadata'] ?? []
        );

        if (isset($data['load'])) {
            $node->setLoad($data['load']);
        }

        if (isset($data['alive'])) {
            $node->setAlive($data['alive']);
        }

        return $node;
    }
}
