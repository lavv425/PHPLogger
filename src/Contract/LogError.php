<?php

declare(strict_types=1);

namespace Logger\Contract;

use Logger\Enum\ErrorType;
use Logger\Enum\PhpSeverity;
use Throwable;

/**
 * Unified error description shared by every log type.
 *
 * "code" and "severity" are deliberately separate: an exception code, a cURL
 * errno and a SQLSTATE are one concept, the PHP E_* constant is another.
 */
final class LogError
{
    private string $type;
    private string $message;
    private ?string $code;
    private ?string $class;
    private ?string $severity;
    private ?int $severityCode;
    /** @var string[]|null */
    private ?array $stackTrace;

    /**
     * @param string[]|null $stackTrace
     */
    public function __construct(
        string $type,
        string $message,
        ?string $code = null,
        ?string $class = null,
        ?string $severity = null,
        ?int $severityCode = null,
        ?array $stackTrace = null
    ) {
        $this->type = ErrorType::isValid($type) ? $type : ErrorType::OTHER;
        $this->message = $message;
        $this->code = $code;
        $this->class = $class;
        $this->severity = $severity;
        $this->severityCode = $severityCode;
        $this->stackTrace = $stackTrace;
    }

    public static function fromThrowable(Throwable $throwable, int $maxFrames = 20): self
    {
        $code = $throwable->getCode();

        return new self(
            ErrorType::EXCEPTION,
            $throwable->getMessage(),
            $code === 0 || $code === '' ? null : (string) $code,
            get_class($throwable),
            null,
            null,
            self::renderTrace($throwable, $maxFrames)
        );
    }

    /**
     * @param string[]|null $stackTrace
     */
    public static function fromPhpError(int $severity, string $message, ?array $stackTrace = null): self
    {
        return new self(
            ErrorType::PHP_ERROR,
            $message,
            null,
            null,
            PhpSeverity::name($severity),
            $severity,
            $stackTrace
        );
    }

    public static function fromCurl(int $errno, string $message): self
    {
        // 28 is CURLE_OPERATION_TIMEDOUT; a timeout deserves its own type in
        // dashboards even though cURL reports it like any other error.
        $type = $errno === 28 ? ErrorType::TIMEOUT : ErrorType::CURL_ERROR;

        return new self($type, $message, (string) $errno);
    }

    public static function fromHttpStatus(int $status, ?string $message = null): self
    {
        return new self(
            ErrorType::HTTP_ERROR,
            $message ?? sprintf('HTTP status %d', $status),
            (string) $status
        );
    }

    public static function fromDatabase(string $message, ?string $sqlState = null): self
    {
        return new self(ErrorType::DB_ERROR, $message, $sqlState);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function class(): ?string
    {
        return $this->class;
    }

    public function severity(): ?string
    {
        return $this->severity;
    }

    public function severityCode(): ?int
    {
        return $this->severityCode;
    }

    /** @return string[]|null */
    public function stackTrace(): ?array
    {
        return $this->stackTrace;
    }

    public function withMessage(string $message): self
    {
        $clone = clone $this;
        $clone->message = $message;

        return $clone;
    }

    /** @param string[]|null $stackTrace */
    public function withStackTrace(?array $stackTrace): self
    {
        $clone = clone $this;
        $clone->stackTrace = $stackTrace;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'code' => $this->code,
            'message' => $this->message,
            'class' => $this->class,
            'severity' => $this->severity,
            'severity_code' => $this->severityCode,
            'stack_trace' => $this->stackTrace,
        ];
    }

    /**
     * Renders frames from file/line/class/function only.
     *
     * getTraceAsString() is never used: it inlines call arguments, which is one
     * of the most reliable ways to leak a password into a log line.
     *
     * @return string[]
     */
    private static function renderTrace(Throwable $throwable, int $maxFrames): array
    {
        $frames = [];
        $index = 0;

        foreach ($throwable->getTrace() as $frame) {
            if ($index >= $maxFrames) {
                $frames[] = sprintf('#%d {truncated}', $index);
                break;
            }

            $callable = '';
            if (isset($frame['class'], $frame['type'], $frame['function'])) {
                $callable = $frame['class'] . $frame['type'] . $frame['function'] . '()';
            } elseif (isset($frame['function'])) {
                $callable = $frame['function'] . '()';
            }

            $frames[] = sprintf(
                '#%d %s(%s): %s',
                $index,
                $frame['file'] ?? '[internal]',
                isset($frame['line']) ? (string) $frame['line'] : '0',
                $callable
            );

            ++$index;
        }

        return $frames;
    }
}
