<?php

declare(strict_types=1);

namespace Logger\Formatter;

use Logger\Contract\LogRecord;

interface FormatterInterface
{
    /** Returns the serialized record, newline terminated. */
    public function format(LogRecord $record): string;
}
