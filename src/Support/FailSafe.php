<?php

declare(strict_types=1);

namespace PhpLogger\Support;

use Throwable;

/**
 * Last resort reporting when the pipeline itself fails.
 *
 * Deliberately primitive: it shares no code with the pipeline that just broke,
 * it is rate limited per request, and the record it writes carries no
 * caller-supplied data at all. Not even the event name is reported: an event
 * name built by concatenating a value would reopen the leak the gate just
 * prevented.
 */
final class FailSafe
{
    private string $target;
    private int $maxRecords;
    private string $service;
    private string $env;
    private int $emitted = 0;

    public function __construct(string $target, int $maxRecords, string $service, string $env)
    {
        $this->target = $target;
        $this->maxRecords = $maxRecords;
        $this->service = $service;
        $this->env = $env;
    }

    public function report(string $reason, string $droppedLogType): void
    {
        if ($this->emitted >= $this->maxRecords) {
            return;
        }

        ++$this->emitted;

        try {
            $line = json_encode([
                'schema_version' => 1,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'level' => 'error',
                'service' => $this->service,
                'env' => $this->env,
                'log_type' => 'logger_error',
                'event' => 'pipeline_failure',
                'outcome' => 'failure',
                'success' => false,
                'reason' => $this->safeToken($reason),
                'dropped_log_type' => $this->safeToken($droppedLogType),
            ], JSON_UNESCAPED_SLASHES);

            if (!is_string($line)) {
                return;
            }

            $target = $this->target;
            $stream = ErrorTrap::run(static function () use ($target) {
                return fopen($target, 'ab');
            });

            if (!is_resource($stream)) {
                return;
            }

            ErrorTrap::run(static function () use ($stream, $line): void {
                fwrite($stream, $line . "\n");
                fclose($stream);
            });
        } catch (Throwable $exception) {
            // Nothing left to try: losing a meta record is preferable to
            // throwing from the failure path of the logger.
        }
    }

    public function emitted(): int
    {
        return $this->emitted;
    }

    /** Guarantees the value is a plain token, never free-form data. */
    private function safeToken(string $value): string
    {
        return preg_match(Assert::NAME_PATTERN, $value) === 1 ? $value : 'unknown';
    }
}
