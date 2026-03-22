<?php

declare(strict_types=1);

namespace Kode\Process;

/**
 * PHP 版本特性检测
 * 
 * 提供跨版本兼容性支持
 */
final class PhpCompat
{
    public static function version(): string
    {
        return PHP_VERSION;
    }

    public static function versionId(): int
    {
        return PHP_VERSION_ID;
    }

    public static function isPhp81(): bool
    {
        return PHP_VERSION_ID >= 80100 && PHP_VERSION_ID < 80200;
    }

    public static function isPhp82(): bool
    {
        return PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300;
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

    public static function hasPipeOperator(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasCloneWith(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasUriExtension(): bool
    {
        return PHP_VERSION_ID >= 80500 && extension_loaded('uri');
    }

    public static function hasNoDiscardAttribute(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasPersistentCurlShare(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasConstExprClosures(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasArrayFind(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasFpow(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function hasFiberLocal(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    public static function hasReadonlyProperties(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasEnums(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasAttributes(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasMatchExpression(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasNamedArguments(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasUnionTypes(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasIntersectionTypes(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasNeverType(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasVoidType(): bool
    {
        return PHP_VERSION_ID >= 70100;
    }

    public static function hasMixedType(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasStaticReturnType(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasThrowExpression(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasNullsafeOperator(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasWeakMaps(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasStringableInterface(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function hasClosuresFromCallable(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasFirstClassCallables(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasNewInInitializers(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function hasReadonlyClasses(): bool
    {
        return PHP_VERSION_ID >= 80200;
    }

    public static function hasDisjunctiveNormalFormTypes(): bool
    {
        return PHP_VERSION_ID >= 80200;
    }

    public static function hasConstantsInTraits(): bool
    {
        return PHP_VERSION_ID >= 80200;
    }

    public static function hasDeprecatedDynamicProperties(): bool
    {
        return PHP_VERSION_ID >= 80200;
    }

    public static function hasRandomExtension(): bool
    {
        return PHP_VERSION_ID >= 80200 && extension_loaded('random');
    }

    public static function hasTypedClassConstants(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    public static function hasDynamicClassConstantFetch(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    public static function hasJsonValidate(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    public static function hasOverrideAttribute(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    public static function hasDeepCloningOfReadonlyProperties(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    public static function supportsPipe(): bool
    {
        return self::hasPipeOperator();
    }

    public static function arrayFind(array $array, callable $callback): mixed
    {
        if (self::hasArrayFind()) {
            return \array_find($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }

    public static function arrayFindKey(array $array, callable $callback): int|string|null
    {
        if (self::hasArrayFind()) {
            return \array_find_key($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }

    public static function arrayAny(array $array, callable $callback): bool
    {
        if (self::hasArrayFind()) {
            return \array_any($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    public static function arrayAll(array $array, callable $callback): bool
    {
        if (self::hasArrayFind()) {
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
        if (self::hasFpow()) {
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
}
