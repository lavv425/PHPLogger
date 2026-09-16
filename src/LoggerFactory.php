<?php

declare(strict_types=1);

namespace Logger;

use Logger\Channel\Channel;
use Logger\Channel\ChannelRegistry;
use Logger\Config\ChannelConfig;
use Logger\Config\Config;
use Logger\Interfaces\Context\ContextProviderInterface;
use Logger\Context\MutableContextProvider;
use Logger\Context\Pseudonymizer;
use Logger\Context\ServerContextFactory;
use Logger\Interfaces\Formatter\FormatterInterface;
use Logger\Formatter\JsonFormatter;
use Logger\Handler\CircuitBreakerHandler;
use Logger\Handler\ErrorLogHandler;
use Logger\Interfaces\Handler\HandlerInterface;
use Logger\Handler\InMemoryHandler;
use Logger\Handler\NullHandler;
use Logger\Handler\StreamHandler;
use Logger\Processor\SamplingProcessor;
use Logger\Sanitize\SanitizingGate;
use Logger\Sanitize\Scrubber;
use Logger\Sanitize\StatementNormalizer;
use Logger\Sanitize\Truncator;
use Logger\Interfaces\Support\ClockInterface;
use Logger\Support\FailSafe;
use Logger\Support\ReentrancyGuard;
use Logger\Support\SystemClock;

/**
 * Wires the pipeline from a validated configuration.
 *
 * Kept as an instance so callers can reach the context provider (to attach the
 * user id after authentication) and the built handlers (useful in tests).
 */
final class LoggerFactory
{
    private Config $config;
    private ClockInterface $clock;
    private ContextProviderInterface $contextProvider;
    private FormatterInterface $formatter;
    /** @var array<string, HandlerInterface[]> */
    private array $handlers = [];

    public function __construct(
        Config $config,
        ?ContextProviderInterface $contextProvider = null,
        ?ClockInterface $clock = null,
        ?FormatterInterface $formatter = null
    ) {
        $this->config = $config;
        $this->clock = $clock ?? new SystemClock();
        $this->formatter = $formatter ?? new JsonFormatter();
        $this->contextProvider = $contextProvider ?? self::defaultContextProvider($config);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(
        array $config,
        ?ContextProviderInterface $contextProvider = null,
        ?ClockInterface $clock = null
    ): Logger {
        return (new self(Config::fromArray($config), $contextProvider, $clock))->build();
    }

    public function build(): Logger
    {
        $factory = new RecordFactory(
            $this->clock,
            $this->contextProvider,
            $this->config->service(),
            $this->config->env(),
            $this->config->host() ?? (gethostname() ?: null)
        );

        $limits = $this->config->limits();

        $gate = new SanitizingGate(
            $this->config->capture(),
            new Scrubber(),
            new Truncator($limits, $this->formatter),
            new StatementNormalizer(),
            $limits
        );

        $failSafe = new FailSafe(
            $this->config->failSafeTarget(),
            $this->config->failSafeMaxRecords(),
            $this->config->service(),
            $this->config->env()
        );

        return new Logger(
            $factory,
            $gate,
            $this->buildRegistry(),
            $failSafe,
            new ReentrancyGuard(),
            $this->config->strictEvents()
        );
    }

    public function contextProvider(): ContextProviderInterface
    {
        return $this->contextProvider;
    }

    /** @return array<string, HandlerInterface[]> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    private function buildRegistry(): ChannelRegistry
    {
        $sampling = $this->config->sampling();
        $channels = [];

        foreach ($this->config->channels() as $name => $channelConfig) {
            $handlers = [];
            foreach ($channelConfig->handlers() as $spec) {
                $handlers[] = $this->buildHandler($spec);
            }

            $this->handlers[$name] = $handlers;

            $channels[$name] = new Channel(
                $name,
                $channelConfig->minLevel(),
                $sampling === [] ? [] : [new SamplingProcessor($sampling)],
                $handlers
            );
        }

        return new ChannelRegistry($channels, $this->config->routing(), $this->config->defaultChannel());
    }

    /** @param array<string, mixed> $spec */
    private function buildHandler(array $spec): HandlerInterface
    {
        switch ($spec['type']) {
            case ChannelConfig::HANDLER_STREAM:
                $handler = new StreamHandler((string) $spec['target'], $this->formatter, (bool) $spec['locking']);
                break;
            case ChannelConfig::HANDLER_ERROR_LOG:
                $handler = new ErrorLogHandler($this->formatter);
                break;
            case ChannelConfig::HANDLER_MEMORY:
                $handler = new InMemoryHandler($this->formatter);
                break;
            default:
                return new NullHandler();
        }

        return new CircuitBreakerHandler(
            $handler,
            $this->clock,
            $this->config->breakerFailureThreshold(),
            $this->config->breakerCooldownSeconds()
        );
    }

    private static function defaultContextProvider(Config $config): ContextProviderInterface
    {
        $pseudonymizer = new Pseudonymizer($config->pepper());
        $server = isset($_SERVER) && is_array($_SERVER) ? $_SERVER : [];

        return new MutableContextProvider(
            (new ServerContextFactory($pseudonymizer))->create($server),
            $pseudonymizer
        );
    }
}
