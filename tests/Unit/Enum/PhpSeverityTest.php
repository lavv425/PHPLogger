<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Enum;

use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Enum\PhpSeverity;
use PHPUnit\Framework\TestCase;

final class PhpSeverityTest extends TestCase
{
    public function test_names_the_known_error_constants(): void
    {
        self::assertSame('E_ERROR', PhpSeverity::name(1));
        self::assertSame('E_WARNING', PhpSeverity::name(2));
        self::assertSame('E_NOTICE', PhpSeverity::name(8));
        self::assertSame('E_USER_DEPRECATED', PhpSeverity::name(16384));
    }

    public function test_unknown_severities_get_a_placeholder_instead_of_an_error(): void
    {
        self::assertSame('E_UNKNOWN', PhpSeverity::name(999999));
        self::assertSame('E_UNKNOWN', PhpSeverity::name(0));
    }

    /** @dataProvider severities */
    public function test_maps_severity_to_level_and_outcome(int $severity, string $level, string $outcome, bool $fatal): void
    {
        self::assertSame($level, PhpSeverity::level($severity));
        self::assertSame($outcome, PhpSeverity::outcome($severity));
        self::assertSame($fatal, PhpSeverity::isFatal($severity));
    }

    /** @return array<string, array{int, string, string, bool}> */
    public function severities(): array
    {
        return [
            'E_ERROR' => [1, Level::CRITICAL, Outcome::FAILURE, true],
            'E_PARSE' => [4, Level::CRITICAL, Outcome::FAILURE, true],
            'E_CORE_ERROR' => [16, Level::CRITICAL, Outcome::FAILURE, true],
            'E_COMPILE_ERROR' => [64, Level::CRITICAL, Outcome::FAILURE, true],
            'E_RECOVERABLE_ERROR' => [4096, Level::CRITICAL, Outcome::FAILURE, true],

            'E_USER_ERROR' => [256, Level::ERROR, Outcome::FAILURE, false],

            'E_WARNING' => [2, Level::WARNING, Outcome::UNKNOWN, false],
            'E_CORE_WARNING' => [32, Level::WARNING, Outcome::UNKNOWN, false],
            'E_USER_WARNING' => [512, Level::WARNING, Outcome::UNKNOWN, false],

            'E_NOTICE' => [8, Level::NOTICE, Outcome::UNKNOWN, false],
            'E_DEPRECATED' => [8192, Level::NOTICE, Outcome::UNKNOWN, false],
            'unknown severity' => [999999, Level::NOTICE, Outcome::UNKNOWN, false],
        ];
    }

    public function test_a_warning_says_nothing_about_the_surrounding_operation(): void
    {
        // The point of the mapping: only actual errors mark the work as failed.
        self::assertSame(Outcome::UNKNOWN, PhpSeverity::outcome(2));
        self::assertSame(Outcome::UNKNOWN, PhpSeverity::outcome(8192));
        self::assertSame(Outcome::FAILURE, PhpSeverity::outcome(1));
    }
}
