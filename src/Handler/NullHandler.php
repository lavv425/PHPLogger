<?php

declare(strict_types=1);

namespace PhpLogger\Handler;

use PhpLogger\Contract\LogRecord;

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
