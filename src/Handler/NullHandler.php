<?php

declare(strict_types=1);

namespace Logger\Handler;

use Logger\Contract\LogRecord;
use Logger\Interfaces\Handler\HandlerInterface;

/** Discards everything. Useful to switch a channel off from configuration. */
final class NullHandler implements HandlerInterface
{
    public function handle(LogRecord $record): void
    {
    }

    public function close(): void
    {
    }
}
