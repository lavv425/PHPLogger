<?php

declare(strict_types=1);

namespace Logger\Handler;

use Logger\Contract\LogRecord;
use Logger\Exception\HandlerFailure;
use Logger\Support\Clock;

/**
 * Stops hammering a destination that keeps failing.
 *
 * The trip is reported once, so the failure is visible; afterwards records are
 * dropped silently until the cooldown expires. A blocked stdout pipe must not
 * turn every log call into a stalled request.
 */
final class CircuitBreakerHandler implements HandlerInterface
{
    private HandlerInterface $inner;
    private Clock $clock;
    private int $failureThreshold;
    private int $cooldownSeconds;
    private int $failures = 0;
    private ?float $openedAt = null;

    public function __construct(
        HandlerInterface $inner,
        Clock $clock,
        int $failureThreshold = 5,
        int $cooldownSeconds = 30
    ) {
        $this->inner = $inner;
        $this->clock = $clock;
        $this->failureThreshold = $failureThreshold;
        $this->cooldownSeconds = $cooldownSeconds;
    }

    public function handle(LogRecord $record): void
    {
        if ($this->isOpen()) {
            return;
        }

        try {
            $this->inner->handle($record);
            $this->failures = 0;
        } catch (HandlerFailure $failure) {
            ++$this->failures;

            if ($this->failures >= $this->failureThreshold) {
                $this->openedAt = $this->clock->elapsedReference();
            }

            throw $failure;
        }
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isOpen(): bool
    {
        if ($this->openedAt === null) {
            return false;
        }

        if ($this->clock->elapsedReference() - $this->openedAt < $this->cooldownSeconds) {
            return true;
        }

        // Half-open: let the next record probe the destination again.
        $this->openedAt = null;
        $this->failures = 0;

        return false;
    }
}
