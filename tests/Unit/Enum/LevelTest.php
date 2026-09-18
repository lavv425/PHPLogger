<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Enum;

use InvalidArgumentException;
use Logger\Enum\Level;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    public function test_recognises_every_psr3_level(): void
    {
        self::assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
            Level::all()
        );

        foreach (Level::all() as $level) {
            self::assertTrue(Level::isValid($level), $level . ' should be valid');
        }
    }

    public function test_rejects_unknown_levels(): void
    {
        self::assertFalse(Level::isValid('verbose'));
        self::assertFalse(Level::isValid('INFO'), 'levels are lowercase by contract');
        self::assertFalse(Level::isValid(''));
    }

    public function test_weights_increase_with_severity(): void
    {
        $previous = 0;

        foreach (Level::all() as $level) {
            $weight = Level::weight($level);
            self::assertGreaterThan($previous, $weight, $level . ' must outrank the level before it');
            $previous = $weight;
        }
    }

    public function test_weight_of_an_unknown_level_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log level "verbose".');

        Level::weight('verbose');
    }

    /** @dataProvider thresholds */
    public function test_is_at_least_compares_severity(string $level, string $minimum, bool $expected): void
    {
        self::assertSame($expected, Level::isAtLeast($level, $minimum));
    }

    /** @return array<string, array{string, string, bool}> */
    public function thresholds(): array
    {
        return [
            'equal levels pass' => [Level::WARNING, Level::WARNING, true],
            'higher passes' => [Level::ERROR, Level::WARNING, true],
            'lower is filtered out' => [Level::INFO, Level::WARNING, false],
            'debug against the floor' => [Level::DEBUG, Level::DEBUG, true],
            'emergency clears everything' => [Level::EMERGENCY, Level::DEBUG, true],
            'notice sits between info and warning' => [Level::NOTICE, Level::INFO, true],
        ];
    }
}
