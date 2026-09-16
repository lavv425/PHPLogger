<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Support;

use Logger\Support\FailSafe;
use PHPUnit\Framework\TestCase;

final class FailSafeTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'failsafe');
        self::assertIsString($path);
        $this->target = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->target)) {
            unlink($this->target);
        }
    }

    public function test_writes_one_ndjson_meta_record(): void
    {
        (new FailSafe($this->target, 3, 'billing-api', 'production'))->report('pipeline_failure', 'db_query');

        $lines = $this->lines();
        self::assertCount(1, $lines);

        $record = json_decode($lines[0], true);
        self::assertIsArray($record);
        self::assertSame(1, $record['schema_version']);
        self::assertSame('error', $record['level']);
        self::assertSame('billing-api', $record['service']);
        self::assertSame('production', $record['env']);
        self::assertSame('logger_error', $record['log_type']);
        self::assertSame('failure', $record['outcome']);
        self::assertFalse($record['success']);
        self::assertSame('pipeline_failure', $record['reason']);
        self::assertSame('db_query', $record['dropped_log_type']);
    }

    public function test_carries_no_caller_supplied_data(): void
    {
        (new FailSafe($this->target, 3, 'svc', 'test'))->report('pipeline_failure', 'db_query');

        $record = json_decode($this->lines()[0], true);
        self::assertIsArray($record);

        // Whatever the application was logging must not reach this record: the
        // gate never ran on it.
        self::assertArrayNotHasKey('data', $record);
        self::assertArrayNotHasKey('error', $record);
        self::assertArrayNotHasKey('message', $record);
    }

    /** @dataProvider unsafeTokens */
    public function test_free_form_values_are_reduced_to_a_placeholder(string $value): void
    {
        (new FailSafe($this->target, 3, 'svc', 'test'))->report($value, $value);

        $record = json_decode($this->lines()[0], true);
        self::assertIsArray($record);
        self::assertSame('unknown', $record['reason']);
        self::assertSame('unknown', $record['dropped_log_type']);
    }

    /** @return array<string, array{string}> */
    public function unsafeTokens(): array
    {
        return [
            'an email address' => ['user@example.com'],
            'a sentence' => ['something went wrong while logging'],
            'empty' => [''],
            'json' => ['{"token":"secret"}'],
        ];
    }

    public function test_is_rate_limited_per_request(): void
    {
        $failSafe = new FailSafe($this->target, 2, 'svc', 'test');

        for ($i = 0; $i < 10; ++$i) {
            $failSafe->report('pipeline_failure', 'db_query');
        }

        self::assertCount(2, $this->lines(), 'a broken pipeline must not flood the destination');
        self::assertSame(2, $failSafe->emitted());
    }

    public function test_a_zero_budget_disables_reporting_entirely(): void
    {
        $failSafe = new FailSafe($this->target, 0, 'svc', 'test');
        $failSafe->report('pipeline_failure', 'db_query');

        self::assertSame([], $this->lines());
        self::assertSame(0, $failSafe->emitted());
    }

    public function test_an_unwritable_target_is_swallowed(): void
    {
        $failSafe = new FailSafe('/this/path/does/not/exist/failsafe.ndjson', 3, 'svc', 'test');

        $failSafe->report('pipeline_failure', 'db_query');

        // Losing a meta record is preferable to throwing from the failure path.
        self::assertSame(1, $failSafe->emitted(), 'the attempt still counts against the budget');
    }

    /** @return string[] */
    private function lines(): array
    {
        $contents = file_get_contents($this->target);
        self::assertIsString($contents);

        return $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));
    }
}
