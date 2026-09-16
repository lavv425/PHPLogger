<?php

declare(strict_types=1);

namespace Logger\Interfaces\Logger;

use Logger\Interfaces\Payload\LogPayloadInterface;

interface LoggerInterface
{
    /**
     * Writes an event.
     *
     * Never throws, except for InvalidLogEventException when strict_events is
     * enabled: logging must not be able to break the code it observes.
     */
    public function log(LogPayloadInterface $payload, ?string $levelOverride = null): void;

    /** Returns a logger pinned to a channel, bypassing the routing table. */
    public function channel(string $name): LoggerInterface;
}
