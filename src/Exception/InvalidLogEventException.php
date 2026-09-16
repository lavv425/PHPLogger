<?php

declare(strict_types=1);

namespace PhpLogger\Exception;

use InvalidArgumentException;

/**
 * Thrown when a caller builds an event with invalid data. Propagated when
 * strict_events is on (development, CI), converted into a meta record otherwise.
 */
final class InvalidLogEventException extends InvalidArgumentException implements LoggerException
{
    public static function forField(string $field, string $reason): self
    {
        return new self(sprintf('Invalid log event field "%s": %s', $field, $reason));
    }
}
