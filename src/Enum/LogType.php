<?php

declare(strict_types=1);

namespace Logger\Enum;

/**
 * Built-in log types. Custom payloads may declare their own value; the pipeline
 * only requires it to match Assert::NAME_PATTERN.
 */
final class LogType
{
    public const DB_QUERY = 'db_query';
    public const SERVICE_CALL = 'service_call';
    public const PHP_LOG = 'php_log';

    /** Emitted by the fail-safe path only; carries no user data by construction. */
    public const LOGGER_ERROR = 'logger_error';

    private function __construct()
    {
    }

    /** @return string[] */
    public static function all(): array
    {
        return [self::DB_QUERY, self::SERVICE_CALL, self::PHP_LOG, self::LOGGER_ERROR];
    }
}
