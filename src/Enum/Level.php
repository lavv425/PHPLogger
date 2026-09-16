<?php

declare(strict_types=1);

namespace PhpLogger\Enum;

use InvalidArgumentException;

/**
 * PSR-3 severity levels, kept as strings so the JSON output stays readable.
 */
final class Level
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    public const ALERT = 'alert';
    public const EMERGENCY = 'emergency';

    private const WEIGHTS = [
        self::DEBUG => 100,
        self::INFO => 200,
        self::NOTICE => 250,
        self::WARNING => 300,
        self::ERROR => 400,
        self::CRITICAL => 500,
        self::ALERT => 550,
        self::EMERGENCY => 600,
    ];

    private function __construct()
    {
    }

    public static function isValid(string $level): bool
    {
        return isset(self::WEIGHTS[$level]);
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::WEIGHTS);
    }

    public static function weight(string $level): int
    {
        if (!isset(self::WEIGHTS[$level])) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $level));
        }

        return self::WEIGHTS[$level];
    }

    public static function isAtLeast(string $level, string $minimum): bool
    {
        return self::weight($level) >= self::weight($minimum);
    }
}
