<?php

declare(strict_types=1);

namespace Kode\Process\Integration;

use Kode\Process\GlobalProcessManager;
use Kode\Process\Response;
use Kode\Process\Server;

interface FrameworkAdapterInterface
{
    public function getName(): string;

    public function getVersion(): string;

    public function isAvailable(): bool;

    public function boot(): Response;

    public function register(array $config = []): Response;

    public function getContainer(): ?object;

    public function get(string $service): ?object;

    public function has(string $service): bool;

    public function make(string $class, array $params = []): ?object;

    public function call(callable $callback, array $params = []): mixed;

    public function config(string $key, mixed $default = null): mixed;

    public function environment(): string;

    public function isDebug(): bool;

    public function log(string $level, string $message, array $context = []): void;

    public function handleError(\Throwable $e): void;

    public function terminate(): void;
}
