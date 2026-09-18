<?php

declare(strict_types=1);

namespace Logger\Support;

use DateTimeImmutable;
use DateTimeZone;
use Logger\Interfaces\Support\ClockInterface;

final class SystemClock implements ClockInterface
{
    private DateTimeZone $timezone;

    public function __construct(?DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        // microtime() keeps the sub-second precision that "now" would drop.
        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)), $utc);

        if ($parsed === false) {
            return (new DateTimeImmutable('now', $utc))->setTimezone($this->timezone);
        }

        return $parsed->setTimezone($this->timezone);
    }

    public function elapsedReference(): float
    {
        return hrtime(true) / 1e9;
    }
}
