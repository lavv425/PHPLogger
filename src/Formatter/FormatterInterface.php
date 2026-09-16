<?php

declare(strict_types=1);

namespace PhpLogger\Formatter;

use PhpLogger\Contract\LogRecord;

interface FormatterInterface
{
    /** Returns the serialized record, newline terminated. */
    public function format(LogRecord $record): string;
}
