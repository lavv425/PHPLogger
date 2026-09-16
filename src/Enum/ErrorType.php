<?php

declare(strict_types=1);

namespace Logger\Enum;

final class ErrorType
{
    public const EXCEPTION = 'exception';
    public const PHP_ERROR = 'php_error';
    public const TIMEOUT = 'timeout';
    public const CURL_ERROR = 'curl_error';
    public const HTTP_ERROR = 'http_error';
    public const DB_ERROR = 'db_error';
    public const OTHER = 'other';

    private function __construct()
    {
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::EXCEPTION,
            self::PHP_ERROR,
            self::TIMEOUT,
            self::CURL_ERROR,
            self::HTTP_ERROR,
            self::DB_ERROR,
            self::OTHER,
        ];
    }
}
