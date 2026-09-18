<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Contract;

use Logger\Contract\LogError;
use Logger\Contract\LogRecord;
use Logger\Enum\ErrorType;
use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class LogRecordTest extends TestCase
{
    public function test_field_order_is_part_of_the_contract(): void
    {
        $record = RecordBuilder::make(['database' => 'billing']);

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
        ], array_keys($record->toArray()));
    }

    public function test_renders_the_envelope(): void
    {
        $rendered = RecordBuilder::make(['database' => 'billing'], Level::WARNING, 'db_query', Outcome::SUCCESS, 0.25)->toArray();

        self::assertSame(LogRecord::SCHEMA_VERSION, $rendered['schema_version']);
        self::assertSame(RecordBuilder::TIMESTAMP, $rendered['timestamp']);
        self::assertSame('warning', $rendered['level']);
        self::assertSame('test-service', $rendered['service']);
        self::assertSame('testing', $rendered['env']);
        self::assertSame('test-host', $rendered['host']);
        self::assertSame('db_query', $rendered['log_type']);
        self::assertSame('test_event', $rendered['event']);
        self::assertSame('success', $rendered['outcome']);
        self::assertTrue($rendered['success']);
        self::assertSame(0.25, $rendered['duration']);
        self::assertNull($rendered['error']);
    }

    public function test_correlation_keys_are_always_present_even_when_unset(): void
    {
        // Schema stability: a collector must not have to deal with a field that
        // appears only on some lines.
        $rendered = RecordBuilder::make()->toArray();

        foreach (['request_id', 'session_ref', 'user_ref', 'user_agent'] as $key) {
            self::assertArrayHasKey($key, $rendered);
            self::assertNull($rendered[$key]);
        }
    }

    public function test_correlation_values_are_copied_into_the_envelope(): void
    {
        $rendered = RecordBuilder::make([], Level::INFO, 'db_query', Outcome::SUCCESS, null, null, ['request_id' => 'req_abc', 'user_ref' => 'a1b2c3'])->toArray();

        self::assertSame('req_abc', $rendered['request_id']);
        self::assertSame('a1b2c3', $rendered['user_ref']);
        self::assertNull($rendered['session_ref']);
    }

    public function test_an_unknown_outcome_leaves_the_success_flag_null(): void
    {
        $rendered = RecordBuilder::make([], Level::INFO, 'php_log', Outcome::UNKNOWN)->toArray();

        self::assertNull($rendered['success']);
    }

    public function test_duration_is_rounded_to_microseconds(): void
    {
        $rendered = RecordBuilder::make([], Level::INFO, 'db_query', Outcome::SUCCESS, 0.12345678)->toArray();

        self::assertSame(0.123457, $rendered['duration']);
    }

    public function test_data_is_rendered_as_an_object_so_an_empty_one_is_not_a_list(): void
    {
        $rendered = RecordBuilder::make()->toArray();

        self::assertIsObject($rendered['data']);
        self::assertSame('{}', json_encode($rendered['data']));
    }

    public function test_the_truncated_marker_only_appears_when_set(): void
    {
        self::assertArrayNotHasKey('_truncated', RecordBuilder::make()->toArray());

        $flagged = RecordBuilder::make()->withTruncated(true)->toArray();
        self::assertTrue($flagged['_truncated']);
        self::assertSame('_truncated', array_key_last($flagged), 'the marker is appended, not interleaved');
    }

    public function test_the_error_is_rendered_through_its_own_shape(): void
    {
        $error = new LogError(ErrorType::DB_ERROR, 'deadlock', '40001');

        $rendered = RecordBuilder::make([], Level::ERROR, 'db_query', Outcome::FAILURE, null, $error)->toArray();

        self::assertSame($error->toArray(), $rendered['error']);
    }

    public function test_with_methods_return_copies(): void
    {
        $original = RecordBuilder::make(['a' => 1]);

        $modified = $original->withData(['b' => 2])->withCorrelation(['request_id' => 'req_x'])->withTruncated(true);

        self::assertSame(['a' => 1], $original->data());
        self::assertSame([], $original->correlation());
        self::assertFalse($original->isTruncated());

        self::assertSame(['b' => 2], $modified->data());
        self::assertSame(['request_id' => 'req_x'], $modified->correlation());
        self::assertTrue($modified->isTruncated());
    }

    public function test_a_null_host_is_emitted_as_null(): void
    {
        $record = new LogRecord(RecordBuilder::timestamp(), Level::INFO, 'svc', 'test', null, 'db_query', 'e', Outcome::SUCCESS, null, null, [], []);

        self::assertNull($record->toArray()['host']);
        self::assertNull($record->host());
    }
}
