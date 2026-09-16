<?php

declare(strict_types=1);

namespace Logger\Formatter;

use JsonException;
use Logger\Contract\LogRecord;
use Logger\Exception\SanitizationFailure;

/**
 * Newline delimited JSON, one record per line.
 *
 * JSON_PRESERVE_ZERO_FRACTION keeps a 0.0 duration a float, so the destination
 * never sees the same field as int on one line and float on the next.
 */
final class JsonFormatter implements FormatterInterface
{
    public const DEFAULT_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    private int $flags;
    private bool $fixPrecision;

    public function __construct(int $flags = self::DEFAULT_FLAGS)
    {
        $this->flags = $flags | JSON_THROW_ON_ERROR;

        // With serialize_precision set to something other than -1 (some
        // distributions ship 17, XAMPP ships 100) a 0.045 duration is written as
        // 0.04499999999999999833..., which is unusable in a dashboard. The
        // setting is overridden around the encode call only, and only when the
        // environment actually needs it.
        $this->fixPrecision = (string) ini_get('serialize_precision') !== '-1';
    }

    public function format(LogRecord $record): string
    {
        if (!$this->fixPrecision) {
            return $this->encode($record);
        }

        $previous = ini_set('serialize_precision', '-1');

        try {
            return $this->encode($record);
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    private function encode(LogRecord $record): string
    {
        try {
            return json_encode($record->toArray(), $this->flags) . "\n";
        } catch (JsonException $exception) {
            // A record that cannot be encoded must not be written in any
            // degraded form: its content has not been through the gate.
            throw new SanitizationFailure('record is not encodable: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
