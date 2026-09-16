<?php

declare(strict_types=1);

namespace Logger\Enum;

/**
 * Maps PHP error bitmask values to their constant name and to a log level.
 *
 * Raw integers are used on purpose instead of the E_* constants: some of them
 * are deprecated in recent PHP versions and referencing them would emit a
 * deprecation from inside the logger.
 */
final class PhpSeverity
{
    private const NAMES = [
        1 => 'E_ERROR',
        2 => 'E_WARNING',
        4 => 'E_PARSE',
        8 => 'E_NOTICE',
        16 => 'E_CORE_ERROR',
        32 => 'E_CORE_WARNING',
        64 => 'E_COMPILE_ERROR',
        128 => 'E_COMPILE_WARNING',
        256 => 'E_USER_ERROR',
        512 => 'E_USER_WARNING',
        1024 => 'E_USER_NOTICE',
        2048 => 'E_STRICT',
        4096 => 'E_RECOVERABLE_ERROR',
        8192 => 'E_DEPRECATED',
        16384 => 'E_USER_DEPRECATED',
    ];

    private const FATAL = [1, 4, 16, 64, 4096];
    private const ERRORS = [256];
    private const WARNINGS = [2, 32, 128, 512];

    private function __construct()
    {
    }

    public static function name(int $severity): string
    {
        return self::NAMES[$severity] ?? 'E_UNKNOWN';
    }

    public static function isFatal(int $severity): bool
    {
        return in_array($severity, self::FATAL, true);
    }

    public static function level(int $severity): string
    {
        if (self::isFatal($severity)) {
            return Level::CRITICAL;
        }

        if (in_array($severity, self::ERRORS, true)) {
            return Level::ERROR;
        }

        if (in_array($severity, self::WARNINGS, true)) {
            return Level::WARNING;
        }

        return Level::NOTICE;
    }

    /**
     * Only actual errors are a failed operation. A warning or a deprecation
     * says nothing about the outcome of the surrounding work.
     */
    public static function outcome(int $severity): string
    {
        if (self::isFatal($severity) || in_array($severity, self::ERRORS, true)) {
            return Outcome::FAILURE;
        }

        return Outcome::UNKNOWN;
    }
}
