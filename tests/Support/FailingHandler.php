<?php

declare(strict_types=1);

namespace PhpLoggerTests\Support;

use PhpLogger\Contract\LogRecord;
use PhpLogger\Exception\HandlerFailure;
use PhpLogger\Handler\HandlerInterface;

/** Destination that is always down. */
final class FailingHandler implements HandlerInterface
{
    private int $attempts = 0;

    public function handle(LogRecord $record): void
    {
        ++$this->attempts;

        throw new HandlerFailure('destination is down');
    }

    public function close(): void
    {
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
