<?php

declare(strict_types=1);

namespace PhpLogger\Processor;

use PhpLogger\Contract\LogRecord;

interface ProcessorInterface
{
    /** Returns the record to keep processing, or null to drop it. */
    public function process(LogRecord $record): ?LogRecord;
}
