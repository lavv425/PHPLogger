<?php

declare(strict_types=1);

namespace Logger\Sanitize;

use Logger\Config\CaptureConfig;
use Logger\Config\LimitsConfig;
use Logger\Contract\LogError;
use Logger\Contract\LogRecord;
use Logger\Enum\LogType;
use Logger\Exception\SanitizationFailure;
use Logger\Support\Text;
use Throwable;

/**
 * The single point where a record becomes safe to write.
 *
 * Rules, in order:
 *  1. a SQL statement is replaced by its normalized fingerprint;
 *  2. every array-valued field under "data" is dropped unless CaptureConfig
 *     allow-lists its keys (deny by default);
 *  3. every remaining string is scrubbed and size-capped.
 *
 * If any of this fails the record is dropped: an unsanitized record is never
 * written, not even by the fail-safe path.
 */
final class SanitizingGate
{
    private CaptureConfig $capture;
    private Scrubber $scrubber;
    private Truncator $truncator;
    private StatementNormalizer $normalizer;
    private LimitsConfig $limits;

    public function __construct(CaptureConfig $capture, Scrubber $scrubber, Truncator $truncator, StatementNormalizer $normalizer, LimitsConfig $limits)
    {
        $this->capture = $capture;
        $this->scrubber = $scrubber;
        $this->truncator = $truncator;
        $this->normalizer = $normalizer;
        $this->limits = $limits;
    }

    /** @throws SanitizationFailure */
    public function apply(LogRecord $record): LogRecord
    {
        try {
            $data = $record->data();

            if ($record->logType() === LogType::DB_QUERY) {
                $data = $this->applyStatementPolicy($data);
            }

            $record = $record
                ->withData($this->sanitizeData($record->logType(), $data))
                ->withError($this->sanitizeError($record->error()))
                ->withCorrelation($this->sanitizeCorrelation($record->correlation()));

            return $this->truncator->truncate($record);
        } catch (Throwable $exception) {
            throw new SanitizationFailure(
                'record dropped during sanitization: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyStatementPolicy(array $data): array
    {
        $statement = $data['statement'] ?? null;

        if (!is_string($statement)) {
            return $data;
        }

        $normalized = $this->normalizer->normalize($statement);
        $data['statement'] = $normalized;
        $data['statement_hash'] = $this->normalizer->fingerprint($normalized);

        if ($this->capture->isRawStatementEnabled()) {
            $data['statement_raw'] = $statement;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeData(string $logType, array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if ($this->scrubber->isSensitiveKey($key)) {
                $sanitized[$key] = $value === null ? null : $this->scrubber->mask();
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->filterStructure($logType, $key, $value);
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = Text::truncateBytes(
                    $this->scrubber->scrubString($value),
                    $this->limits->maxFieldBytes()
                );
                continue;
            }

            $sanitized[$key] = $this->scrubber->scrubValue($value, $this->limits->maxDepth());
        }

        return $sanitized;
    }

    /**
     * Deny by default: only the keys named in the configuration survive, and the
     * number of dropped entries is reported so the omission is visible.
     *
     * @param array<string, mixed> $structure
     * @return array<string, mixed>
     */
    private function filterStructure(string $logType, string $field, array $structure): array
    {
        $allowed = $this->capture->allowedKeys($logType, $field);
        $kept = [];
        $dropped = 0;

        foreach ($structure as $key => $value) {
            $stringKey = (string) $key;

            if (!in_array($stringKey, $allowed, true) || $this->scrubber->isSensitiveKey($stringKey)) {
                ++$dropped;
                continue;
            }

            $kept[$stringKey] = $this->scrubber->scrubValue($value, $this->limits->maxDepth());
        }

        if ($dropped > 0) {
            $kept['_omitted'] = $dropped;
        }

        return $kept;
    }

    /** @param array<string, string|null> $correlation */
    private function sanitizeCorrelation(array $correlation): array
    {
        $sanitized = [];

        foreach ($correlation as $key => $value) {
            $sanitized[$key] = is_string($value)
                ? Text::truncateBytes($this->scrubber->scrubString($value), $this->limits->maxFieldBytes())
                : null;
        }

        return $sanitized;
    }

    private function sanitizeError(?LogError $error): ?LogError
    {
        if ($error === null) {
            return null;
        }

        $error = $error->withMessage(
            Text::truncateBytes($this->scrubber->scrubString($error->message()), $this->limits->maxFieldBytes())
        );

        $frames = $error->stackTrace();
        if ($frames === null) {
            return $error;
        }

        $scrubbed = [];
        foreach (array_slice($frames, 0, $this->limits->maxStackFrames()) as $frame) {
            $scrubbed[] = $this->scrubber->scrubString((string) $frame);
        }

        return $error->withStackTrace($scrubbed);
    }
}
