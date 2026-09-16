<?php

declare(strict_types=1);

namespace PhpLogger\Contract;

use DateTimeImmutable;
use PhpLogger\Enum\Outcome;

/**
 * The envelope, fully resolved and ready to be rendered.
 *
 * Built only by RecordFactory; rewritten only by the sanitizing gate through
 * the with* methods.
 */
final class LogRecord
{
    public const SCHEMA_VERSION = 1;

    /** Correlation keys always present in the output, for schema stability. */
    private const CORRELATION_KEYS = ['request_id', 'session_ref', 'user_ref', 'user_agent'];

    private DateTimeImmutable $timestamp;
    private string $level;
    private string $service;
    private string $env;
    private ?string $host;
    private string $logType;
    private string $event;
    private string $outcome;
    private ?float $duration;
    private ?LogError $error;
    /** @var array<string, string|null> */
    private array $correlation;
    /** @var array<string, mixed> */
    private array $data;
    private bool $truncated = false;

    /**
     * @param array<string, string|null> $correlation
     * @param array<string, mixed> $data
     */
    public function __construct(
        DateTimeImmutable $timestamp,
        string $level,
        string $service,
        string $env,
        ?string $host,
        string $logType,
        string $event,
        string $outcome,
        ?float $duration,
        ?LogError $error,
        array $correlation,
        array $data
    ) {
        $this->timestamp = $timestamp;
        $this->level = $level;
        $this->service = $service;
        $this->env = $env;
        $this->host = $host;
        $this->logType = $logType;
        $this->event = $event;
        $this->outcome = $outcome;
        $this->duration = $duration;
        $this->error = $error;
        $this->correlation = $correlation;
        $this->data = $data;
    }

    public function timestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function level(): string
    {
        return $this->level;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function env(): string
    {
        return $this->env;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function logType(): string
    {
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

    public function duration(): ?float
    {
        return $this->duration;
    }

    public function error(): ?LogError
    {
        return $this->error;
    }

    /** @return array<string, string|null> */
    public function correlation(): array
    {
        return $this->correlation;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /** @param array<string, mixed> $data */
    public function withData(array $data): self
    {
        $clone = clone $this;
        $clone->data = $data;

        return $clone;
    }

    public function withError(?LogError $error): self
    {
        $clone = clone $this;
        $clone->error = $error;

        return $clone;
    }

    /** @param array<string, string|null> $correlation */
    public function withCorrelation(array $correlation): self
    {
        $clone = clone $this;
        $clone->correlation = $correlation;

        return $clone;
    }

    public function withTruncated(bool $truncated): self
    {
        $clone = clone $this;
        $clone->truncated = $truncated;

        return $clone;
    }

    /**
     * Renders the public JSON schema. Key order is part of the contract covered
     * by the golden tests.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $record = [
            'schema_version' => self::SCHEMA_VERSION,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'level' => $this->level,
            'service' => $this->service,
            'env' => $this->env,
            'host' => $this->host,
            'log_type' => $this->logType,
            'event' => $this->event,
            'outcome' => $this->outcome,
            'success' => Outcome::toSuccessFlag($this->outcome),
            'duration' => $this->duration === null ? null : round($this->duration, 6),
            'error' => $this->error === null ? null : $this->error->toArray(),
        ];

        foreach (self::CORRELATION_KEYS as $key) {
            $record[$key] = $this->correlation[$key] ?? null;
        }

        $record['data'] = (object) $this->data;

        if ($this->truncated) {
            $record['_truncated'] = true;
        }

        return $record;
    }
}
