<?php

declare(strict_types=1);

namespace Logger\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Logger\Contract\LogError;
use Logger\Contract\LogRecord;
use Logger\Enum\Level;
use Logger\Enum\LogType;
use Logger\Enum\Outcome;

/**
 * Builds LogRecord instances for tests without repeating the twelve
 * constructor arguments in every case.
 */
final class RecordBuilder
{
    public const TIMESTAMP = '2024-03-01T10:20:30.123456+00:00';

    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|null> $correlation
     */
    public static function make(array $data = [], string $level = Level::INFO, string $logType = LogType::DB_QUERY, string $outcome = Outcome::SUCCESS, ?float $duration = null, ?LogError $error = null, array $correlation = []): LogRecord
    {
        return new LogRecord(
            self::timestamp(),
            $level,
            'test-service',
            'testing',
            'test-host',
            $logType,
            'test_event',
            $outcome,
            $duration,
            $error,
            $correlation,
            $data
        );
    }

    public static function timestamp(): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.uP',
            self::TIMESTAMP,
            new DateTimeZone('UTC')
        );

        if ($timestamp === false) {
            throw new \RuntimeException('Unparsable fixture timestamp.');
        }

        return $timestamp;
    }
}
