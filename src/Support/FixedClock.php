<?php

declare(strict_types=1);

namespace Logger\Support;

use DateTimeImmutable;

/** Test double: time only moves when the test moves it. */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;
    private float $reference;

    public function __construct(DateTimeImmutable $now, float $reference = 0.0)
    {
        $this->now = $now;
        $this->reference = $reference;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function elapsedReference(): float
    {
        return $this->reference;
    }

    public function advance(float $seconds): void
    {
        $this->reference += $seconds;
        $this->now = $this->now->modify(sprintf('+%d seconds', (int) $seconds));
    }
}
