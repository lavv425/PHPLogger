<?php

declare(strict_types=1);

namespace PhpLogger\Support;

use PhpLogger\Enum\Level;
use PhpLogger\Enum\Outcome;
use PhpLogger\Exception\InvalidLogEventException;

/**
 * Validation for caller-supplied event data. Names are restricted to a fixed
 * charset so dynamic values (an email, an id) cannot end up as an event or type
 * name, where the sanitizer would not look for them.
 */
final class Assert
{
    public const NAME_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    private function __construct()
    {
    }

    public static function name(string $value, string $field): string
    {
        if (preg_match(self::NAME_PATTERN, $value) !== 1) {
            throw InvalidLogEventException::forField(
                $field,
                'must match ' . self::NAME_PATTERN . ' (no free-form data in names)'
            );
        }

        return $value;
    }

    public static function nonEmpty(string $value, string $field): string
    {
        if (trim($value) === '') {
            throw InvalidLogEventException::forField($field, 'must not be empty');
        }

        return $value;
    }

    public static function nonNegative(float $value, string $field): float
    {
        if ($value < 0.0 || is_nan($value) || is_infinite($value)) {
            throw InvalidLogEventException::forField($field, 'must be a finite, non-negative number');
        }

        return $value;
    }

    public static function level(string $value, string $field): string
    {
        if (!Level::isValid($value)) {
            throw InvalidLogEventException::forField($field, 'unknown level "' . $value . '"');
        }

        return $value;
    }

    public static function outcome(string $value, string $field): string
    {
        if (!Outcome::isValid($value)) {
            throw InvalidLogEventException::forField($field, 'unknown outcome "' . $value . '"');
        }

        return $value;
    }
}
