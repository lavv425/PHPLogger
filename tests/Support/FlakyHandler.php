<?php

declare(strict_types=1);

namespace Logger\Tests\Support;

use Logger\Contract\LogRecord;
use Logger\Exception\HandlerFailure;
use Logger\Interfaces\Handler\HandlerInterface;

/** A destination whose availability the test switches on and off. */
final class FlakyHandler implements HandlerInterface
{
    private bool $healthy = true;
    /** @var LogRecord[] */
    private array $accepted = [];

    public function fail(): void
    {
        $this->healthy = false;
    }

    public function recover(): void
    {
        $this->healthy = true;
    }

    public function handle(LogRecord $record): void
    {
        if (!$this->healthy) {
            throw new HandlerFailure('destination unavailable');
        }

        $this->accepted[] = $record;
    }

    public function close(): void
    {
    }

    /** @return LogRecord[] */
    public function accepted(): array
    {
        return $this->accepted;
    }
}
