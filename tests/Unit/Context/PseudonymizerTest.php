<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Context;

use Logger\Context\Pseudonymizer;
use PHPUnit\Framework\TestCase;

final class PseudonymizerTest extends TestCase
{
    public function test_is_disabled_without_a_pepper(): void
    {
        $pseudonymizer = new Pseudonymizer('');

        self::assertFalse($pseudonymizer->isEnabled());
        self::assertNull($pseudonymizer->pseudonymize('user-42'), 'the raw value must never be emitted by accident');
    }

    public function test_is_enabled_with_a_pepper(): void
    {
        self::assertTrue((new Pseudonymizer('s3cr3t'))->isEnabled());
    }

    public function test_never_returns_the_input(): void
    {
        $pseudonym = (new Pseudonymizer('s3cr3t'))->pseudonymize('user-42');

        self::assertIsString($pseudonym);
        self::assertStringNotContainsString('user-42', $pseudonym);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $pseudonym);
    }

    public function test_is_stable_so_correlation_keeps_working(): void
    {
        $pseudonymizer = new Pseudonymizer('s3cr3t');

        self::assertSame($pseudonymizer->pseudonymize('user-42'), $pseudonymizer->pseudonymize('user-42'));
    }

    public function test_different_values_get_different_pseudonyms(): void
    {
        $pseudonymizer = new Pseudonymizer('s3cr3t');

        self::assertNotSame($pseudonymizer->pseudonymize('user-42'), $pseudonymizer->pseudonymize('user-43'));
    }

    public function test_a_different_pepper_produces_a_different_pseudonym(): void
    {
        // Rotating the pepper must break replay against the application.
        self::assertNotSame(
            (new Pseudonymizer('pepper-a'))->pseudonymize('user-42'),
            (new Pseudonymizer('pepper-b'))->pseudonymize('user-42')
        );
    }

    public function test_null_and_empty_values_produce_nothing(): void
    {
        $pseudonymizer = new Pseudonymizer('s3cr3t');

        self::assertNull($pseudonymizer->pseudonymize(null));
        self::assertNull($pseudonymizer->pseudonymize(''));
    }

    /** @dataProvider lengths */
    public function test_the_length_is_clamped_to_a_sane_range(int $requested, int $expected): void
    {
        $pseudonym = (new Pseudonymizer('s3cr3t', $requested))->pseudonymize('user-42');

        self::assertSame($expected, strlen((string) $pseudonym));
    }

    /** @return array<string, array{int, int}> */
    public function lengths(): array
    {
        return [
            'below the floor' => [2, 8],
            'at the floor' => [8, 8],
            'in range' => [32, 32],
            'at the ceiling' => [64, 64],
            'above the ceiling' => [128, 64],
        ];
    }
}
