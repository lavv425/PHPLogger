<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Sanitize;

use Logger\Sanitize\StatementNormalizer;
use PHPUnit\Framework\TestCase;

final class StatementNormalizerTest extends TestCase
{
    private StatementNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new StatementNormalizer();
    }

    /** @dataProvider statements */
    public function test_replaces_values_with_a_placeholder(string $statement, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($statement));
    }

    /** @return array<string, array{string, string}> */
    public function statements(): array
    {
        return [
            'single quoted literal' => [
                "SELECT * FROM users WHERE email = 'marco@example.com'",
                'SELECT * FROM users WHERE email = ?',
            ],
            'doubled quote escape' => [
                "SELECT * FROM users WHERE name = 'O''Brien'",
                'SELECT * FROM users WHERE name = ?',
            ],
            'backslash escape' => [
                "SELECT * FROM users WHERE name = 'O\\'Brien'",
                'SELECT * FROM users WHERE name = ?',
            ],
            'double quoted literal' => [
                'SELECT * FROM users WHERE email = "marco@example.com"',
                'SELECT * FROM users WHERE email = ?',
            ],
            'integer literal' => ['SELECT * FROM users WHERE id = 42', 'SELECT * FROM users WHERE id = ?'],
            'decimal literal' => ['SELECT * FROM t WHERE amount > 10.50', 'SELECT * FROM t WHERE amount > ?'],
            'hexadecimal literal' => ['SELECT * FROM t WHERE raw = 0xDEADBEEF', 'SELECT * FROM t WHERE raw = ?'],

            'block comment' => ['SELECT /* hint */ 1 FROM dual', 'SELECT ? FROM dual'],
            'line comment' => ["SELECT 1 -- secret note\nFROM dual", 'SELECT ? FROM dual'],
            'hash comment' => ["SELECT 1 # secret note\nFROM dual", 'SELECT ? FROM dual'],

            'collapses an in list' => [
                'SELECT * FROM t WHERE id IN (1, 2, 3, 4)',
                'SELECT * FROM t WHERE id IN (?)',
            ],
            'collapses a values list' => [
                "INSERT INTO t (a, b) VALUES ('x', 'y')",
                'INSERT INTO t (a, b) VALUES (?)',
            ],
            'collapses whitespace' => ["SELECT\n\t*\n  FROM   users", 'SELECT * FROM users'],
            'trims the edges' => ['   SELECT 1   ', 'SELECT ?'],
        ];
    }

    public function test_an_interpolated_statement_cannot_leak_its_values(): void
    {
        $normalized = $this->normalizer->normalize(
            "SELECT * FROM users WHERE email = 'marco@example.com' AND token = 'abc123' AND id = 42"
        );

        self::assertStringNotContainsString('marco@example.com', $normalized);
        self::assertStringNotContainsString('abc123', $normalized);
        self::assertStringNotContainsString('42', $normalized);
        self::assertSame('SELECT * FROM users WHERE email = ? AND token = ? AND id = ?', $normalized);
    }

    public function test_fingerprint_is_a_short_stable_hash(): void
    {
        $fingerprint = $this->normalizer->fingerprint('SELECT * FROM users WHERE id = ?');

        self::assertSame(16, strlen($fingerprint));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint);
        self::assertSame($fingerprint, $this->normalizer->fingerprint('SELECT * FROM users WHERE id = ?'));
    }

    public function test_statements_that_differ_only_by_value_share_a_fingerprint(): void
    {
        // This is what makes aggregation by query shape possible in a dashboard.
        $first = $this->normalizer->normalize('SELECT * FROM users WHERE id = 1');
        $second = $this->normalizer->normalize('SELECT * FROM users WHERE id = 99999');

        self::assertSame($this->normalizer->fingerprint($first), $this->normalizer->fingerprint($second));
    }

    public function test_different_shapes_get_different_fingerprints(): void
    {
        $select = $this->normalizer->normalize('SELECT * FROM users WHERE id = 1');
        $delete = $this->normalizer->normalize('DELETE FROM users WHERE id = 1');

        self::assertNotSame($this->normalizer->fingerprint($select), $this->normalizer->fingerprint($delete));
    }
}
