<?php

declare(strict_types=1);

namespace Logger\Support;

use Logger\Interfaces\Support\ClockInterface;

/**
 * Measures duration and nothing else. Interpreting the result of the measured
 * operation belongs to the caller or to a dedicated integration adapter: an
 * operation that did not throw is not necessarily an operation that succeeded.
 */
final class Stopwatch
{
    private ClockInterface $clock;
    private float $startedAt;

    private function __construct(ClockInterface $clock)
    {
        $this->clock = $clock;
        $this->startedAt = $clock->elapsedReference();
    }

    public static function start(?ClockInterface $clock = null): self
    {
        return new self($clock ?? new SystemClock());
    }

    /** Seconds since start, rounded to microseconds. */
    public function elapsed(): float
    {
        return round($this->clock->elapsedReference() - $this->startedAt, 6);
    }
}
