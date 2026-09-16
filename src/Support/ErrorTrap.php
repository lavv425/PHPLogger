<?php

declare(strict_types=1);

namespace Logger\Support;

/**
 * Runs an I/O call with warnings captured instead of raised, without the "@"
 * operator and without leaking into the application error handler.
 */
final class ErrorTrap
{
    private function __construct()
    {
    }

    /**
     * @param callable $operation
     * @param string|null $error receives the first captured warning message
     * @return mixed the operation return value
     */
    public static function run(callable $operation, ?string &$error = null)
    {
        $captured = null;

        set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
            if ($captured === null) {
                $captured = $message;
            }

            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
            $error = $captured;
        }
    }
}
