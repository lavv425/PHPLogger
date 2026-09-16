<?php

declare(strict_types=1);

namespace Logger\Config;

use Logger\Exception\InvalidConfigurationException;

/**
 * Validated configuration.
 *
 * Values arrive already resolved: the package never reads getenv(), $_ENV or a
 * framework helper, so it stays usable outside any framework and testable
 * without touching the environment.
 */
final class Config
{
    private string $service;
    private string $env;
    private ?string $host;
    private bool $strictEvents;
    private string $pepper;
    private string $defaultChannel;
    /** @var array<string, ChannelConfig> */
    private array $channels;
    /** @var array<string, string> */
    private array $routing;
    /** @var array<string, float> */
    private array $sampling;
    private CaptureConfig $capture;
    private LimitsConfig $limits;
    private string $failSafeTarget;
    private int $failSafeMaxRecords;
    private int $breakerFailureThreshold;
    private int $breakerCooldownSeconds;

    /**
     * @param array<string, ChannelConfig> $channels
     * @param array<string, string> $routing
     * @param array<string, float> $sampling
     */
    private function __construct(string $service, string $env, ?string $host, bool $strictEvents, string $pepper, string $defaultChannel, array $channels, array $routing, array $sampling, CaptureConfig $capture, LimitsConfig $limits, string $failSafeTarget, int $failSafeMaxRecords, int $breakerFailureThreshold, int $breakerCooldownSeconds)
    {
        $this->service = $service;
        $this->env = $env;
        $this->host = $host;
        $this->strictEvents = $strictEvents;
        $this->pepper = $pepper;
        $this->defaultChannel = $defaultChannel;
        $this->channels = $channels;
        $this->routing = $routing;
        $this->sampling = $sampling;
        $this->capture = $capture;
        $this->limits = $limits;
        $this->failSafeTarget = $failSafeTarget;
        $this->failSafeMaxRecords = $failSafeMaxRecords;
        $this->breakerFailureThreshold = $breakerFailureThreshold;
        $this->breakerCooldownSeconds = $breakerCooldownSeconds;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $channels = self::parseChannels($config);
        $defaultChannel = self::requiredString($config, 'default_channel');

        if (!isset($channels[$defaultChannel])) {
            throw InvalidConfigurationException::forKey('default_channel', 'unknown channel "' . $defaultChannel . '"');
        }

        $failSafe = self::section($config, 'fail_safe');
        $breaker = self::section($config, 'circuit_breaker');

        return new self(
            self::requiredString($config, 'service'),
            self::requiredString($config, 'env'),
            self::optionalString($config, 'host'),
            self::boolean($config, 'strict_events', false),
            self::optionalString($config, 'pepper') ?? '',
            $defaultChannel,
            $channels,
            self::parseRouting($config, $channels),
            self::parseSampling($config),
            CaptureConfig::fromArray(self::section($config, 'capture')),
            LimitsConfig::fromArray(self::section($config, 'limits')),
            self::stringOrDefault($failSafe, 'target', 'php://stderr', 'fail_safe.target'),
            self::intOrDefault($failSafe, 'max_records', 3, 'fail_safe.max_records', 0),
            self::intOrDefault($breaker, 'failure_threshold', 5, 'circuit_breaker.failure_threshold', 1),
            self::intOrDefault($breaker, 'cooldown_seconds', 30, 'circuit_breaker.cooldown_seconds', 1)
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, ChannelConfig>
     */
    private static function parseChannels(array $config): array
    {
        $rawChannels = $config['channels'] ?? [];

        if (!is_array($rawChannels) || $rawChannels === []) {
            throw InvalidConfigurationException::forKey('channels', 'must declare at least one channel');
        }

        $channels = [];
        foreach ($rawChannels as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                throw InvalidConfigurationException::forKey('channels', 'must map channel names to definitions');
            }

            $channels[$name] = ChannelConfig::fromArray($name, $definition);
        }

        return $channels;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, ChannelConfig> $channels
     * @return array<string, string>
     */
    private static function parseRouting(array $config, array $channels): array
    {
        $routing = [];

        foreach (self::section($config, 'routing') as $logType => $channelName) {
            if (!is_string($logType) || !is_string($channelName)) {
                throw InvalidConfigurationException::forKey('routing', 'must map log types to channel names');
            }

            if (!isset($channels[$channelName])) {
                throw InvalidConfigurationException::forKey(
                    'routing.' . $logType,
                    'unknown channel "' . $channelName . '"'
                );
            }

            $routing[$logType] = $channelName;
        }

        return $routing;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, float>
     */
    private static function parseSampling(array $config): array
    {
        $sampling = [];

        foreach (self::section($config, 'sampling') as $logType => $rate) {
            if (!is_string($logType) || !is_numeric($rate) || $rate < 0 || $rate > 1) {
                throw InvalidConfigurationException::forKey('sampling', 'must map log types to a rate between 0 and 1');
            }

            $sampling[$logType] = (float) $rate;
        }

        return $sampling;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function env(): string
    {
        return $this->env;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function strictEvents(): bool
    {
        return $this->strictEvents;
    }

    public function pepper(): string
    {
        return $this->pepper;
    }

    public function defaultChannel(): string
    {
        return $this->defaultChannel;
    }

    /** @return array<string, ChannelConfig> */
    public function channels(): array
    {
        return $this->channels;
    }

    /** @return array<string, string> */
    public function routing(): array
    {
        return $this->routing;
    }

    /** @return array<string, float> */
    public function sampling(): array
    {
        return $this->sampling;
    }

    public function capture(): CaptureConfig
    {
        return $this->capture;
    }

    public function limits(): LimitsConfig
    {
        return $this->limits;
    }

    public function failSafeTarget(): string
    {
        return $this->failSafeTarget;
    }

    public function failSafeMaxRecords(): int
    {
        return $this->failSafeMaxRecords;
    }

    public function breakerFailureThreshold(): int
    {
        return $this->breakerFailureThreshold;
    }

    public function breakerCooldownSeconds(): int
    {
        return $this->breakerCooldownSeconds;
    }

    /** @param array<string, mixed> $config */
    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw InvalidConfigurationException::forKey($key, 'must be a non-empty string');
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be a string or null');
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function boolean(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        if (!is_bool($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be a boolean');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string|int, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (!is_array($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        return $value;
    }

    /** @param array<string|int, mixed> $section */
    private static function stringOrDefault(array $section, string $key, string $default, string $path): string
    {
        $value = $section[$key] ?? $default;

        if (!is_string($value) || $value === '') {
            throw InvalidConfigurationException::forKey($path, 'must be a non-empty string');
        }

        return $value;
    }

    /** @param array<string|int, mixed> $section */
    private static function intOrDefault(array $section, string $key, int $default, string $path, int $minimum): int
    {
        $value = $section[$key] ?? $default;

        if (!is_int($value) || $value < $minimum) {
            throw InvalidConfigurationException::forKey($path, 'must be an integer >= ' . $minimum);
        }

        return $value;
    }
}
