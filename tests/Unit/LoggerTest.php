<?php

declare(strict_types=1);

namespace Logger\Tests\Unit;

use Logger\Contract\LogRecord;
use Logger\Enum\Level;
use Logger\Exception\InvalidConfigurationException;
use Logger\Exception\InvalidLogEventException;
use Logger\Interfaces\Handler\HandlerInterface;
use Logger\Interfaces\Logger\LoggerInterface;
use Logger\Payload\DbQueryPayload;
use Logger\Payload\PhpLogPayload;
use Logger\Tests\Support\FailingHandler;
use Logger\Tests\Support\LoggerHarness;
use Logger\Tests\Support\PayloadStub;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    /** @var LoggerHarness[] */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }

        $this->harnesses = [];
    }

    public function test_implements_the_logger_contract(): void
    {
        self::assertInstanceOf(LoggerInterface::class, $this->harness()->logger());
    }

    public function test_writes_a_record_to_the_routed_channel(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertCount(1, $harness->lines('stdout'));
        self::assertSame([], $harness->lines('errors'));
        self::assertSame('user_lookup', $harness->lastRecord('stdout')['event']);
    }

    public function test_routing_sends_a_log_type_to_its_own_channel(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(PhpLogPayload::create('custom_log', 'boom')->withLevel(Level::ERROR));

        self::assertSame([], $harness->lines('stdout'));
        self::assertCount(1, $harness->lines('errors'));
    }

    public function test_a_level_override_is_applied(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess(), Level::CRITICAL);

        self::assertSame('critical', $harness->lastRecord('stdout')['level']);
    }

    public function test_a_pinned_channel_bypasses_the_routing_table(): void
    {
        $harness = $this->harness();

        $harness->logger()->channel('errors')->log(PhpLogPayload::create('custom_log', 'msg')->withLevel(Level::ERROR));

        self::assertCount(1, $harness->lines('errors'));
    }

    public function test_pinning_a_channel_returns_a_new_logger(): void
    {
        $harness = $this->harness();
        $logger = $harness->logger();

        $pinned = $logger->channel('errors');

        self::assertNotSame($logger, $pinned);

        // The original must keep routing normally.
        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        self::assertCount(1, $harness->lines('stdout'));
    }

    public function test_pinning_an_unknown_channel_fails_fast(): void
    {
        // That is configuration, not runtime, so it must not be swallowed.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('unknown channel "audit"');

        $this->harness()->logger()->channel('audit');
    }

    public function test_an_invalid_event_is_propagated_under_strict_events(): void
    {
        $harness = $this->harness(['strict_events' => true]);

        $this->expectException(InvalidLogEventException::class);

        $harness->logger()->log(new PayloadStub('db query with spaces'));
    }

    public function test_an_invalid_event_degrades_into_a_meta_record_otherwise(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(new PayloadStub('db query with spaces'));

        self::assertSame([], $harness->lines('stdout'), 'the broken record is not written');

        $failSafe = json_decode($harness->failSafeLines()[0], true);
        self::assertSame('invalid_event', $failSafe['reason']);
        self::assertSame('logger_error', $failSafe['log_type']);
    }

    public function test_a_failing_destination_never_reaches_the_application(): void
    {
        $harness = $this->harness(['extra_handlers' => [new FailingHandler()]]);

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        // One surviving destination, so nothing is even reported.
        self::assertCount(1, $harness->lines('stdout'));
        self::assertSame([], $harness->failSafeLines());
    }

    public function test_a_total_blackout_degrades_into_a_meta_record(): void
    {
        $harness = $this->harness(['routing' => ['db_query' => 'dead']]);

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        // Routing to a channel that does not exist is caught by the guard like
        // any other pipeline failure: logging must not break the application.
        $failSafe = json_decode($harness->failSafeLines()[0], true);
        self::assertSame('pipeline_failure', $failSafe['reason']);
        self::assertSame('db_query', $failSafe['dropped_log_type']);
    }

    public function test_a_payload_that_cannot_even_name_itself_is_reported_as_unknown(): void
    {
        $harness = $this->harness();

        $harness->logger()->log((new PayloadStub())->throwOnLogType());

        $failSafe = json_decode($harness->failSafeLines()[0], true);
        self::assertSame('unknown', $failSafe['dropped_log_type']);
    }

    public function test_an_error_raised_inside_the_logger_cannot_re_enter_it(): void
    {
        $reentrant = new class () implements HandlerInterface {
            public ?LoggerInterface $logger = null;
            public int $depth = 0;

            public function handle(LogRecord $record): void
            {
                ++$this->depth;

                if ($this->logger !== null && $this->depth < 5) {
                    // Simulates an application error handler that logs while
                    // the logger is still busy.
                    $this->logger->log(PhpLogPayload::create('error_handler', 'nested'));
                }
            }

            public function close(): void
            {
            }
        };

        $harness = $this->harness(['extra_handlers' => [$reentrant]]);
        $reentrant->logger = $harness->logger();

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertSame(1, $reentrant->depth, 'the nested call must be refused, not recursed into');
    }

    public function test_the_guard_is_released_after_a_failure(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(new PayloadStub('db query with spaces'));
        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());

        self::assertCount(1, $harness->lines('stdout'), 'a failed call must not leave the logger stuck');
    }

    public function test_close_closes_every_channel(): void
    {
        $failing = new FailingHandler();
        $harness = $this->harness(['extra_handlers' => [$failing]]);

        $harness->logger()->close();

        self::assertTrue($failing->isClosed());
    }

    /** @param array<string, mixed> $options */
    private function harness(array $options = []): LoggerHarness
    {
        $harness = LoggerHarness::create($options);
        $this->harnesses[] = $harness;

        return $harness;
    }
}
