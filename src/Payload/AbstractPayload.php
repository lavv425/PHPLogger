<?php

declare(strict_types=1);

namespace Logger\Payload;

use Logger\Contract\LogError;
use Logger\Interfaces\Payload\LogPayloadInterface;
use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Support\Assert;

/**
 * Shared envelope-facing state for the built-in payloads.
 *
 * Instances are immutable: every with* method returns a copy. PHP 7.4 has no
 * readonly properties, so immutability is enforced by keeping state private and
 * exposing no setters.
 */
abstract class AbstractPayload implements LogPayloadInterface
{
    private string $event;
    private string $outcome = Outcome::UNKNOWN;
    private ?string $level = null;
    private ?float $duration = null;
    private ?LogError $error = null;

    protected function __construct(string $event)
    {
        $this->event = Assert::name($event, 'event');
    }

    public function event(): string
    {
        return $this->event;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function duration(): ?float
    {
        return $this->duration;
    }

    public function error(): ?LogError
    {
        return $this->error;
    }

    public function defaultLevel(): string
    {
        if ($this->level !== null) {
            return $this->level;
        }

        return $this->outcome === Outcome::FAILURE ? Level::ERROR : Level::INFO;
    }

    /** @return static */
    public function withOutcome(string $outcome): self
    {
        $clone = clone $this;
        $clone->outcome = Assert::outcome($outcome, 'outcome');

        return $clone;
    }

    /** @return static */
    public function withLevel(string $level): self
    {
        $clone = clone $this;
        $clone->level = Assert::level($level, 'level');

        return $clone;
    }

    /** @return static */
    public function withDuration(float $duration): self
    {
        $clone = clone $this;
        $clone->duration = Assert::nonNegative($duration, 'duration');

        return $clone;
    }

    /**
     * Attaches an error without deciding the outcome: an HTTP 500 is an error
     * for one caller and an expected answer for another.
     *
     * @return static
     */
    public function withError(?LogError $error): self
    {
        $clone = clone $this;
        $clone->error = $error;

        return $clone;
    }

    /**
     * Shortcut for the common case: the operation failed because of this error.
     *
     * @return static
     */
    public function withFailure(LogError $error): self
    {
        $clone = clone $this;
        $clone->error = $error;
        $clone->outcome = Outcome::FAILURE;

        return $clone;
    }

    /** @return static */
    public function withSuccess(): self
    {
        return $this->withOutcome(Outcome::SUCCESS);
    }

    /** Level explicitly set by the caller, if any. */
    protected function explicitLevel(): ?string
    {
        return $this->level;
    }
}
