<?php

declare(strict_types=1);

namespace Kode\Process;

/**
 * PHP 版本能力探测与前向兼容垫片
 *
 * 自 3.0.0 起最低要求 PHP 8.3，因此 8.0 - 8.3 的语言特性一律视为可用，
 * 相关的恒真探测方法已全部移除。本类只处理 8.4 / 8.5 及以后的前瞻特性。
 *
 * 能力探测优先使用 function_exists()/extension_loaded() 而非版本号比较：
 * 版本号只能表达「某版本起应当存在」，而实际运行环境可能禁用了对应函数。
 */
final class PhpCompat
{
    private static mixed $curlShareHandle = null;

    public static function version(): string
    {
        return PHP_VERSION;
    }

    public static function versionId(): int
    {
        return PHP_VERSION_ID;
    }

    public static function isPhp83(): bool
    {
        return PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400;
    }

    public static function isPhp84(): bool
    {
        return PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500;
    }

    public static function isPhp85(): bool
    {
        return PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600;
    }

    public static function isPhp86(): bool
    {
        return PHP_VERSION_ID >= 80600;
    }

    public static function hasPropertyHooks(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    public static function hasAsymmetricVisibility(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    public static function hasLazyObjects(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    public static function hasPipeOperator(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasCloneWith(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasConstExprClosures(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasNoDiscardAttribute(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasUriExtension(): bool
    {
        return extension_loaded('uri');
    }

    public static function hasPersistentCurlShare(): bool
    {
        return extension_loaded('curl') && class_exists('CURLShare', false);
    }

    /**
     * array_find 系列函数是否原生可用
     */
    public static function hasArrayFind(): bool
    {
        return function_exists('array_find');
    }

    public static function hasFpow(): bool
    {
        return function_exists('fpow');
    }

    public static function supportsPipe(): bool
    {
        return self::hasPipeOperator();
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function arrayFind(array $array, callable $callback): mixed
    {
        if (function_exists('array_find')) {
            return \array_find($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function arrayFindKey(array $array, callable $callback): int|string|null
    {
        if (function_exists('array_find_key')) {
            return \array_find_key($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function arrayAny(array $array, callable $callback): bool
    {
        if (function_exists('array_any')) {
            return \array_any($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function arrayAll(array $array, callable $callback): bool
    {
        if (function_exists('array_all')) {
            return \array_all($array, $callback);
        }

        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    public static function fpow(float $base, float $exponent): float
    {
        if (function_exists('fpow')) {
            return \fpow($base, $exponent);
        }

        return $base ** $exponent;
    }

    public static function pipe(mixed $value, callable ...$callbacks): mixed
    {
        foreach ($callbacks as $callback) {
            $value = $callback($value);
        }

        return $value;
    }

    public static function createPipe(callable ...$callbacks): \Closure
    {
        return static function (mixed $value) use ($callbacks): mixed {
            foreach ($callbacks as $callback) {
                $value = $callback($value);
            }

            return $value;
        };
    }

    public static function enableCurlShare(): mixed
    {
        if (!self::hasPersistentCurlShare()) {
            return null;
        }

        if (self::$curlShareHandle === null) {
            $class = 'CURLShare';
            $handle = new $class(constant('CURLSHARE_NONE'));
            $handle->setopt(\CURLSHOPT_SHARE, constant('CURL_LOCK_DATA_COOKIE'));
            $handle->setopt(\CURLSHOPT_SHARE, constant('CURL_LOCK_DATA_DNS'));
            $handle->setopt(\CURLSHOPT_SHARE, constant('CURL_LOCK_DATA_SSL_SESSION'));
            self::$curlShareHandle = $handle;
        }

        return self::$curlShareHandle;
    }

    public static function disableCurlShare(): void
    {
        if (self::$curlShareHandle !== null) {
            self::$curlShareHandle->close();
            self::$curlShareHandle = null;
        }
    }
}
