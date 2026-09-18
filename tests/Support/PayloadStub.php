<?php

declare(strict_types=1);

namespace Logger\Tests\Support;

use Logger\Contract\LogError;
use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Interfaces\Payload\LogPayloadInterface;

/**
 * A payload that returns exactly what the test puts in, including values the
 * built-in payloads would have rejected at construction time. Used to prove
 * that RecordFactory validates on its own rather than trusting the payload.
 */
final class PayloadStub implements LogPayloadInterface
{
    private string $logType;
    private string $event;
    private string $outcome;
    private string $defaultLevel;
    private ?float $duration;
    private ?LogError $error;
    /** @var array<string, mixed> */
    private array $data;
    private bool $throwOnLogType = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $logType = 'stub_type', string $event = 'stub_event', string $outcome = Outcome::SUCCESS, string $defaultLevel = Level::INFO, ?float $duration = null, ?LogError $error = null, array $data = [])
    {
        $this->logType = $logType;
        $this->event = $event;
        $this->outcome = $outcome;
        $this->defaultLevel = $defaultLevel;
        $this->duration = $duration;
        $this->error = $error;
        $this->data = $data;
    }

    /** Makes logType() blow up, to exercise Logger::safeLogType(). */
    public function throwOnLogType(): self
    {
        $clone = clone $this;
        $clone->throwOnLogType = true;

        return $clone;
    }

    public function logType(): string
    {
        if ($this->throwOnLogType) {
            throw new \RuntimeException('log type is not available');
        }

        return $this->logType;
    }

    public function event(): string
    {
        return $this->event;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function defaultLevel(): string
    {
        return $this->defaultLevel;
    }

    public function duration(): ?float
    {
        return $this->duration;
    }

    public function error(): ?LogError
    {
        return $this->error;
    }

    public function data(): array
    {
        return $this->data;
    }
}
