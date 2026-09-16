<?php

declare(strict_types=1);

namespace Logger\Enum;

/**
 * Result of the operation the event describes. UNKNOWN is for events that have
 * no outcome at all (a deprecation notice) or whose result the caller cannot
 * interpret.
 */
final class Outcome
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }

    public static function isValid(string $outcome): bool
    {
        return in_array($outcome, [self::SUCCESS, self::FAILURE, self::UNKNOWN], true);
    }

    /**
     * Convenience boolean mirrored in the JSON output. Null for UNKNOWN so it
     * never claims a failure that did not happen.
     */
    public static function toSuccessFlag(string $outcome): ?bool
    {
        if ($outcome === self::SUCCESS) {
            return true;
        }

        if ($outcome === self::FAILURE) {
            return false;
        }

        return null;
    }
}
