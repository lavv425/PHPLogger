<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Payload;

use Logger\Contract\LogError;
use Logger\Enum\DbOperation;
use Logger\Enum\Level;
use Logger\Enum\LogType;
use Logger\Enum\Outcome;
use Logger\Exception\InvalidLogEventException;
use Logger\Interfaces\Payload\LogPayloadInterface;
use Logger\Payload\DbQueryPayload;
use PHPUnit\Framework\TestCase;

final class DbQueryPayloadTest extends TestCase
{
    public function test_implements_the_payload_contract(): void
    {
        self::assertInstanceOf(LogPayloadInterface::class, DbQueryPayload::create('user_lookup', 'billing'));
    }

    public function test_starts_with_an_unknown_outcome(): void
    {
        $payload = DbQueryPayload::create('user_lookup', 'billing');

        self::assertSame(LogType::DB_QUERY, $payload->logType());
        self::assertSame('user_lookup', $payload->event());
        self::assertSame(Outcome::UNKNOWN, $payload->outcome());
        self::assertSame(Level::INFO, $payload->defaultLevel());
        self::assertNull($payload->duration());
        self::assertNull($payload->error());
    }

    public function test_renders_the_type_specific_fields(): void
    {
        $payload = DbQueryPayload::create('user_lookup', 'billing')->withStatement('SELECT id FROM users WHERE id = ?', ['id' => 7])->withRowCount(1);

        self::assertSame([
            'database' => 'billing',
            'operation' => DbOperation::SELECT,
            'statement' => 'SELECT id FROM users WHERE id = ?',
            'params' => ['id' => 7],
            'row_count' => 1,
        ], $payload->data());
    }

    public function test_the_operation_is_derived_from_the_statement(): void
    {
        $payload = DbQueryPayload::create('cleanup', 'billing')->withStatement('DELETE FROM sessions');

        self::assertSame(DbOperation::DELETE, $payload->data()['operation']);
    }

    public function test_the_operation_can_be_overridden(): void
    {
        $payload = DbQueryPayload::create('cleanup', 'billing')->withStatement('DELETE FROM sessions')->withOperation(DbOperation::TRUNCATE);

        self::assertSame(DbOperation::TRUNCATE, $payload->data()['operation']);
    }

    public function test_an_unknown_operation_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('unknown operation "MERGE"');

        DbQueryPayload::create('cleanup', 'billing')->withOperation('MERGE');
    }

    public function test_the_statement_is_stored_as_given_for_the_gate_to_normalize(): void
    {
        // Normalisation belongs to the sanitizing gate, not to the payload.
        $statement = "SELECT * FROM users WHERE email = 'marco@example.com'";

        self::assertSame($statement, DbQueryPayload::create('lookup', 'billing')->withStatement($statement)->data()['statement']);
    }

    public function test_an_empty_database_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('"database"');

        DbQueryPayload::create('user_lookup', '   ');
    }

    public function test_an_empty_statement_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('"statement"');

        DbQueryPayload::create('user_lookup', 'billing')->withStatement('  ');
    }

    public function test_an_event_name_carrying_free_form_data_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('"event"');

        DbQueryPayload::create('lookup for marco@example.com', 'billing');
    }

    public function test_every_with_method_returns_a_copy(): void
    {
        $original = DbQueryPayload::create('user_lookup', 'billing');

        $modified = $original->withStatement('SELECT 1')->withRowCount(3)->withSuccess()->withDuration(0.5);

        // PHP 7.4 has no named arguments, so the builder is the API: it has to
        // stay immutable or a shared payload would leak between call sites.
        self::assertNull($original->data()['statement']);
        self::assertNull($original->data()['row_count']);
        self::assertSame(Outcome::UNKNOWN, $original->outcome());
        self::assertNull($original->duration());

        self::assertSame('SELECT 1', $modified->data()['statement']);
        self::assertSame(3, $modified->data()['row_count']);
        self::assertSame(Outcome::SUCCESS, $modified->outcome());
        self::assertSame(0.5, $modified->duration());
    }

    public function test_a_failure_sets_both_the_error_and_the_outcome(): void
    {
        $error = LogError::fromDatabase('deadlock found', '40001');

        $payload = DbQueryPayload::create('user_lookup', 'billing')->withFailure($error);

        self::assertSame($error, $payload->error());
        self::assertSame(Outcome::FAILURE, $payload->outcome());
        self::assertSame(Level::ERROR, $payload->defaultLevel(), 'a failure defaults to error level');
    }

    public function test_an_error_can_be_attached_without_deciding_the_outcome(): void
    {
        // An HTTP 500 is an error for one caller and an expected answer for
        // another, so attaching one must not force the outcome.
        $payload = DbQueryPayload::create('user_lookup', 'billing')->withError(LogError::fromDatabase('lock wait'));

        self::assertNotNull($payload->error());
        self::assertSame(Outcome::UNKNOWN, $payload->outcome());
    }

    public function test_an_explicit_level_wins_over_the_outcome(): void
    {
        $payload = DbQueryPayload::create('user_lookup', 'billing')->withOutcome(Outcome::FAILURE)->withLevel(Level::WARNING);

        self::assertSame(Level::WARNING, $payload->defaultLevel());
    }

    public function test_an_unknown_level_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);

        DbQueryPayload::create('user_lookup', 'billing')->withLevel('verbose');
    }

    public function test_a_negative_duration_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('"duration"');

        DbQueryPayload::create('user_lookup', 'billing')->withDuration(-0.5);
    }
}
