<?php

declare(strict_types=1);

namespace PhpLogger;

use DateTimeZone;
use PhpLogger\Context\ContextProvider;
use PhpLogger\Contract\LogPayload;
use PhpLogger\Contract\LogRecord;
use PhpLogger\Exception\InvalidLogEventException;
use PhpLogger\Support\Assert;
use PhpLogger\Support\Clock;

/**
 * Turns a payload plus the ambient context into a validated envelope.
 *
 * Every caller-controlled value is checked here, so the rest of the pipeline can
 * assume a well-formed record.
 */
final class RecordFactory
{
    private Clock $clock;
    private ContextProvider $contextProvider;
    private string $service;
    private string $env;
    private ?string $host;

    public function __construct(
        Clock $clock,
        ContextProvider $contextProvider,
        string $service,
        string $env,
        ?string $host
    ) {
        $this->clock = $clock;
        $this->contextProvider = $contextProvider;
        $this->service = $service;
        $this->env = $env;
        $this->host = $host;
    }

    /** @throws InvalidLogEventException */
    public function create(LogPayload $payload, ?string $levelOverride = null): LogRecord
    {
        $logType = Assert::name($payload->logType(), 'log_type');
        $event = Assert::name($payload->event(), 'event');
        $outcome = Assert::outcome($payload->outcome(), 'outcome');
        $level = Assert::level($levelOverride ?? $payload->defaultLevel(), 'level');

        $duration = $payload->duration();
        if ($duration !== null) {
            Assert::nonNegative($duration, 'duration');
        }

        return new LogRecord(
            $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
            $level,
            $this->service,
            $this->env,
            $this->host,
            $logType,
            $event,
            $outcome,
            $duration,
            $payload->error(),
            $this->contextProvider->current()->toArray(),
            $payload->data()
        );
    }
}
