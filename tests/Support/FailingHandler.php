<?php

declare(strict_types=1);

namespace Logger\Tests\Support;

use Logger\Contract\LogRecord;
use Logger\Exception\HandlerFailure;
use Logger\Interfaces\Handler\HandlerInterface;

/** A destination that is always down. */
final class FailingHandler implements HandlerInterface
{
    private string $message;
    private int $attempts = 0;
    private bool $closed = false;

    public function __construct(string $message = 'destination unavailable')
    {
        $this->message = $message;
    }

    public function handle(LogRecord $record): void
    {
        ++$this->attempts;

        throw new HandlerFailure($this->message);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
