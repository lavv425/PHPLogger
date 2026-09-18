<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Sanitize;

use Logger\Config\LimitsConfig;
use Logger\Contract\LogError;
use Logger\Enum\ErrorType;
use Logger\Formatter\JsonFormatter;
use Logger\Sanitize\Truncator;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class TruncatorTest extends TestCase
{
    public function test_a_record_within_the_budget_is_returned_untouched(): void
    {
        $record = RecordBuilder::make(['note' => 'small']);

        $truncated = (new Truncator($this->limits(4096)))->truncate($record);

        self::assertFalse($truncated->isTruncated());
        self::assertSame(['note' => 'small'], $truncated->data());
    }

    public function test_sacrifices_the_payload_first(): void
    {
        $record = RecordBuilder::make([
            'payload' => array_fill(0, 50, str_repeat('x', 40)),
            'kept' => 'value',
        ]);

        $truncated = (new Truncator($this->limits(700)))->truncate($record);

        self::assertTrue($truncated->isTruncated());
        self::assertSame(['_omitted' => 50], $truncated->data()['payload']);
        self::assertSame('value', $truncated->data()['kept'], 'fields outside the drop order survive');
    }

    public function test_follows_the_drop_order_until_the_record_fits(): void
    {
        $record = RecordBuilder::make([
            'payload' => array_fill(0, 30, str_repeat('p', 40)),
            'context' => array_fill(0, 30, str_repeat('c', 40)),
            'params' => array_fill(0, 30, str_repeat('a', 40)),
        ]);

        $truncated = (new Truncator($this->limits(600)))->truncate($record);

        self::assertTrue($truncated->isTruncated());
        self::assertSame(['_omitted' => 30], $truncated->data()['payload']);
        self::assertSame(['_omitted' => 30], $truncated->data()['context']);
        self::assertSame(['_omitted' => 30], $truncated->data()['params']);
    }

    public function test_a_scalar_field_in_the_drop_order_becomes_null(): void
    {
        $record = RecordBuilder::make(['statement_raw' => str_repeat('x', 2000)]);

        $truncated = (new Truncator($this->limits(600)))->truncate($record);

        self::assertTrue($truncated->isTruncated());
        self::assertNull($truncated->data()['statement_raw']);
    }

    public function test_trims_the_stack_trace_to_the_frame_limit(): void
    {
        $error = new LogError(ErrorType::EXCEPTION, 'boom', null, 'RuntimeException', null, null, [
            '#0 a.php(1): a()',
            '#1 b.php(2): b()',
            '#2 c.php(3): c()',
            '#3 d.php(4): d()',
        ]);

        $truncated = (new Truncator(new LimitsConfig(4096, 1024, 2, 4)))->truncate(RecordBuilder::make([], 'error', 'php_log', 'failure', null, $error));

        self::assertTrue($truncated->isTruncated());
        $trace = $truncated->error()->stackTrace();
        self::assertIsArray($trace);
        self::assertCount(2, $trace);
        self::assertSame('#0 a.php(1): a()', $trace[0]);
    }

    public function test_drops_the_stack_trace_entirely_when_the_record_is_still_too_large(): void
    {
        $frames = [];
        for ($i = 0; $i < 20; ++$i) {
            $frames[] = '#' . $i . ' ' . str_repeat('f', 60) . '.php(1): callSomething()';
        }

        $error = new LogError(ErrorType::EXCEPTION, 'boom', null, null, null, null, $frames);
        $record = RecordBuilder::make([], 'error', 'php_log', 'failure', null, $error);

        $truncated = (new Truncator(new LimitsConfig(600, 512, 20, 4)))->truncate($record);

        self::assertTrue($truncated->isTruncated());
        self::assertNull($truncated->error()->stackTrace());
    }

    public function test_shrinks_long_strings_as_a_last_resort(): void
    {
        $record = RecordBuilder::make(['note' => str_repeat('x', 5000)]);

        $truncated = (new Truncator($this->limits(600)))->truncate($record);

        self::assertLessThan(5000, strlen($truncated->data()['note']));
    }

    public function test_a_record_saved_by_shrinking_alone_is_still_flagged(): void
    {
        // Shrinking loses data like dropping a field does, so the consumer has
        // to be told even when nothing else was sacrificed.
        $record = RecordBuilder::make(['note' => str_repeat('x', 5000)]);

        $truncated = (new Truncator($this->limits(600)))->truncate($record);

        self::assertTrue($truncated->isTruncated());
    }

    public function test_a_record_that_needed_no_shrinking_is_not_flagged(): void
    {
        $record = RecordBuilder::make(['note' => 'short', 'count' => 3]);

        $truncated = (new Truncator($this->limits(4096)))->truncate($record);

        self::assertFalse($truncated->isTruncated());
        self::assertSame(['note' => 'short', 'count' => 3], $truncated->data());
    }

    public function test_an_oversized_error_message_is_shrunk_and_flagged(): void
    {
        $error = new LogError(ErrorType::EXCEPTION, str_repeat('boom ', 400));

        $truncated = (new Truncator($this->limits(600)))->truncate(RecordBuilder::make([], 'error', 'php_log', 'failure', null, $error));

        self::assertTrue($truncated->isTruncated());
        self::assertLessThan(2000, strlen($truncated->error()->message()));
    }

    public function test_flags_a_record_that_stays_oversized_after_every_strategy(): void
    {
        $record = RecordBuilder::make(['note' => str_repeat('x', 5000)]);

        $truncated = (new Truncator(new LimitsConfig(200, 128, 20, 4)))->truncate($record);

        self::assertTrue($truncated->isTruncated());
    }

    public function test_measures_with_the_formatter_that_will_actually_write(): void
    {
        // The size that matters is the size on the wire, so the truncator is
        // handed the real formatter rather than estimating.
        $record = RecordBuilder::make(['note' => 'small']);

        $truncator = new Truncator($this->limits(4096), new JsonFormatter());

        self::assertFalse($truncator->truncate($record)->isTruncated());
    }

    private function limits(int $maxRecordBytes): LimitsConfig
    {
        return new LimitsConfig($maxRecordBytes, min(1024, $maxRecordBytes), 20, 4);
    }
}
