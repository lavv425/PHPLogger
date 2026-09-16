<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Processor;

use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Processor\SamplingProcessor;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class SamplingProcessorTest extends TestCase
{
    public function test_an_unlisted_log_type_is_always_kept(): void
    {
        $processor = new SamplingProcessor(['db_query' => 0.0]);

        self::assertNotNull($processor->process(RecordBuilder::make([], Level::INFO, 'service_call')));
    }

    public function test_a_full_rate_keeps_everything(): void
    {
        $processor = new SamplingProcessor(['db_query' => 1.0]);

        for ($i = 0; $i < 50; ++$i) {
            self::assertNotNull($processor->process(RecordBuilder::make([], Level::INFO, 'db_query')));
        }
    }

    public function test_a_zero_rate_drops_the_boring_records(): void
    {
        $processor = new SamplingProcessor(['db_query' => 0.0]);

        self::assertNull($processor->process(RecordBuilder::make([], Level::INFO, 'db_query')));
        self::assertNull($processor->process(RecordBuilder::make([], Level::DEBUG, 'db_query')));
        self::assertNull($processor->process(RecordBuilder::make([], Level::WARNING, 'db_query')));
    }

    public function test_a_failure_is_never_sampled_away(): void
    {
        $processor = new SamplingProcessor(['db_query' => 0.0]);

        $record = RecordBuilder::make([], Level::INFO, 'db_query', Outcome::FAILURE);

        self::assertNotNull($processor->process($record), 'sampling must not hide the interesting records');
    }

    /** @dataProvider highLevels */
    public function test_anything_at_error_level_or_above_is_never_sampled_away(string $level): void
    {
        $processor = new SamplingProcessor(['db_query' => 0.0]);

        self::assertNotNull($processor->process(RecordBuilder::make([], $level, 'db_query', Outcome::SUCCESS)));
    }

    /** @return array<string, array{string}> */
    public function highLevels(): array
    {
        return [
            'error' => [Level::ERROR],
            'critical' => [Level::CRITICAL],
            'alert' => [Level::ALERT],
            'emergency' => [Level::EMERGENCY],
        ];
    }

    public function test_a_partial_rate_keeps_a_plausible_share(): void
    {
        $processor = new SamplingProcessor(['db_query' => 0.5]);
        $kept = 0;

        for ($i = 0; $i < 2000; ++$i) {
            if ($processor->process(RecordBuilder::make([], Level::INFO, 'db_query')) !== null) {
                ++$kept;
            }
        }

        // A wide band: this asserts the rate is applied at all, without turning
        // a random draw into a flaky test.
        self::assertGreaterThan(800, $kept);
        self::assertLessThan(1200, $kept);
    }

    public function test_an_empty_configuration_keeps_everything(): void
    {
        $processor = new SamplingProcessor([]);

        self::assertNotNull($processor->process(RecordBuilder::make([], Level::DEBUG, 'db_query')));
    }
}
