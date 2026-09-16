<?php

declare(strict_types=1);

namespace PhpLogger;

use PhpLogger\Contract\LogPayload;

interface LoggerInterface
{
    /**
     * Writes an event.
     *
     * Never throws, except for InvalidLogEventException when strict_events is
     * enabled: logging must not be able to break the code it observes.
     */
    public function log(LogPayload $payload, ?string $levelOverride = null): void;

    /** Returns a logger pinned to a channel, bypassing the routing table. */
    public function channel(string $name): LoggerInterface;
}
