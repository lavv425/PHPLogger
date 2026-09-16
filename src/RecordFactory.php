<?php

declare(strict_types=1);

namespace Logger;

use DateTimeZone;
use Logger\Interfaces\Context\ContextProviderInterface;
use Logger\Interfaces\Payload\LogPayloadInterface;
use Logger\Contract\LogRecord;
use Logger\Exception\InvalidLogEventException;
use Logger\Support\Assert;
use Logger\Interfaces\Support\ClockInterface;

/**
 * Turns a payload plus the ambient context into a validated envelope.
 *
 * Every caller-controlled value is checked here, so the rest of the pipeline can
 * assume a well-formed record.
 */
final class RecordFactory
{
    private ClockInterface $clock;
    private ContextProviderInterface $contextProvider;
    private string $service;
    private string $env;
    private ?string $host;

    public function __construct(
        ClockInterface $clock,
        ContextProviderInterface $contextProvider,
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
    public function create(LogPayloadInterface $payload, ?string $levelOverride = null): LogRecord
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
