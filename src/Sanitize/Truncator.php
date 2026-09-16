<?php

declare(strict_types=1);

namespace Logger\Sanitize;

use Logger\Config\LimitsConfig;
use Logger\Contract\LogRecord;
use Logger\Formatter\FormatterInterface;
use Logger\Formatter\JsonFormatter;
use Logger\Support\Text;
use Throwable;

/**
 * Keeps a record under the size limit, dropping the least useful parts first.
 */
final class Truncator
{
    /** Order in which data fields are sacrificed when the record is too large. */
    private const DROP_ORDER = ['payload', 'context', 'params', 'statement_raw'];

    private LimitsConfig $limits;
    private FormatterInterface $formatter;

    /**
     * The formatter is the one that will actually write the record, so the size
     * measured here is the size that reaches the destination.
     */
    public function __construct(LimitsConfig $limits, ?FormatterInterface $formatter = null)
    {
        $this->limits = $limits;
        $this->formatter = $formatter ?? new JsonFormatter();
    }

    public function truncate(LogRecord $record): LogRecord
    {
        $truncated = false;

        $error = $record->error();
        if ($error !== null) {
            $frames = $error->stackTrace();
            if ($frames !== null && count($frames) > $this->limits->maxStackFrames()) {
                $error = $error->withStackTrace(array_slice($frames, 0, $this->limits->maxStackFrames()));
                $record = $record->withError($error);
                $truncated = true;
            }
        }

        if ($this->encodedSize($record) <= $this->limits->maxRecordBytes()) {
            return $truncated ? $record->withTruncated(true) : $record;
        }

        $data = $record->data();
        foreach (self::DROP_ORDER as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $data[$field] = is_array($data[$field]) ? ['_omitted' => count($data[$field])] : null;
            $record = $record->withData($data);
            $truncated = true;

            if ($this->encodedSize($record) <= $this->limits->maxRecordBytes()) {
                return $record->withTruncated(true);
            }
        }

        if ($record->error() !== null && $record->error()->stackTrace() !== null) {
            $record = $record->withError($record->error()->withStackTrace(null));
            $truncated = true;

            if ($this->encodedSize($record) <= $this->limits->maxRecordBytes()) {
                return $record->withTruncated(true);
            }
        }

        $record = $this->shrinkLongStrings($record);

        return $this->encodedSize($record) > $this->limits->maxRecordBytes() || $truncated
            ? $record->withTruncated(true)
            : $record;
    }

    private function shrinkLongStrings(LogRecord $record): LogRecord
    {
        $budget = (int) max(64, $this->limits->maxRecordBytes() / 8);

        $error = $record->error();
        if ($error !== null) {
            $record = $record->withError($error->withMessage(Text::truncateBytes($error->message(), $budget)));
        }

        $data = $record->data();
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = Text::truncateBytes($value, $budget);
            }
        }

        return $record->withData($data);
    }

    private function encodedSize(LogRecord $record): int
    {
        try {
            return strlen($this->formatter->format($record));
        } catch (Throwable $exception) {
            // Unencodable: treat as oversized so the caller keeps shrinking it.
            return PHP_INT_MAX;
        }
    }
}
