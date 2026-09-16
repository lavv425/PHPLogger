<?php

declare(strict_types=1);

namespace Logger\Tests\Integration;

use Logger\Contract\LogRecord;
use Logger\Payload\DbQueryPayload;
use Logger\Payload\PhpLogPayload;
use Logger\Tests\Support\LoggerHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the wire format.
 *
 * Any change to a field name, type or order fails here on purpose: the
 * compatibility policy in SCHEMA.md makes that a breaking change for every
 * collector already parsing these lines.
 */
final class SchemaContractTest extends TestCase
{
    private LoggerHarness $harness;

    protected function setUp(): void
    {
        $this->harness = LoggerHarness::create(['routing' => [], 'pepper' => 's3cr3t']);
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    public function test_the_envelope_keys_and_their_order_are_stable(): void
    {
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertSame([
            'schema_version',
            'timestamp',
            'level',
            'service',
            'env',
            'host',
            'log_type',
            'event',
            'outcome',
            'success',
            'duration',
            'error',
            'request_id',
            'session_ref',
            'user_ref',
            'user_agent',
            'data',
        ], array_keys($this->harness->lastRecord()));
    }

    public function test_the_schema_version_is_emitted_on_every_record(): void
    {
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertSame(1, LogRecord::SCHEMA_VERSION);
        self::assertSame(1, $this->harness->lastRecord()['schema_version']);
    }

    public function test_the_timestamp_is_iso8601_with_microseconds_in_utc(): void
    {
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertSame('2024-03-01T10:20:30.000000+00:00', $this->harness->lastRecord()['timestamp']);
    }

    public function test_the_error_object_keys_and_their_order_are_stable(): void
    {
        $this->harness->logger()->log(PhpLogPayload::fromThrowable('uncaught_exception', new RuntimeException('boom')));

        self::assertSame([
            'type',
            'code',
            'message',
            'class',
            'severity',
            'severity_code',
            'stack_trace',
        ], array_keys($this->harness->lastRecord()['error']));
    }

    /** @dataProvider envelopeTypes */
    public function test_each_envelope_field_keeps_its_type(string $field, string $type): void
    {
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withDuration(0.5)->withSuccess());

        self::assertSame($type, gettype($this->harness->lastRecord()[$field]));
    }

    /** @return array<string, array{string, string}> */
    public function envelopeTypes(): array
    {
        return [
            'schema_version is an integer' => ['schema_version', 'integer'],
            'timestamp is a string' => ['timestamp', 'string'],
            'level is a string' => ['level', 'string'],
            'service is a string' => ['service', 'string'],
            'env is a string' => ['env', 'string'],
            'log_type is a string' => ['log_type', 'string'],
            'event is a string' => ['event', 'string'],
            'outcome is a string' => ['outcome', 'string'],
            'success is a boolean' => ['success', 'boolean'],
            'duration is a double' => ['duration', 'double'],
            'data is an array once decoded' => ['data', 'array'],
        ];
    }

    public function test_the_db_query_payload_shape_is_stable(): void
    {
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withStatement('SELECT 1')->withRowCount(1)->withSuccess());

        self::assertSame([
            'database',
            'operation',
            'statement',
            'params',
            'row_count',
            'statement_hash',
        ], array_keys($this->harness->lastRecord()['data']));
    }

    public function test_the_php_log_payload_shape_is_stable(): void
    {
        $this->harness->logger()->log(PhpLogPayload::create('custom_log', 'hello'));

        self::assertSame([
            'message',
            'file',
            'line',
            'class',
            'function',
            'memory_usage',
            'context',
        ], array_keys($this->harness->lastRecord()['data']));
    }

    public function test_an_absent_optional_field_is_null_rather_than_missing(): void
    {
        // A collector must not have to deal with a field that appears only on
        // some lines.
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        $record = $this->harness->lastRecord();

        foreach (['host', 'duration', 'error', 'session_ref', 'user_ref', 'user_agent'] as $field) {
            self::assertArrayHasKey($field, $record);
        }

        self::assertNull($record['duration']);
        self::assertNull($record['error']);
    }

    public function test_the_truncation_marker_is_the_only_conditional_field(): void
    {
        $this->harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertArrayNotHasKey('_truncated', $this->harness->lastRecord());
    }
}
