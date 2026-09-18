<?php

declare(strict_types=1);

namespace Logger\Adapter\Pdo;

use Logger\Interfaces\Logger\LoggerInterface;
use Logger\Support\Stopwatch;
use PDO;
use PDOException;

/**
 * A PDO that logs the queries going through it.
 *
 * A subclass rather than a decorator on purpose: existing code type-hints PDO,
 * and wrapping it would break every one of those signatures.
 *
 * Four interception points are needed, not one. Prepared statements report from
 * LoggingStatement, but exec() and query() never build one, and some drivers
 * (SQLite, PostgreSQL) validate the statement inside prepare() itself, so a
 * broken query fails there and execute() is never reached.
 *
 * The return types are left undeclared because PDO declares unions such as
 * PDOStatement|false, which PHP 7.4 cannot express. ReturnTypeWillChange keeps
 * PHP 8 quiet; on 7.4 the attribute is read as a comment.
 */
final class LoggingPdo extends PDO
{
    private QueryRecorder $recorder;

    /**
     * @param array<int, mixed> $options
     */
    public function __construct(string $dsn, ?string $username, ?string $password, array $options, LoggerInterface $logger, string $database, string $event = 'db_query')
    {
        parent::__construct($dsn, $username, $password, $options);

        $this->recorder = new QueryRecorder($logger, $database, $event);
        $this->setAttribute(self::ATTR_STATEMENT_CLASS, [LoggingStatement::class, [$this->recorder]]);
    }

    /**
     * @param string $statement
     * @param array<int, mixed> $options
     */
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = [])
    {
        try {
            return parent::prepare($statement, $options);
        } catch (PDOException $exception) {
            // Driver-side validation: the query never reaches execute().
            $this->recorder->failure((string) $statement, null, 0.0, QueryRecorder::describe($exception));

            throw $exception;
        }
    }

    /**
     * @param string $statement
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $stopwatch = Stopwatch::start();

        try {
            $affected = parent::exec($statement);
        } catch (PDOException $exception) {
            $this->recorder->failure((string) $statement, null, $stopwatch->elapsed(), QueryRecorder::describe($exception));

            throw $exception;
        }

        $duration = $stopwatch->elapsed();

        if ($affected === false) {
            $this->recorder->failure((string) $statement, null, $duration, QueryRecorder::describe(new PDOException($this->errorMessage())));

            return false;
        }

        $this->recorder->success((string) $statement, null, $duration, (int) $affected);

        return $affected;
    }

    /**
     * @param string $statement
     * @param mixed ...$fetchMode
     */
    #[\ReturnTypeWillChange]
    public function query($statement, ...$fetchMode)
    {
        $stopwatch = Stopwatch::start();

        try {
            $result = parent::query($statement, ...$fetchMode);
        } catch (PDOException $exception) {
            $this->recorder->failure((string) $statement, null, $stopwatch->elapsed(), QueryRecorder::describe($exception));

            throw $exception;
        }

        $duration = $stopwatch->elapsed();

        if ($result === false) {
            $this->recorder->failure((string) $statement, null, $duration, QueryRecorder::describe(new PDOException($this->errorMessage())));

            return false;
        }

        $this->recorder->success((string) $statement, null, $duration, null);

        return $result;
    }

    private function errorMessage(): string
    {
        $info = parent::errorInfo();

        return is_array($info) && isset($info[2]) && is_string($info[2]) ? $info[2] : 'query failed';
    }
}
