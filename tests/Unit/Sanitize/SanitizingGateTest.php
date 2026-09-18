<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Sanitize;

use Logger\Config\CaptureConfig;
use Logger\Config\LimitsConfig;
use Logger\Contract\LogError;
use Logger\Enum\ErrorType;
use Logger\Enum\LogType;
use Logger\Sanitize\SanitizingGate;
use Logger\Sanitize\Scrubber;
use Logger\Sanitize\StatementNormalizer;
use Logger\Sanitize\Truncator;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class SanitizingGateTest extends TestCase
{
    public function test_replaces_a_sql_statement_with_its_fingerprint(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make([
            'statement' => "SELECT * FROM users WHERE email = 'marco@example.com'",
        ]));

        $data = $record->data();
        self::assertSame('SELECT * FROM users WHERE email = ?', $data['statement']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['statement_hash']);
        self::assertArrayNotHasKey('statement_raw', $data);
    }

    public function test_the_raw_statement_is_only_emitted_when_explicitly_enabled(): void
    {
        $statement = "SELECT * FROM users WHERE id = 42";

        $record = $this->gate([], true)->apply(RecordBuilder::make(['statement' => $statement]));

        self::assertSame($statement, $record->data()['statement_raw']);
        self::assertSame('SELECT * FROM users WHERE id = ?', $record->data()['statement']);
    }

    public function test_the_statement_policy_only_applies_to_db_queries(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make(['statement' => 'SELECT 1'], 'info', LogType::PHP_LOG));

        self::assertArrayNotHasKey('statement_hash', $record->data());
    }

    public function test_a_non_string_statement_is_left_for_the_generic_rules(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make(['statement' => null]));

        self::assertNull($record->data()['statement']);
        self::assertArrayNotHasKey('statement_hash', $record->data());
    }

    public function test_array_fields_are_dropped_unless_allow_listed(): void
    {
        $record = $this->gate(['db_query' => ['params' => ['tenant_id']]])->apply(RecordBuilder::make([
            'params' => ['tenant_id' => 7, 'email' => 'marco@example.com', 'note' => 'free text'],
        ]));

        self::assertSame(['tenant_id' => 7, '_omitted' => 2], $record->data()['params']);
    }

    public function test_an_unconfigured_structure_is_dropped_whole(): void
    {
        // Deny by default: this is the structural guarantee, not best effort.
        $record = $this->gate()->apply(RecordBuilder::make([
            'params' => ['anything' => 'at all', 'more' => 'data'],
        ]));

        self::assertSame(['_omitted' => 2], $record->data()['params']);
    }

    public function test_an_allow_listed_key_is_still_dropped_when_its_name_is_sensitive(): void
    {
        $record = $this->gate(['db_query' => ['params' => ['tenant_id', 'password']]])->apply(RecordBuilder::make([
            'params' => ['tenant_id' => 7, 'password' => 'hunter2'],
        ]));

        self::assertSame(['tenant_id' => 7, '_omitted' => 1], $record->data()['params']);
        self::assertStringNotContainsString('hunter2', json_encode($record->toArray()));
    }

    public function test_an_empty_structure_reports_no_omission(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make(['params' => []]));

        self::assertSame([], $record->data()['params']);
    }

    public function test_a_sensitive_top_level_field_is_masked(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make(['token' => 'abc123', 'database' => 'billing']));

        self::assertSame(Scrubber::MASK, $record->data()['token']);
        self::assertSame('billing', $record->data()['database']);
    }

    public function test_a_null_sensitive_field_stays_null_instead_of_becoming_a_fake_mask(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make(['token' => null]));

        self::assertNull($record->data()['token']);
    }

    public function test_strings_are_scrubbed_and_capped(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make([
            'database' => 'contact marco@example.com',
            'note' => str_repeat('x', 200),
        ]));

        self::assertStringNotContainsString('marco@example.com', $record->data()['database']);
        self::assertLessThanOrEqual(64 + 3, strlen($record->data()['note']), 'capped at max_field_bytes');
    }

    public function test_correlation_values_are_scrubbed(): void
    {
        $record = $this->gate()->apply(RecordBuilder::make([], 'info', LogType::DB_QUERY, 'success', null, null, [
            'request_id' => 'req_abc',
            'user_agent' => 'Client marco@example.com',
            'session_ref' => null,
        ]));

        self::assertSame('req_abc', $record->correlation()['request_id']);
        self::assertStringNotContainsString('marco@example.com', $record->correlation()['user_agent']);
        self::assertNull($record->correlation()['session_ref']);
    }

    public function test_the_error_message_is_scrubbed(): void
    {
        $error = new LogError(ErrorType::EXCEPTION, 'login failed for marco@example.com');

        $record = $this->gate()->apply(RecordBuilder::make([], 'error', LogType::PHP_LOG, 'failure', null, $error));

        self::assertStringNotContainsString('marco@example.com', $record->error()->message());
    }

    public function test_stack_frames_are_scrubbed_and_limited(): void
    {
        $error = new LogError(ErrorType::EXCEPTION, 'boom', null, null, null, null, [
            '#0 /app/Auth.php(10): login(marco@example.com)',
            '#1 /app/Kernel.php(20): handle()',
            '#2 /app/index.php(30): run()',
        ]);

        $gate = new SanitizingGate(new CaptureConfig([], false), new Scrubber(), new Truncator(new LimitsConfig(4096, 1024, 2, 4)), new StatementNormalizer(), new LimitsConfig(4096, 1024, 2, 4));

        $record = $gate->apply(RecordBuilder::make([], 'error', LogType::PHP_LOG, 'failure', null, $error));

        $trace = $record->error()->stackTrace();
        self::assertCount(2, $trace);
        self::assertStringNotContainsString('marco@example.com', $trace[0]);
    }

    public function test_a_record_with_no_error_stays_without_one(): void
    {
        self::assertNull($this->gate()->apply(RecordBuilder::make(['database' => 'billing']))->error());
    }

    /**
     * @param array<string, array<string, string[]>> $fields
     */
    private function gate(array $fields = [], bool $rawStatement = false): SanitizingGate
    {
        $limits = new LimitsConfig(4096, 64, 20, 4);

        return new SanitizingGate(new CaptureConfig($fields, $rawStatement), new Scrubber(), new Truncator($limits), new StatementNormalizer(), $limits);
    }
}
