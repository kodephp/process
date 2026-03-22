<?php

declare(strict_types=1);

namespace Kode\Process\Async;

interface EventEmitterInterface
{
    public function on(string $event, callable $listener): self;

    public function once(string $event, callable $listener): self;

    public function off(string $event, ?callable $listener = null): self;

    public function emit(string $event, array $args = []): self;

    public function listeners(string $event): array;

    public function hasListeners(string $event): bool;

    public function removeAllListeners(?string $event = null): self;
}
