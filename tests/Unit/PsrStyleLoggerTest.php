<?php

declare(strict_types=1);

namespace Logger\Tests\Unit;

use Logger\Enum\ErrorType;
use Logger\Enum\Level;
use Logger\Enum\LogType;
use Logger\Enum\Outcome;
use Logger\PsrStyleLogger;
use Logger\Tests\Support\LoggerHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PsrStyleLoggerTest extends TestCase
{
    private LoggerHarness $harness;
    private PsrStyleLogger $logger;

    protected function setUp(): void
    {
        // php_log is routed to "errors", whose floor is warning; pinning keeps
        // every level in one readable place.
        $this->harness = LoggerHarness::create(['routing' => []]);
        $this->logger = new PsrStyleLogger($this->harness->logger());
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    /** @dataProvider psrLevels */
    public function test_every_psr3_method_maps_to_its_level(string $method, string $expectedLevel): void
    {
        $this->logger->{$method}('something happened');

        $record = $this->harness->lastRecord();
        self::assertSame($expectedLevel, $record['level']);
        self::assertSame(LogType::PHP_LOG, $record['log_type']);
        self::assertSame('something happened', $record['data']['message']);
    }

    /** @return array<string, array{string, string}> */
    public function psrLevels(): array
    {
        return [
            'emergency' => ['emergency', Level::EMERGENCY],
            'alert' => ['alert', Level::ALERT],
            'critical' => ['critical', Level::CRITICAL],
            'error' => ['error', Level::ERROR],
            'warning' => ['warning', Level::WARNING],
            'notice' => ['notice', Level::NOTICE],
            'info' => ['info', Level::INFO],
            'debug' => ['debug', Level::DEBUG],
        ];
    }

    public function test_the_generic_log_method_accepts_a_level(): void
    {
        $this->logger->log(Level::WARNING, 'careful');

        self::assertSame(Level::WARNING, $this->harness->lastRecord()['level']);
    }

    public function test_an_unknown_level_falls_back_to_info(): void
    {
        // A third party library may pass anything; the record still gets out.
        $this->logger->log('verbose', 'chatty');

        self::assertSame(Level::INFO, $this->harness->lastRecord()['level']);
    }

    public function test_the_default_event_is_used_when_none_is_given(): void
    {
        $this->logger->info('hello');

        self::assertSame('custom_log', $this->harness->lastRecord()['event']);
    }

    public function test_the_event_can_be_named_through_the_context(): void
    {
        $this->logger->info('hello', ['event' => 'checkout.started']);

        self::assertSame('checkout.started', $this->harness->lastRecord()['event']);
    }

    public function test_an_event_name_carrying_free_form_data_is_ignored(): void
    {
        $this->logger->info('hello', ['event' => 'login for marco@example.com']);

        self::assertSame('custom_log', $this->harness->lastRecord()['event']);
    }

    public function test_the_event_and_exception_keys_are_removed_from_the_context(): void
    {
        $harness = LoggerHarness::create(['routing' => [], 'capture_fields' => ['php_log' => ['context' => ['event', 'exception', 'route']]]]);
        $logger = new PsrStyleLogger($harness->logger());

        $logger->info('hello', ['event' => 'checkout.started', 'exception' => new RuntimeException('boom'), 'route' => '/checkout']);

        self::assertSame(['route' => '/checkout'], $harness->lastRecord()['data']['context']);

        $harness->cleanUp();
    }

    public function test_an_empty_context_is_reported_as_absent(): void
    {
        $this->logger->info('hello');

        self::assertNull($this->harness->lastRecord()['data']['context']);
    }

    public function test_an_exception_in_the_context_becomes_a_failure(): void
    {
        $exception = new RuntimeException('connection refused');
        $line = __LINE__ - 1;

        $this->logger->error('call failed', ['exception' => $exception]);

        $record = $this->harness->lastRecord();
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertFalse($record['success']);
        self::assertSame(ErrorType::EXCEPTION, $record['error']['type']);
        self::assertSame('connection refused', $record['error']['message']);
        self::assertSame($line, $record['data']['line']);
        self::assertSame(__FILE__, $record['data']['file']);
    }

    public function test_a_non_throwable_exception_key_is_ignored(): void
    {
        $this->logger->error('call failed', ['exception' => 'not an exception']);

        $record = $this->harness->lastRecord();
        self::assertNull($record['error']);
        self::assertSame(Outcome::UNKNOWN, $record['outcome']);
    }

    public function test_the_context_is_still_allow_listed(): void
    {
        // The adapter is a convenience, not a way around the sanitizing gate.
        $this->logger->info('hello', ['route' => '/checkout', 'password' => 'hunter2']);

        $record = $this->harness->lastRecord();
        self::assertSame(['_omitted' => 2], $record['data']['context']);
        self::assertStringNotContainsString('hunter2', json_encode($record));
    }
}
