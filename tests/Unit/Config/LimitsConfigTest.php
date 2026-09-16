<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Config;

use Logger\Config\LimitsConfig;
use Logger\Exception\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class LimitsConfigTest extends TestCase
{
    public function test_applies_the_documented_defaults(): void
    {
        $limits = LimitsConfig::fromArray([]);

        // 4096 is PIPE_BUF: above it, concurrent FPM workers writing to the
        // same stdout can interleave and produce unparseable lines.
        self::assertSame(4096, $limits->maxRecordBytes());
        self::assertSame(1024, $limits->maxFieldBytes());
        self::assertSame(20, $limits->maxStackFrames());
        self::assertSame(4, $limits->maxDepth());
    }

    public function test_reads_explicit_values(): void
    {
        $limits = LimitsConfig::fromArray(['max_record_bytes' => 8192, 'max_field_bytes' => 512, 'max_stack_frames' => 5, 'max_depth' => 2]);

        self::assertSame(8192, $limits->maxRecordBytes());
        self::assertSame(512, $limits->maxFieldBytes());
        self::assertSame(5, $limits->maxStackFrames());
        self::assertSame(2, $limits->maxDepth());
    }

    /** @dataProvider invalidLimits */
    public function test_every_limit_must_be_a_positive_integer(string $key, $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('limits.' . $key);

        LimitsConfig::fromArray([$key => $value]);
    }

    /** @return array<string, array{string, mixed}> */
    public function invalidLimits(): array
    {
        return [
            'zero record bytes' => ['max_record_bytes', 0],
            'negative record bytes' => ['max_record_bytes', -1],
            'record bytes as string' => ['max_record_bytes', '4096'],
            'record bytes as float' => ['max_record_bytes', 4096.0],
            'zero field bytes' => ['max_field_bytes', 0],
            'zero stack frames' => ['max_stack_frames', 0],
            'zero depth' => ['max_depth', 0],
        ];
    }

    public function test_a_field_cannot_be_allowed_to_exceed_the_whole_record(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must not exceed limits.max_record_bytes');

        LimitsConfig::fromArray(['max_record_bytes' => 512, 'max_field_bytes' => 1024]);
    }

    public function test_a_field_budget_equal_to_the_record_budget_is_allowed(): void
    {
        $limits = LimitsConfig::fromArray(['max_record_bytes' => 512, 'max_field_bytes' => 512]);

        self::assertSame(512, $limits->maxFieldBytes());
    }
}
