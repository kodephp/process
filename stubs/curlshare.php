<?php

// PHP 内置 CurlShare 符号桩（phpstan stub 缺失时兜底；运行期由 PHP 核心提供）

class CurlShare
{
    public function __construct() {}

    public function setopt(int $option, mixed $value): bool {}

    public function getopt(int $option): mixed {}

    public function close(): void {}
}
