<?php

declare(strict_types=1);

namespace PhpLogger;

use PhpLogger\Channel\Channel;
use PhpLogger\Channel\ChannelRegistry;
use PhpLogger\Config\ChannelConfig;
use PhpLogger\Config\Config;
use PhpLogger\Context\ContextProvider;
use PhpLogger\Context\MutableContextProvider;
use PhpLogger\Context\Pseudonymizer;
use PhpLogger\Context\ServerContextFactory;
use PhpLogger\Formatter\FormatterInterface;
use PhpLogger\Formatter\JsonFormatter;
use PhpLogger\Handler\CircuitBreakerHandler;
use PhpLogger\Handler\ErrorLogHandler;
use PhpLogger\Handler\HandlerInterface;
use PhpLogger\Handler\InMemoryHandler;
use PhpLogger\Handler\NullHandler;
use PhpLogger\Handler\StreamHandler;
use PhpLogger\Processor\SamplingProcessor;
use PhpLogger\Sanitize\SanitizingGate;
use PhpLogger\Sanitize\Scrubber;
use PhpLogger\Sanitize\StatementNormalizer;
use PhpLogger\Sanitize\Truncator;
use PhpLogger\Support\Clock;
use PhpLogger\Support\FailSafe;
use PhpLogger\Support\ReentrancyGuard;
use PhpLogger\Support\SystemClock;

/**
 * Wires the pipeline from a validated configuration.
 *
 * Kept as an instance so callers can reach the context provider (to attach the
 * user id after authentication) and the built handlers (useful in tests).
 */
final class LoggerFactory
{
    private Config $config;
    private Clock $clock;
    private ContextProvider $contextProvider;
    private FormatterInterface $formatter;
    /** @var array<string, HandlerInterface[]> */
    private array $handlers = [];

    public function __construct(
        Config $config,
        ?ContextProvider $contextProvider = null,
        ?Clock $clock = null,
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
        ?ContextProvider $contextProvider = null,
        ?Clock $clock = null
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

    public function contextProvider(): ContextProvider
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

    private static function defaultContextProvider(Config $config): ContextProvider
    {
        $pseudonymizer = new Pseudonymizer($config->pepper());
        $server = isset($_SERVER) && is_array($_SERVER) ? $_SERVER : [];

        return new MutableContextProvider(
            (new ServerContextFactory($pseudonymizer))->create($server),
            $pseudonymizer
        );
    }
}
