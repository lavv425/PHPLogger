<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Adapter;

use Logger\Adapter\Pdo\LoggingPdo;
use Logger\Adapter\Pdo\LoggingStatement;
use Logger\Enum\ErrorType;
use Logger\Enum\Outcome;
use Logger\Tests\Support\LoggerHarness;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class LoggingPdoTest extends TestCase
{
    private LoggerHarness $harness;
    private LoggingPdo $pdo;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required to exercise the adapter against a real driver.');
        }
    }

    protected function setUp(): void
    {
        $this->harness = LoggerHarness::create(['capture_fields' => ['db_query' => ['params' => ['0', '1', ':email', ':id']]]]);
        $this->pdo = $this->connect();
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $this->harness->handler()->clear();
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    public function test_is_usable_wherever_a_pdo_is_expected(): void
    {
        // The whole reason for a subclass instead of a decorator: existing
        // signatures keep working.
        self::assertInstanceOf(PDO::class, $this->pdo);
    }

    public function test_prepared_statements_are_the_logging_ones(): void
    {
        self::assertInstanceOf(LoggingStatement::class, $this->pdo->prepare('SELECT 1'));
    }

    public function test_logs_a_successful_prepared_statement(): void
    {
        $this->pdo->prepare('INSERT INTO users (email) VALUES (?)')->execute(['marco@example.com']);

        $record = $this->harness->lastRecord();
        self::assertSame('db_query', $record['log_type']);
        self::assertSame(Outcome::SUCCESS, $record['outcome']);
        self::assertSame('INSERT', $record['data']['operation']);
        self::assertSame('billing', $record['data']['database']);
        self::assertSame(1, $record['data']['row_count']);
        self::assertIsFloat($record['duration']);
    }

    public function test_the_statement_is_fingerprinted_not_quoted(): void
    {
        $this->pdo->query("SELECT * FROM users WHERE email = 'marco@example.com'");

        $record = $this->harness->lastRecord();
        self::assertSame('SELECT * FROM users WHERE email = ?', $record['data']['statement']);
        self::assertStringNotContainsString('marco@example.com', $this->harness->lines()[0]);
    }

    public function test_logs_exec(): void
    {
        $this->pdo->exec("INSERT INTO users (email) VALUES ('a@b.it')");

        $record = $this->harness->lastRecord();
        self::assertSame(Outcome::SUCCESS, $record['outcome']);
        self::assertSame('INSERT', $record['data']['operation']);
        self::assertSame(1, $record['data']['row_count'], 'exec reports the affected rows');
    }

    public function test_logs_query(): void
    {
        $this->pdo->query('SELECT * FROM users');

        $record = $this->harness->lastRecord();
        self::assertSame(Outcome::SUCCESS, $record['outcome']);
        self::assertSame('SELECT', $record['data']['operation']);
    }

    public function test_a_failure_is_recorded_before_the_exception_is_rethrown(): void
    {
        // The whole point of the try/catch: with ERRMODE_EXCEPTION the failed
        // queries are precisely the ones a naive wrapper loses.
        try {
            $this->pdo->prepare('SELECT * FROM missing_table')->execute();
            self::fail('the driver should have refused this query');
        } catch (PDOException $exception) {
            self::assertStringContainsString('missing_table', $exception->getMessage());
        }

        $record = $this->harness->lastRecord();
        self::assertNotNull($record, 'the failure must have been logged');
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertFalse($record['success']);
        self::assertSame('error', $record['level']);
        self::assertSame(ErrorType::DB_ERROR, $record['error']['type']);
    }

    public function test_the_original_exception_reaches_the_caller_untouched(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec('THIS IS NOT SQL');
    }

    public function test_a_failing_exec_is_logged(): void
    {
        try {
            $this->pdo->exec('THIS IS NOT SQL');
        } catch (PDOException $exception) {
            // Expected; the assertion is about what was recorded.
        }

        self::assertSame(Outcome::FAILURE, $this->harness->lastRecord()['outcome']);
    }

    public function test_a_failing_query_is_logged(): void
    {
        try {
            $this->pdo->query('SELECT * FROM missing_table');
        } catch (PDOException $exception) {
            // Expected.
        }

        self::assertSame(Outcome::FAILURE, $this->harness->lastRecord()['outcome']);
    }

    public function test_the_sqlstate_is_carried_over(): void
    {
        try {
            $this->pdo->exec('THIS IS NOT SQL');
        } catch (PDOException $exception) {
            // Expected.
        }

        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', (string) $this->harness->lastRecord()['error']['code']);
    }

    public function test_values_bound_with_bind_value_are_captured(): void
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email) VALUES (:email)');
        $statement->bindValue(':email', 'tenant-a');
        $statement->execute();

        // Without the bindValue override only parameters passed to execute()
        // would ever be visible.
        self::assertSame(['@email' => 'tenant-a'], $this->renameKeys($this->harness->lastRecord()['data']['params']));
    }

    public function test_values_bound_with_bind_param_are_read_at_execution_time(): void
    {
        $email = 'prima';
        $statement = $this->pdo->prepare('INSERT INTO users (email) VALUES (:email)');
        $statement->bindParam(':email', $email);
        $email = 'dopo';
        $statement->execute();

        // bindParam binds a reference: logging the value captured at bind time
        // would report something that was never sent to the database.
        self::assertSame(['@email' => 'dopo'], $this->renameKeys($this->harness->lastRecord()['data']['params']));
    }

    public function test_parameters_passed_to_execute_win_over_bound_ones(): void
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email) VALUES (?)');
        $statement->execute(['diretto']);

        self::assertSame(['0' => 'diretto'], $this->harness->lastRecord()['data']['params']);
    }

    public function test_the_event_name_is_configurable(): void
    {
        $harness = LoggerHarness::create();
        $pdo = new LoggingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], $harness->logger(), 'billing', 'user_lookup');

        $pdo->exec('CREATE TABLE t (id INTEGER)');

        self::assertSame('user_lookup', $harness->lastRecord()['event']);
        $harness->cleanUp();
    }

    public function test_a_logging_failure_never_becomes_a_database_failure(): void
    {
        // The logger is pointed at a channel that does not exist, so every
        // attempt to log blows up inside the pipeline.
        $harness = LoggerHarness::create(['routing' => ['db_query' => 'dead']]);
        $pdo = new LoggingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], $harness->logger(), 'billing');

        $pdo->exec('CREATE TABLE t (id INTEGER)');
        $affected = $pdo->exec('INSERT INTO t (id) VALUES (1)');

        self::assertSame(1, $affected, 'the query still ran and reported normally');
        $harness->cleanUp();
    }

    /**
     * SQLite reports named placeholders back with a ":" prefix, which the gate
     * keeps verbatim; the "@" rename only makes the expectation readable.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function renameKeys(array $params): array
    {
        $renamed = [];
        foreach ($params as $key => $value) {
            $renamed[str_replace(':', '@', (string) $key)] = $value;
        }

        return $renamed;
    }

    private function connect(): LoggingPdo
    {
        return new LoggingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], $this->harness->logger(), 'billing');
    }
}
