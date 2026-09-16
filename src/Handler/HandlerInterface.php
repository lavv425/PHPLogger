<?php

declare(strict_types=1);

namespace Logger\Handler;

use Logger\Contract\LogRecord;
use Logger\Exception\HandlerFailure;

interface HandlerInterface
{
    /** @throws HandlerFailure when the destination could not be written to */
    public function handle(LogRecord $record): void;

    public function close(): void;
}
