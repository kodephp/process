<?php

declare(strict_types=1);

namespace Kode\Process\Cluster;

use Kode\Process\Response;

interface NodeInterface
{
    public function getId(): string;

    public function getHost(): string;

    public function getPort(): int;

    public function getRole(): string;

    public function isAlive(): bool;

    public function getLastHeartbeat(): float;

    public function getLoad(): float;

    public function getMetadata(): array;

    public function toArray(): array;
}
