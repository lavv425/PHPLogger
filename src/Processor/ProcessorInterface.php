<?php

declare(strict_types=1);

namespace Logger\Processor;

use Logger\Contract\LogRecord;

interface ProcessorInterface
{
    /** Returns the record to keep processing, or null to drop it. */
    public function process(LogRecord $record): ?LogRecord;
}
