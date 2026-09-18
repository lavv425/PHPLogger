<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Config;

use Logger\Config\Config;
use Logger\Exception\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_the_shipped_example_is_valid(): void
    {
        $config = Config::fromArray(require __DIR__ . '/../../../config/logger.example.php');

        self::assertSame('billing-api', $config->service());
        self::assertSame('production', $config->env());
        self::assertSame('stdout', $config->defaultChannel());
        self::assertArrayHasKey('errors', $config->channels());
        self::assertSame(['db_query' => 'stdout', 'service_call' => 'stdout', 'php_log' => 'errors'], $config->routing());
    }

    public function test_applies_the_documented_defaults(): void
    {
        $config = Config::fromArray($this->minimal());

        self::assertNull($config->host());
        self::assertFalse($config->strictEvents());
        self::assertSame('', $config->pepper());
        self::assertSame([], $config->routing());
        self::assertSame([], $config->sampling());
        self::assertSame('php://stderr', $config->failSafeTarget());
        self::assertSame(3, $config->failSafeMaxRecords());
        self::assertSame(5, $config->breakerFailureThreshold());
        self::assertSame(30, $config->breakerCooldownSeconds());
    }

    public function test_reads_the_optional_sections(): void
    {
        $config = Config::fromArray($this->minimal([
            'host' => 'web-01',
            'strict_events' => true,
            'pepper' => 's3cr3t',
            'sampling' => ['db_query' => 0.25],
            'fail_safe' => ['target' => 'php://stdout', 'max_records' => 1],
            'circuit_breaker' => ['failure_threshold' => 2, 'cooldown_seconds' => 5],
        ]));

        self::assertSame('web-01', $config->host());
        self::assertTrue($config->strictEvents());
        self::assertSame('s3cr3t', $config->pepper());
        self::assertSame(['db_query' => 0.25], $config->sampling());
        self::assertSame('php://stdout', $config->failSafeTarget());
        self::assertSame(1, $config->failSafeMaxRecords());
        self::assertSame(2, $config->breakerFailureThreshold());
        self::assertSame(5, $config->breakerCooldownSeconds());
    }

    /** @dataProvider brokenConfigurations */
    public function test_refuses_a_broken_configuration_at_boot(array $overrides, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        Config::fromArray($this->minimal($overrides));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function brokenConfigurations(): array
    {
        return [
            'missing service' => [['service' => null], 'at "service"'],
            'blank service' => [['service' => '   '], 'at "service"'],
            'missing env' => [['env' => null], 'at "env"'],
            'non string host' => [['host' => 42], 'at "host"'],
            'non boolean strict_events' => [['strict_events' => 'yes'], 'at "strict_events"'],
            'no channels' => [['channels' => []], 'must declare at least one channel'],
            'channels is not a map' => [['channels' => 'stdout'], 'must declare at least one channel'],
            'channel definition is not an array' => [['channels' => ['stdout' => 'stream']], 'map channel names'],
            'unknown default channel' => [['default_channel' => 'nope'], 'unknown channel "nope"'],
            'routing to an unknown channel' => [['routing' => ['db_query' => 'nope']], 'routing.db_query'],
            'routing is not an array' => [['routing' => 'stdout'], 'at "routing"'],
            'sampling above one' => [['sampling' => ['db_query' => 1.5]], 'between 0 and 1'],
            'sampling below zero' => [['sampling' => ['db_query' => -0.1]], 'between 0 and 1'],
            'sampling is not numeric' => [['sampling' => ['db_query' => 'half']], 'between 0 and 1'],
            'empty fail safe target' => [['fail_safe' => ['target' => '']], 'fail_safe.target'],
            'negative fail safe budget' => [['fail_safe' => ['max_records' => -1]], 'fail_safe.max_records'],
            'fail safe budget is not an int' => [['fail_safe' => ['max_records' => '3']], 'fail_safe.max_records'],
            'breaker threshold below one' => [['circuit_breaker' => ['failure_threshold' => 0]], 'failure_threshold'],
            'breaker cooldown below one' => [['circuit_breaker' => ['cooldown_seconds' => 0]], 'cooldown_seconds'],
        ];
    }

    public function test_a_sampling_rate_at_the_boundaries_is_accepted(): void
    {
        $config = Config::fromArray($this->minimal(['sampling' => ['a' => 0, 'b' => 1]]));

        self::assertSame(['a' => 0.0, 'b' => 1.0], $config->sampling());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function minimal(array $overrides = []): array
    {
        $config = [
            'service' => 'billing-api',
            'env' => 'testing',
            'default_channel' => 'stdout',
            'channels' => ['stdout' => ['handlers' => [['type' => 'memory']]]],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($config[$key]);
                continue;
            }

            $config[$key] = $value;
        }

        return $config;
    }
}
