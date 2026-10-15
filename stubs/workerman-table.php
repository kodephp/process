<?php

// Workerman v4 Table 符号桩（phpstan 静态分析用；运行期由 workerman/workerman v4 提供，
// v5 已移除该类，GlobalData WorkermanTable 仅在用户装 v4 时可用）

namespace Workerman;

class Table
{
    public const TYPE_STRING = 1;
    public const TYPE_INT = 2;
    public const TYPE_FLOAT = 3;
    public const TYPE_DOUBLE = 4;

    public function __construct(int $size = 0, ?int $columns = null, float $memoryLimit = 0.0) {}

    public function column(string $name, int $type, int $size = 0): bool {}

    public function create(): bool {}

    public function set(string $key, mixed $row, float $ttl = 0.0): bool {}

    public function get(string $key, mixed $column = null): mixed {}

    public function del(string $key): bool {}

    public function count(): int {}

    public function inc(string $key, string $column, int $by = 1): mixed {}

    public function dec(string $key, string $column, int $by = 1): mixed {}

    public function destroy(): void {}
}
