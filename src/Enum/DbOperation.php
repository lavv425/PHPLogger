<?php

declare(strict_types=1);

namespace PhpLogger\Enum;

final class DbOperation
{
    public const SELECT = 'SELECT';
    public const INSERT = 'INSERT';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';
    public const REPLACE = 'REPLACE';
    public const CALL = 'CALL';
    public const SHOW = 'SHOW';
    public const TRUNCATE = 'TRUNCATE';
    public const OTHER = 'OTHER';

    private const ALIASES = [
        'WITH' => self::SELECT,
        'EXEC' => self::CALL,
        'EXECUTE' => self::CALL,
        'DESCRIBE' => self::SHOW,
        'DESC' => self::SHOW,
        'EXPLAIN' => self::SHOW,
    ];

    private function __construct()
    {
    }

    public static function isValid(string $operation): bool
    {
        return in_array($operation, self::all(), true);
    }

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::SELECT,
            self::INSERT,
            self::UPDATE,
            self::DELETE,
            self::REPLACE,
            self::CALL,
            self::SHOW,
            self::TRUNCATE,
            self::OTHER,
        ];
    }

    /**
     * Derives the operation from the leading keyword. Comments and opening
     * parentheses are skipped so "/* hint *\/ (SELECT ...)" is still a SELECT.
     */
    public static function fromStatement(string $statement): string
    {
        $normalized = preg_replace('#/\*.*?\*/#s', ' ', $statement);
        $normalized = preg_replace('/--[^\r\n]*/', ' ', (string) $normalized);
        $normalized = ltrim((string) $normalized, " \t\r\n(");

        if (preg_match('/^([A-Za-z]+)/', $normalized, $matches) !== 1) {
            return self::OTHER;
        }

        $keyword = strtoupper($matches[1]);

        if (isset(self::ALIASES[$keyword])) {
            return self::ALIASES[$keyword];
        }

        return self::isValid($keyword) && $keyword !== self::OTHER ? $keyword : self::OTHER;
    }
}
