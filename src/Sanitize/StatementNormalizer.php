<?php

declare(strict_types=1);

namespace PhpLogger\Sanitize;

/**
 * Turns a SQL statement into a value-free fingerprint.
 *
 * Two goals at once: a statement built by interpolation cannot leak its values,
 * and dashboards can aggregate by hash instead of by a million distinct strings.
 *
 * Trade-off: double-quoted sequences are replaced as well. On PostgreSQL those
 * are quoted identifiers, so the fingerprint loses their names. Keeping the
 * value hidden is worth more than the readability.
 */
final class StatementNormalizer
{
    private const PLACEHOLDER = '?';

    public function normalize(string $statement): string
    {
        $normalized = preg_replace('#/\*.*?\*/#s', ' ', $statement);
        $normalized = preg_replace('/--[^\r\n]*/', ' ', (string) $normalized);
        $normalized = preg_replace('/#[^\r\n]*/', ' ', (string) $normalized);

        // String literals, including doubled and backslash escapes.
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", self::PLACEHOLDER, (string) $normalized);
        $normalized = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', self::PLACEHOLDER, (string) $normalized);

        // Numeric and hexadecimal literals.
        $normalized = preg_replace('/\b0x[0-9a-fA-F]+\b/', self::PLACEHOLDER, (string) $normalized);
        $normalized = preg_replace('/\b\d+(?:\.\d+)?\b/', self::PLACEHOLDER, (string) $normalized);

        // Collapse placeholder lists so IN (?, ?, ?) aggregates as one shape.
        $normalized = preg_replace(
            '/\(\s*\?(?:\s*,\s*\?)+\s*\)/',
            '(' . self::PLACEHOLDER . ')',
            (string) $normalized
        );

        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);

        return trim((string) $normalized);
    }

    public function fingerprint(string $normalizedStatement): string
    {
        return substr(hash('sha256', $normalizedStatement), 0, 16);
    }
}
