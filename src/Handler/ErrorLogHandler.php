<?php

declare(strict_types=1);

namespace Logger\Handler;

use Logger\Contract\LogRecord;
use Logger\Exception\HandlerFailure;
use Logger\Interfaces\Formatter\FormatterInterface;
use Logger\Interfaces\Handler\HandlerInterface;

/**
 * Fallback destination.
 *
 * Where the line ends up and whether it gets a prefix depends entirely on the
 * error_log ini setting and on the SAPI: it is not guaranteed to be stderr and
 * not guaranteed to stay valid NDJSON. Use a stream target when the collector
 * parses JSON.
 */
final class ErrorLogHandler implements HandlerInterface
{
    private FormatterInterface $formatter;

    public function __construct(FormatterInterface $formatter)
    {
        $this->formatter = $formatter;
    }

    public function handle(LogRecord $record): void
    {
        $line = rtrim($this->formatter->format($record), "\n");

        if (error_log($line) !== true) {
            throw new HandlerFailure('error_log() refused the record');
        }
    }

    public function close(): void
    {
    }
}
