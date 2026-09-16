<?php

declare(strict_types=1);

namespace Logger\Payload;

use Logger\Contract\LogError;
use Logger\Enum\LogType;
use Logger\Enum\PhpSeverity;
use Throwable;

/**
 * PHP error, exception, or explicit application log.
 *
 * "data.message" is always present: for an error it mirrors error.message, so a
 * dashboard has one field to read regardless of how the record was produced.
 */
final class PhpLogPayload extends AbstractPayload
{
    private ?string $message = null;
    private ?string $file = null;
    private ?int $line = null;
    private ?string $function = null;
    private ?string $class = null;
    private ?int $memoryUsage = null;
    /** @var array<string, mixed>|null */
    private ?array $context = null;
    private ?int $severity = null;

    private function __construct(string $event)
    {
        parent::__construct($event);
    }

    public static function create(string $event, string $message): self
    {
        $payload = new self($event);
        $payload->message = $message;

        return $payload;
    }

    public static function fromThrowable(string $event, Throwable $throwable, int $maxFrames = 20): self
    {
        $payload = new self($event);
        $payload->message = $throwable->getMessage();
        $payload->file = $throwable->getFile();
        $payload->line = $throwable->getLine();
        $payload->class = get_class($throwable);

        return $payload->withFailure(LogError::fromThrowable($throwable, $maxFrames));
    }

    /**
     * @param string[]|null $stackTrace
     */
    public static function fromPhpError(
        string $event,
        int $severity,
        string $message,
        string $file,
        int $line,
        ?array $stackTrace = null
    ): self {
        $payload = new self($event);
        $payload->message = $message;
        $payload->file = $file;
        $payload->line = $line;
        $payload->severity = $severity;

        return $payload
            ->withError(LogError::fromPhpError($severity, $message, $stackTrace))
            ->withOutcome(PhpSeverity::outcome($severity));
    }

    public function withLocation(string $file, int $line): self
    {
        $clone = clone $this;
        $clone->file = $file;
        $clone->line = $line;

        return $clone;
    }

    public function withCallSite(?string $class, ?string $function): self
    {
        $clone = clone $this;
        $clone->class = $class;
        $clone->function = $function;

        return $clone;
    }

    public function withMemoryUsage(int $bytes): self
    {
        $clone = clone $this;
        $clone->memoryUsage = $bytes;

        return $clone;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function withContext(?array $context): self
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    public function defaultLevel(): string
    {
        $explicit = $this->explicitLevel();
        if ($explicit !== null) {
            return $explicit;
        }

        if ($this->severity !== null) {
            return PhpSeverity::level($this->severity);
        }

        return parent::defaultLevel();
    }

    public function logType(): string
    {
        return LogType::PHP_LOG;
    }

    public function data(): array
    {
        return [
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'class' => $this->class,
            'function' => $this->function,
            'memory_usage' => $this->memoryUsage,
            'context' => $this->context,
        ];
    }
}
