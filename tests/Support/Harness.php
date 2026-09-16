<?php

declare(strict_types=1);

namespace PhpLoggerTests\Support;

use DateTimeImmutable;
use PhpLogger\Channel\Channel;
use PhpLogger\Channel\ChannelRegistry;
use PhpLogger\Config\CaptureConfig;
use PhpLogger\Config\LimitsConfig;
use PhpLogger\Context\MutableContextProvider;
use PhpLogger\Context\Pseudonymizer;
use PhpLogger\Context\RequestContext;
use PhpLogger\Formatter\JsonFormatter;
use PhpLogger\Handler\HandlerInterface;
use PhpLogger\Handler\InMemoryHandler;
use PhpLogger\Logger;
use PhpLogger\RecordFactory;
use PhpLogger\Sanitize\SanitizingGate;
use PhpLogger\Sanitize\Scrubber;
use PhpLogger\Sanitize\StatementNormalizer;
use PhpLogger\Sanitize\Truncator;
use PhpLogger\Support\FailSafe;
use PhpLogger\Support\FixedClock;
use PhpLogger\Support\ReentrancyGuard;

/**
 * Builds a fully wired pipeline with fixed time and context, so records are
 * byte-for-byte reproducible.
 */
final class Harness
{
    public const TIMESTAMP = '2026-01-15T10:00:00.123456+00:00';
    public const SERVICE = 'billing-api';
    public const ENV = 'test';
    public const HOST = 'test-host';

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, string[]>> $captureFields
     */
    public static function gate(
        array $captureFields = [],
        bool $rawStatement = false,
        ?LimitsConfig $limits = null
    ): SanitizingGate {
        $limits = $limits ?? self::limits();

        return new SanitizingGate(
            new CaptureConfig($captureFields, $rawStatement),
            new Scrubber(),
            new Truncator($limits, new JsonFormatter()),
            new StatementNormalizer(),
            $limits
        );
    }

    public static function limits(int $maxRecordBytes = 65536, int $maxFieldBytes = 1024): LimitsConfig
    {
        return new LimitsConfig($maxRecordBytes, $maxFieldBytes, 20, 4);
    }

    public static function clock(): FixedClock
    {
        return new FixedClock(new DateTimeImmutable(self::TIMESTAMP));
    }

    public static function contextProvider(): MutableContextProvider
    {
        return new MutableContextProvider(
            new RequestContext('req_test0123456789', 'a1b2c3d4e5f60718', null, 'TestAgent/1.0'),
            new Pseudonymizer('test-pepper')
        );
    }

    public static function recordFactory(): RecordFactory
    {
        return new RecordFactory(self::clock(), self::contextProvider(), self::SERVICE, self::ENV, self::HOST);
    }

    /**
     * @param HandlerInterface[] $handlers
     */
    public static function logger(
        array $handlers,
        ?SanitizingGate $gate = null,
        bool $strictEvents = false,
        ?FailSafe $failSafe = null
    ): Logger {
        $channel = new Channel('test', 'debug', [], $handlers);
        $registry = new ChannelRegistry(['test' => $channel], [], 'test');

        return new Logger(
            self::recordFactory(),
            $gate ?? self::gate(),
            $registry,
            $failSafe ?? new FailSafe('php://memory', 3, self::SERVICE, self::ENV),
            new ReentrancyGuard(),
            $strictEvents
        );
    }

    /** @return array{0: Logger, 1: InMemoryHandler} */
    public static function loggerWithMemory(?SanitizingGate $gate = null, bool $strictEvents = false): array
    {
        $handler = new InMemoryHandler();

        return [self::logger([$handler], $gate, $strictEvents), $handler];
    }

    /** @return array<string, mixed> */
    public static function baseConfigArray(): array
    {
        return [
            'service' => self::SERVICE,
            'env' => self::ENV,
            'default_channel' => 'memory',
            'channels' => [
                'memory' => ['handlers' => [['type' => 'memory']]],
            ],
        ];
    }
}
