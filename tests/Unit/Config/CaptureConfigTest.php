<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Config;

use Logger\Config\CaptureConfig;
use Logger\Exception\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class CaptureConfigTest extends TestCase
{
    public function test_denies_by_default(): void
    {
        $capture = CaptureConfig::fromArray([]);

        self::assertSame([], $capture->allowedKeys('db_query', 'params'));
        self::assertFalse($capture->isRawStatementEnabled());
    }

    public function test_an_unconfigured_log_type_or_field_allows_nothing(): void
    {
        $capture = CaptureConfig::fromArray(['fields' => ['db_query' => ['params' => ['tenant_id']]]]);

        self::assertSame(['tenant_id'], $capture->allowedKeys('db_query', 'params'));
        self::assertSame([], $capture->allowedKeys('db_query', 'payload'), 'another field of the same type');
        self::assertSame([], $capture->allowedKeys('service_call', 'params'), 'another log type');
    }

    public function test_allowed_keys_are_reindexed(): void
    {
        $capture = CaptureConfig::fromArray(['fields' => ['db_query' => ['params' => [3 => 'a', 7 => 'b']]]]);

        self::assertSame(['a', 'b'], $capture->allowedKeys('db_query', 'params'));
    }

    public function test_the_raw_statement_escape_hatch_can_be_opened_explicitly(): void
    {
        self::assertTrue(CaptureConfig::fromArray(['raw_statement' => true])->isRawStatementEnabled());
    }

    /** @dataProvider brokenCaptures */
    public function test_refuses_a_broken_capture_section(array $config, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        CaptureConfig::fromArray($config);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function brokenCaptures(): array
    {
        return [
            'raw_statement is not a boolean' => [['raw_statement' => 'yes'], 'capture.raw_statement'],
            'fields is not an array' => [['fields' => 'all'], 'capture.fields'],
            'log type maps to a scalar' => [['fields' => ['db_query' => 'params']], 'map log types to field lists'],
            'field maps to a scalar' => [['fields' => ['db_query' => ['params' => 'tenant_id']]], 'capture.fields.db_query'],
            'allowed key is not a string' => [['fields' => ['db_query' => ['params' => [42]]]], 'capture.fields.db_query.params'],
        ];
    }
}
