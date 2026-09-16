<?php

declare(strict_types=1);

namespace Logger\Contract;

/**
 * A log type. Implement this to add a new one: the pipeline never needs to know
 * the concrete class, and the envelope field names stay out of reach because
 * everything returned by data() is nested under "data".
 */
interface LogPayload
{
    /** Discriminator written to "log_type"; must match Assert::NAME_PATTERN. */
    public function logType(): string;

    public function event(): string;

    /** One of the Outcome constants. */
    public function outcome(): string;

    /**
     * Level proposed by the payload. An explicit level passed to Logger::log()
     * wins over this one.
     */
    public function defaultLevel(): string;

    public function duration(): ?float;

    public function error(): ?LogError;

    /**
     * Type-specific fields, written under "data".
     *
     * Values are returned raw: normalization, allow-listing and scrubbing are
     * the sanitizing gate's job, not the payload's.
     *
     * @return array<string, mixed>
     */
    public function data(): array;
}
