<?php

declare(strict_types=1);

namespace PhpLogger\Enum;

final class HttpMethod
{
    public const GET = 'GET';
    public const POST = 'POST';
    public const PUT = 'PUT';
    public const PATCH = 'PATCH';
    public const DELETE = 'DELETE';
    public const HEAD = 'HEAD';
    public const OPTIONS = 'OPTIONS';

    private function __construct()
    {
    }

    public static function isValid(string $method): bool
    {
        return in_array($method, self::all(), true);
    }

    /** @return string[] */
    public static function all(): array
    {
        return [self::GET, self::POST, self::PUT, self::PATCH, self::DELETE, self::HEAD, self::OPTIONS];
    }

    public static function normalize(string $method): string
    {
        return strtoupper(trim($method));
    }
}
