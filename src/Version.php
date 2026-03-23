<?php

declare(strict_types=1);

namespace Kode\Process;

final class Version
{
    public const MAJOR = 2;
    public const MINOR = 8;
    public const PATCH = 1;
    public const VERSION = '2.8.1';
    public const VERSION_ID = 20801;

    private static ?string $phpVersion = null;
    private static ?int $phpVersionId = null;

    public static function get(): string
    {
        return self::VERSION;
    }

    public static function getId(): int
    {
        return self::VERSION_ID;
    }

    public static function getMajor(): int
    {
        return self::MAJOR;
    }

    public static function getMinor(): int
    {
        return self::MINOR;
    }

    public static function getPatch(): int
    {
        return self::PATCH;
    }

    public static function getPhpVersion(): string
    {
        if (self::$phpVersion === null) {
            self::$phpVersion = PHP_VERSION;
        }

        return self::$phpVersion;
    }

    public static function getPhpVersionId(): int
    {
        if (self::$phpVersionId === null) {
            self::$phpVersionId = PHP_VERSION_ID;
        }

        return self::$phpVersionId;
    }

    public static function getPhpMajorVersion(): int
    {
        return (int) PHP_MAJOR_VERSION;
    }

    public static function getPhpMinorVersion(): int
    {
        return (int) PHP_MINOR_VERSION;
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

    public static function supportsFiber(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function supportsReadonly(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function supportsEnums(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function supportsAttributes(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function supportsMatch(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function supportsNamedArguments(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function supportsUnionTypes(): bool
    {
        return PHP_VERSION_ID >= 80000;
    }

    public static function supportsIntersectionTypes(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function supportsNeverType(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public static function supportsCloneWith(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function supportsPipeOperator(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function supportsUriExtension(): bool
    {
        return PHP_VERSION_ID >= 80500 && extension_loaded('uri');
    }

    public static function supportsNoDiscard(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    public static function compare(string $version): int
    {
        return version_compare(self::VERSION, $version);
    }

    public static function isGreaterThan(string $version): bool
    {
        return self::compare($version) > 0;
    }

    public static function isLessThan(string $version): bool
    {
        return self::compare($version) < 0;
    }

    public static function isEqualTo(string $version): bool
    {
        return self::compare($version) === 0;
    }

    public static function getFeatures(): array
    {
        return [
            'fiber' => self::supportsFiber(),
            'readonly' => self::supportsReadonly(),
            'enums' => self::supportsEnums(),
            'attributes' => self::supportsAttributes(),
            'match' => self::supportsMatch(),
            'named_arguments' => self::supportsNamedArguments(),
            'union_types' => self::supportsUnionTypes(),
            'intersection_types' => self::supportsIntersectionTypes(),
            'never_type' => self::supportsNeverType(),
            'clone_with' => self::supportsCloneWith(),
            'pipe_operator' => self::supportsPipeOperator(),
            'uri_extension' => self::supportsUriExtension(),
            'no_discard' => self::supportsNoDiscard(),
        ];
    }

    public static function getInfo(): array
    {
        return [
            'version' => self::VERSION,
            'version_id' => self::VERSION_ID,
            'major' => self::MAJOR,
            'minor' => self::MINOR,
            'patch' => self::PATCH,
            'php_version' => self::getPhpVersion(),
            'php_version_id' => self::getPhpVersionId(),
            'php_major' => self::getPhpMajorVersion(),
            'php_minor' => self::getPhpMinorVersion(),
            'features' => self::getFeatures(),
        ];
    }

    public function __toString(): string
    {
        return self::VERSION;
    }
}
