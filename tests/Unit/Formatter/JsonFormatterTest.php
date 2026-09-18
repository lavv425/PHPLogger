<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Formatter;

use Logger\Exception\SanitizationFailure;
use Logger\Formatter\JsonFormatter;
use Logger\Interfaces\Formatter\FormatterInterface;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new JsonFormatter();
    }

    public function test_implements_the_formatter_contract(): void
    {
        self::assertInstanceOf(FormatterInterface::class, $this->formatter);
    }

    public function test_emits_one_newline_terminated_line(): void
    {
        $line = $this->formatter->format(RecordBuilder::make(['database' => 'billing']));

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"), 'NDJSON means exactly one record per line');
    }

    public function test_produces_decodable_json(): void
    {
        $decoded = json_decode($this->formatter->format(RecordBuilder::make(['database' => 'billing'])), true);

        self::assertIsArray($decoded);
        self::assertSame('billing', $decoded['data']['database']);
    }

    public function test_a_multiline_value_stays_on_one_line(): void
    {
        $line = $this->formatter->format(RecordBuilder::make(['note' => "first\nsecond"]));

        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringContainsString('first\nsecond', $line, 'the newline is escaped, not emitted');
    }

    public function test_keeps_unicode_readable(): void
    {
        $line = $this->formatter->format(RecordBuilder::make(['note' => 'caffè è così']));

        self::assertStringContainsString('caffè è così', $line);
    }

    public function test_does_not_escape_slashes(): void
    {
        $line = $this->formatter->format(RecordBuilder::make(['url' => 'https://example.com/v1/items']));

        self::assertStringContainsString('https://example.com/v1/items', $line);
        self::assertStringNotContainsString('\\/', $line);
    }

    public function test_a_zero_duration_stays_a_float(): void
    {
        // Without JSON_PRESERVE_ZERO_FRACTION the collector would see the same
        // field as int on one line and float on the next.
        $line = $this->formatter->format(RecordBuilder::make([], 'info', 'db_query', 'success', 0.0));

        self::assertStringContainsString('"duration":0.0', $line);
    }

    public function test_a_duration_is_not_mangled_by_serialize_precision(): void
    {
        $previous = ini_set('serialize_precision', '17');

        try {
            $line = (new JsonFormatter())->format(RecordBuilder::make([], 'info', 'db_query', 'success', 0.045));

            self::assertStringContainsString('"duration":0.045', $line);
            self::assertStringNotContainsString('0.04499', $line);
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    public function test_restores_the_precision_setting_it_changed(): void
    {
        $previous = ini_set('serialize_precision', '17');

        try {
            (new JsonFormatter())->format(RecordBuilder::make());

            self::assertSame('17', ini_get('serialize_precision'));
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    public function test_an_empty_data_object_is_not_rendered_as_a_list(): void
    {
        self::assertStringContainsString('"data":{}', $this->formatter->format(RecordBuilder::make()));
    }

    public function test_a_record_that_cannot_be_encoded_is_refused_rather_than_degraded(): void
    {
        // INF has no JSON representation. Writing a partial record would put
        // content on the wire that never went through the gate.
        $this->expectException(SanitizationFailure::class);
        $this->expectExceptionMessage('record is not encodable');

        $this->formatter->format(RecordBuilder::make(['ratio' => INF]));
    }

    public function test_invalid_utf8_is_substituted_instead_of_losing_the_record(): void
    {
        $line = $this->formatter->format(RecordBuilder::make(['note' => "broken \xFF sequence"]));

        self::assertIsArray(json_decode($line, true));
    }
}
