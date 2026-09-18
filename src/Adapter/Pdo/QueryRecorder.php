<?php

declare(strict_types=1);

namespace Logger\Adapter\Pdo;

use Logger\Contract\LogError;
use Logger\Enum\Outcome;
use Logger\Interfaces\Logger\LoggerInterface;
use Logger\Payload\DbQueryPayload;
use Logger\Support\Assert;
use PDOException;
use Throwable;

/**
 * Turns the outcome of a database call into a log event.
 *
 * Shared by LoggingPdo and LoggingStatement so the two interception points
 * cannot drift apart. Never throws: a logging failure must not become a
 * database failure.
 */
final class QueryRecorder
{
    private LoggerInterface $logger;
    private string $database;
    private string $event;

    public function __construct(LoggerInterface $logger, string $database, string $event = 'db_query')
    {
        $this->logger = $logger;
        $this->database = Assert::nonEmpty($database, 'database');
        $this->event = Assert::name($event, 'event');
    }

    /**
     * @param array<string|int, mixed>|null $params
     */
    public function success(string $statement, ?array $params, float $duration, ?int $rowCount): void
    {
        $this->write($statement, $params, $duration, $rowCount, Outcome::SUCCESS, null);
    }

    /**
     * @param array<string|int, mixed>|null $params
     */
    public function failure(string $statement, ?array $params, float $duration, ?LogError $error): void
    {
        $this->write($statement, $params, $duration, null, Outcome::FAILURE, $error);
    }

    /** Builds the error description from whatever the driver reported. */
    public static function describe(Throwable $exception): LogError
    {
        $sqlState = null;

        if ($exception instanceof PDOException) {
            $info = $exception->errorInfo;
            // errorInfo[0] is the SQLSTATE; getCode() carries it too, but as a
            // string that is "HY000" for some drivers and 0 for others.
            $sqlState = is_array($info) && isset($info[0]) && is_string($info[0]) ? $info[0] : null;
        }

        return LogError::fromDatabase($exception->getMessage(), $sqlState);
    }

    /**
     * @param array<string|int, mixed>|null $params
     */
    private function write(string $statement, ?array $params, float $duration, ?int $rowCount, string $outcome, ?LogError $error): void
    {
        try {
            $payload = DbQueryPayload::create($this->event, $this->database);

            if (trim($statement) !== '') {
                $payload = $payload->withStatement($statement, $params);
            }

            if ($rowCount !== null) {
                $payload = $payload->withRowCount($rowCount);
            }

            $payload = $payload->withDuration(max(0.0, $duration))->withOutcome($outcome);

            if ($error !== null) {
                $payload = $payload->withError($error);
            }

            $this->logger->log($payload);
        } catch (Throwable $ignored) {
            // The query already happened. Losing its log line is bad; turning
            // it into an exception the application never expected is worse.
        }
    }
}
