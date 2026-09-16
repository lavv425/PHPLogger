<?php

declare(strict_types=1);

namespace PhpLogger\Handler;

use PhpLogger\Contract\LogRecord;
use PhpLogger\Exception\HandlerFailure;

interface HandlerInterface
{
    /** @throws HandlerFailure when the destination could not be written to */
    public function handle(LogRecord $record): void;

    public function close(): void;
}
