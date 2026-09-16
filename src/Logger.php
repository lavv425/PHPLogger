<?php

declare(strict_types=1);

namespace PhpLogger;

use PhpLogger\Channel\ChannelRegistry;
use PhpLogger\Contract\LogPayload;
use PhpLogger\Exception\InvalidLogEventException;
use PhpLogger\Sanitize\SanitizingGate;
use PhpLogger\Support\FailSafe;
use PhpLogger\Support\ReentrancyGuard;
use Throwable;

/**
 * Entry point.
 *
 * The guard wraps the whole pipeline, not just the I/O: a record is built,
 * sanitized, routed and written inside it, so a failure in any stage degrades
 * into a meta record instead of reaching the application.
 *
 * The one deliberate exception is InvalidLogEventException under strict_events,
 * which is a programming error and should surface in development and CI.
 */
final class Logger implements LoggerInterface
{
    private RecordFactory $factory;
    private SanitizingGate $gate;
    private ChannelRegistry $registry;
    private FailSafe $failSafe;
    private ReentrancyGuard $guard;
    private bool $strictEvents;
    private ?string $pinnedChannel = null;

    public function __construct(
        RecordFactory $factory,
        SanitizingGate $gate,
        ChannelRegistry $registry,
        FailSafe $failSafe,
        ReentrancyGuard $guard,
        bool $strictEvents = false
    ) {
        $this->factory = $factory;
        $this->gate = $gate;
        $this->registry = $registry;
        $this->failSafe = $failSafe;
        $this->guard = $guard;
        $this->strictEvents = $strictEvents;
    }

    public function log(LogPayload $payload, ?string $levelOverride = null): void
    {
        // An error raised inside the logger must not re-enter through the
        // application error handler.
        if ($this->guard->isBusy()) {
            return;
        }

        $this->guard->enter();

        try {
            $record = $this->factory->create($payload, $levelOverride);
            $record = $this->gate->apply($record);

            $channel = $this->pinnedChannel === null
                ? $this->registry->resolve($record->logType())
                : $this->registry->get($this->pinnedChannel);

            $channel->handle($record);
        } catch (InvalidLogEventException $exception) {
            if ($this->strictEvents) {
                throw $exception;
            }

            $this->failSafe->report('invalid_event', $this->safeLogType($payload));
        } catch (Throwable $exception) {
            $this->failSafe->report('pipeline_failure', $this->safeLogType($payload));
        } finally {
            $this->guard->leave();
        }
    }

    public function channel(string $name): LoggerInterface
    {
        // Fails fast on an unknown channel: that is configuration, not runtime.
        $this->registry->get($name);

        $clone = clone $this;
        $clone->pinnedChannel = $name;

        return $clone;
    }

    public function close(): void
    {
        $this->registry->closeAll();
    }

    private function safeLogType(LogPayload $payload): string
    {
        try {
            return $payload->logType();
        } catch (Throwable $exception) {
            return 'unknown';
        }
    }
}
