<?php

declare(strict_types=1);

namespace PhpLogger\Support;

use DateTimeImmutable;

/**
 * Injected everywhere time is read, so records and durations are deterministic
 * under test.
 */
interface Clock
{
    public function now(): DateTimeImmutable;

    /** Monotonic-ish reading in seconds, used for durations. */
    public function elapsedReference(): float;
}
