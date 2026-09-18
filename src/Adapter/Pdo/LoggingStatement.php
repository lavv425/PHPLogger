<?php

declare(strict_types=1);

namespace Logger\Adapter\Pdo;

use Logger\Support\Stopwatch;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * A prepared statement that reports what it did.
 *
 * Instantiated by PDO itself through ATTR_STATEMENT_CLASS, which is why the
 * constructor is protected and takes its collaborators as attribute arguments.
 *
 * Values bound through bindValue()/bindParam() are captured too, otherwise only
 * the ones passed straight to execute() would ever be visible. What actually
 * reaches the log is still decided by capture.fields: this only makes the
 * values available to the gate.
 */
final class LoggingStatement extends PDOStatement
{
    private QueryRecorder $recorder;
    /** @var array<string|int, mixed> */
    private array $bound = [];

    protected function __construct(QueryRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    /**
     * @param array<string|int, mixed>|null $params
     */
    public function execute($params = null): bool
    {
        $stopwatch = Stopwatch::start();
        $statement = (string) $this->queryString;
        $used = is_array($params) && $params !== [] ? $params : ($this->bound === [] ? null : $this->bound);

        try {
            $executed = parent::execute($params);
        } catch (PDOException $exception) {
            // Recorded before the rethrow. With ERRMODE_EXCEPTION every failed
            // query leaves through here, so logging after the call would lose
            // exactly the records worth having.
            $this->recorder->failure($statement, $used, $stopwatch->elapsed(), QueryRecorder::describe($exception));

            throw $exception;
        }

        $duration = $stopwatch->elapsed();

        if ($executed === false) {
            // ERRMODE_SILENT or ERRMODE_WARNING: the failure is a return value.
            $this->recorder->failure($statement, $used, $duration, QueryRecorder::describe(new PDOException($this->errorMessage())));

            return false;
        }

        $this->recorder->success($statement, $used, $duration, $this->safeRowCount());

        return true;
    }

    /**
     * @param string|int $param
     * @param mixed $value
     * @param int $type
     */
    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    /**
     * @param string|int $param
     * @param mixed $var
     * @param int $type
     * @param int|null $maxLength
     * @param mixed $driverOptions
     */
    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = null, $driverOptions = null): bool
    {
        // Kept by reference: bindParam reads the variable at execute time, so
        // storing a copy now would log a value that was never sent.
        $this->bound[$param] = &$var;

        // Forwarded only when supplied: PHP 8 types maxLength as int, so
        // passing the null default straight through would be a TypeError.
        if ($driverOptions !== null) {
            return parent::bindParam($param, $var, $type, (int) $maxLength, $driverOptions);
        }

        if ($maxLength !== null) {
            return parent::bindParam($param, $var, $type, (int) $maxLength);
        }

        return parent::bindParam($param, $var, $type);
    }

    private function safeRowCount(): ?int
    {
        try {
            // Meaningful for DML; for a SELECT it is driver dependent and may
            // be 0 even when rows were returned.
            return parent::rowCount();
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private function errorMessage(): string
    {
        $info = parent::errorInfo();

        return is_array($info) && isset($info[2]) && is_string($info[2]) ? $info[2] : 'statement execution failed';
    }
}
