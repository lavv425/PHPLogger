<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Support;

use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Exception\InvalidLogEventException;
use Logger\Support\Assert;
use PHPUnit\Framework\TestCase;

final class AssertTest extends TestCase
{
    /** @dataProvider validNames */
    public function test_accepts_names_made_of_the_allowed_charset(string $name): void
    {
        self::assertSame($name, Assert::name($name, 'event'));
    }

    /** @return array<string, array{string}> */
    public function validNames(): array
    {
        return [
            'plain' => ['user_lookup'],
            'dotted' => ['billing.invoice.created'],
            'colons' => ['app:worker:tick'],
            'dashes' => ['user-lookup'],
            'digits' => ['step2'],
            'single character' => ['a'],
            'maximum length' => [str_repeat('a', 128)],
        ];
    }

    /** @dataProvider invalidNames */
    public function test_rejects_names_that_could_carry_free_form_data(string $name): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('Invalid log event field "event"');

        Assert::name($name, 'event');
    }

    /** @return array<string, array{string}> */
    public function invalidNames(): array
    {
        return [
            // The whole point of the charset: a value must not become a name,
            // because the sanitizer does not inspect names.
            'an email address' => ['user@example.com'],
            'a path' => ['/var/log/app'],
            'whitespace' => ['user lookup'],
            'newline' => ["user\nlookup"],
            'empty' => [''],
            'too long' => [str_repeat('a', 129)],
            'json' => ['{"user":1}'],
        ];
    }

    public function test_non_empty_rejects_blank_and_whitespace_only_values(): void
    {
        self::assertSame('production_db', Assert::nonEmpty('production_db', 'database'));
        self::assertSame(' padded ', Assert::nonEmpty(' padded ', 'database'), 'the value is returned unchanged');

        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('must not be empty');

        Assert::nonEmpty("  \t\n ", 'database');
    }

    public function test_non_negative_accepts_zero_and_positive_durations(): void
    {
        self::assertSame(0.0, Assert::nonNegative(0.0, 'duration'));
        self::assertSame(1.5, Assert::nonNegative(1.5, 'duration'));
    }

    /** @dataProvider invalidDurations */
    public function test_non_negative_rejects_values_that_cannot_be_a_duration(float $value): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('must be a finite, non-negative number');

        Assert::nonNegative($value, 'duration');
    }

    /** @return array<string, array{float}> */
    public function invalidDurations(): array
    {
        return [
            'negative' => [-0.001],
            'not a number' => [NAN],
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
        ];
    }

    public function test_level_and_outcome_are_validated_against_their_enums(): void
    {
        self::assertSame(Level::ERROR, Assert::level(Level::ERROR, 'level'));
        self::assertSame(Outcome::FAILURE, Assert::outcome(Outcome::FAILURE, 'outcome'));
    }

    public function test_rejects_an_unknown_level(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('unknown level "verbose"');

        Assert::level('verbose', 'level');
    }

    public function test_rejects_an_unknown_outcome(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('unknown outcome "ok"');

        Assert::outcome('ok', 'outcome');
    }
}
