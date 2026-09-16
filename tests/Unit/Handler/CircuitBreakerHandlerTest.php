<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Handler;

use DateTimeImmutable;
use Logger\Exception\HandlerFailure;
use Logger\Handler\CircuitBreakerHandler;
use Logger\Handler\InMemoryHandler;
use Logger\Support\FixedClock;
use Logger\Tests\Support\FailingHandler;
use Logger\Tests\Support\FlakyHandler;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerHandlerTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2024-03-01T10:00:00+00:00'), 1000.0);
    }

    public function test_passes_records_through_while_the_destination_is_healthy(): void
    {
        $inner = new InMemoryHandler();
        $breaker = new CircuitBreakerHandler($inner, $this->clock, 3, 30);

        $breaker->handle(RecordBuilder::make(['n' => 1]));
        $breaker->handle(RecordBuilder::make(['n' => 2]));

        self::assertCount(2, $inner->records());
        self::assertFalse($breaker->isOpen());
    }

    public function test_reports_every_failure_until_the_threshold_is_reached(): void
    {
        $breaker = new CircuitBreakerHandler(new FailingHandler(), $this->clock, 3, 30);

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            try {
                $breaker->handle(RecordBuilder::make());
                self::fail('failure ' . $attempt . ' should have been reported');
            } catch (HandlerFailure $failure) {
                self::assertSame('destination unavailable', $failure->getMessage());
            }
        }

        self::assertTrue($breaker->isOpen());
    }

    public function test_drops_records_silently_once_open(): void
    {
        $inner = new FailingHandler();
        $breaker = new CircuitBreakerHandler($inner, $this->clock, 2, 30);

        $this->trip($breaker, 2);
        self::assertSame(2, $inner->attempts());

        // A blocked destination must not turn every log call into a stalled
        // request, so the record is dropped without touching it.
        $breaker->handle(RecordBuilder::make());

        self::assertSame(2, $inner->attempts(), 'the destination is not contacted while the breaker is open');
    }

    public function test_stays_open_for_the_whole_cooldown(): void
    {
        $breaker = new CircuitBreakerHandler(new FailingHandler(), $this->clock, 2, 30);
        $this->trip($breaker, 2);

        $this->clock->advance(29.0);

        self::assertTrue($breaker->isOpen());
    }

    public function test_probes_the_destination_again_after_the_cooldown(): void
    {
        $inner = new FailingHandler();
        $breaker = new CircuitBreakerHandler($inner, $this->clock, 2, 30);
        $this->trip($breaker, 2);

        $this->clock->advance(30.0);

        self::assertFalse($breaker->isOpen(), 'half-open: the next record gets to try');

        try {
            $breaker->handle(RecordBuilder::make());
        } catch (HandlerFailure $failure) {
            // Expected: the destination is still down.
        }

        self::assertSame(3, $inner->attempts());
    }

    public function test_a_success_clears_the_failure_count(): void
    {
        $flaky = new FlakyHandler();
        $breaker = new CircuitBreakerHandler($flaky, $this->clock, 3, 30);

        $flaky->fail();
        $this->attemptAndIgnore($breaker);
        $flaky->fail();
        $this->attemptAndIgnore($breaker);

        $flaky->recover();
        $breaker->handle(RecordBuilder::make());

        // Two isolated failures followed by a success must not add up to a trip
        // on the next hiccup.
        $flaky->fail();
        $this->attemptAndIgnore($breaker);

        self::assertFalse($breaker->isOpen());
    }

    public function test_close_is_delegated_to_the_wrapped_handler(): void
    {
        $inner = new FailingHandler();
        $breaker = new CircuitBreakerHandler($inner, $this->clock, 3, 30);

        $breaker->close();

        self::assertTrue($inner->isClosed());
    }

    private function trip(CircuitBreakerHandler $breaker, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->attemptAndIgnore($breaker);
        }
    }

    private function attemptAndIgnore(CircuitBreakerHandler $breaker): void
    {
        try {
            $breaker->handle(RecordBuilder::make());
        } catch (HandlerFailure $failure) {
            // The breaker reports failures; this test is about its state.
        }
    }
}
