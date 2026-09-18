<?php

declare(strict_types=1);

namespace Logger\Tests\Support;

use DateTimeImmutable;
use Logger\Channel\Channel;
use Logger\Channel\ChannelRegistry;
use Logger\Config\CaptureConfig;
use Logger\Config\LimitsConfig;
use Logger\Context\MutableContextProvider;
use Logger\Context\Pseudonymizer;
use Logger\Context\RequestContext;
use Logger\Enum\Level;
use Logger\Formatter\JsonFormatter;
use Logger\Handler\InMemoryHandler;
use Logger\Interfaces\Handler\HandlerInterface;
use Logger\Logger;
use Logger\Processor\SamplingProcessor;
use Logger\RecordFactory;
use Logger\Sanitize\SanitizingGate;
use Logger\Sanitize\Scrubber;
use Logger\Sanitize\StatementNormalizer;
use Logger\Sanitize\Truncator;
use Logger\Support\FailSafe;
use Logger\Support\FixedClock;
use Logger\Support\ReentrancyGuard;

/**
 * Wires a real pipeline with in-memory destinations and a frozen clock.
 *
 * Deliberately assembled by hand rather than through LoggerFactory: the factory
 * wraps every handler in a circuit breaker, and these tests need to read the
 * records the destination actually received.
 */
final class LoggerHarness
{
    public const TIMESTAMP = '2024-03-01T10:20:30+00:00';

    private Logger $logger;
    /** @var array<string, InMemoryHandler> */
    private array $handlers;
    private MutableContextProvider $contextProvider;
    private FailSafe $failSafe;
    private string $failSafeTarget;
    private FixedClock $clock;

    /**
     * @param array<string, InMemoryHandler> $handlers
     * @param array<string, string> $routing
     */
    private function __construct(array $handlers, MutableContextProvider $contextProvider, FailSafe $failSafe, string $failSafeTarget, FixedClock $clock, Logger $logger)
    {
        $this->handlers = $handlers;
        $this->contextProvider = $contextProvider;
        $this->failSafe = $failSafe;
        $this->failSafeTarget = $failSafeTarget;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $options strict_events, routing, capture_fields,
     *                                      sampling, pepper, limits, extra_handlers
     */
    public static function create(array $options = []): self
    {
        $clock = new FixedClock(new DateTimeImmutable(self::TIMESTAMP), 1000.0);

        $pseudonymizer = new Pseudonymizer($options['pepper'] ?? '');
        $contextProvider = new MutableContextProvider(new RequestContext('req_fixed'), $pseudonymizer);

        $factory = new RecordFactory($clock, $contextProvider, 'test-service', 'testing', 'test-host');

        $limits = $options['limits'] ?? new LimitsConfig(4096, 1024, 20, 4);
        $gate = new SanitizingGate(new CaptureConfig($options['capture_fields'] ?? [], (bool) ($options['raw_statement'] ?? false)), new Scrubber(), new Truncator($limits, new JsonFormatter()), new StatementNormalizer(), $limits);

        $stdout = new InMemoryHandler();
        $errors = new InMemoryHandler();
        $handlers = ['stdout' => $stdout, 'errors' => $errors];

        $sampling = $options['sampling'] ?? [];
        $processors = $sampling === [] ? [] : [new SamplingProcessor($sampling)];

        /** @var HandlerInterface[] $extra */
        $extra = $options['extra_handlers'] ?? [];

        $registry = new ChannelRegistry([
            'stdout' => new Channel('stdout', Level::DEBUG, $processors, array_merge([$stdout], $extra)),
            'errors' => new Channel('errors', Level::WARNING, $processors, [$errors]),
            'off' => new Channel('off', Level::DEBUG, [], []),
        ], $options['routing'] ?? ['php_log' => 'errors'], 'stdout');

        $target = tempnam(sys_get_temp_dir(), 'harness');
        if (!is_string($target)) {
            throw new \RuntimeException('Cannot create a fail-safe target.');
        }

        $failSafe = new FailSafe($target, (int) ($options['fail_safe_max_records'] ?? 3), 'test-service', 'testing');

        $logger = new Logger($factory, $gate, $registry, $failSafe, new ReentrancyGuard(), (bool) ($options['strict_events'] ?? false));

        return new self($handlers, $contextProvider, $failSafe, $target, $clock, $logger);
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function clock(): FixedClock
    {
        return $this->clock;
    }

    public function contextProvider(): MutableContextProvider
    {
        return $this->contextProvider;
    }

    public function failSafe(): FailSafe
    {
        return $this->failSafe;
    }

    public function handler(string $channel = 'stdout'): InMemoryHandler
    {
        if (!isset($this->handlers[$channel])) {
            throw new \RuntimeException('No in-memory handler for channel "' . $channel . '".');
        }

        return $this->handlers[$channel];
    }

    /** @return array<string, mixed>|null */
    public function lastRecord(string $channel = 'stdout'): ?array
    {
        return $this->handler($channel)->lastAsArray();
    }

    /** @return string[] */
    public function lines(string $channel = 'stdout'): array
    {
        return $this->handler($channel)->lines();
    }

    /** @return string[] */
    public function failSafeLines(): array
    {
        if (!is_file($this->failSafeTarget)) {
            return [];
        }

        $contents = (string) file_get_contents($this->failSafeTarget);

        return $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));
    }

    public function cleanUp(): void
    {
        if (is_file($this->failSafeTarget)) {
            unlink($this->failSafeTarget);
        }
    }
}
