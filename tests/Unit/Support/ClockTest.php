<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Logger\Interfaces\Support\ClockInterface;
use Logger\Support\FixedClock;
use Logger\Support\Stopwatch;
use Logger\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_both_clocks_implement_the_contract(): void
    {
        self::assertInstanceOf(ClockInterface::class, new SystemClock());
        self::assertInstanceOf(ClockInterface::class, new FixedClock(new DateTimeImmutable('@0')));
    }

    public function test_fixed_clock_does_not_move_on_its_own(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-03-01T10:00:00+00:00'), 100.0);

        self::assertSame('2024-03-01T10:00:00+00:00', $clock->now()->format('c'));
        self::assertSame('2024-03-01T10:00:00+00:00', $clock->now()->format('c'));
        self::assertSame(100.0, $clock->elapsedReference());
    }

    public function test_fixed_clock_advances_both_readings_together(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-03-01T10:00:00+00:00'), 100.0);

        $clock->advance(90.0);

        self::assertSame('2024-03-01T10:01:30+00:00', $clock->now()->format('c'));
        self::assertSame(190.0, $clock->elapsedReference());
    }

    public function test_system_clock_keeps_sub_second_precision(): void
    {
        $now = (new SystemClock())->now();

        // "now" as a plain string would floor to the second; the microsecond
        // field is what makes records orderable within a request.
        self::assertNotSame('000000', $now->format('u'));
        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function test_system_clock_honours_the_requested_timezone(): void
    {
        $now = (new SystemClock(new DateTimeZone('Europe/Rome')))->now();

        self::assertSame('Europe/Rome', $now->getTimezone()->getName());
    }

    public function test_system_clock_elapsed_reference_only_moves_forward(): void
    {
        $clock = new SystemClock();

        $first = $clock->elapsedReference();
        $second = $clock->elapsedReference();

        self::assertGreaterThanOrEqual($first, $second);
    }

    public function test_stopwatch_measures_against_the_injected_clock(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-03-01T10:00:00+00:00'), 10.0);
        $stopwatch = Stopwatch::start($clock);

        self::assertSame(0.0, $stopwatch->elapsed());

        $clock->advance(2.5);

        self::assertSame(2.5, $stopwatch->elapsed());
    }

    public function test_stopwatch_rounds_to_microseconds(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('@0'), 0.0);
        $stopwatch = Stopwatch::start($clock);

        $clock->advance(0.12345678);

        self::assertSame(0.123457, $stopwatch->elapsed());
    }

    public function test_stopwatch_defaults_to_the_system_clock(): void
    {
        self::assertGreaterThanOrEqual(0.0, Stopwatch::start()->elapsed());
    }
}
