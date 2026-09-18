<?php

declare(strict_types=1);

namespace Logger\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Logger\Context\MutableContextProvider;
use Logger\Context\Pseudonymizer;
use Logger\Context\RequestContext;
use Logger\Contract\LogError;
use Logger\Enum\ErrorType;
use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Exception\InvalidLogEventException;
use Logger\RecordFactory;
use Logger\Support\FixedClock;
use Logger\Tests\Support\PayloadStub;
use PHPUnit\Framework\TestCase;

final class RecordFactoryTest extends TestCase
{
    public function test_builds_the_envelope_from_the_payload_and_the_ambient_context(): void
    {
        $record = $this->factory()->create(new PayloadStub('db_query', 'user_lookup', Outcome::SUCCESS, Level::INFO, 0.25, null, ['database' => 'billing']));

        self::assertSame('db_query', $record->logType());
        self::assertSame('user_lookup', $record->event());
        self::assertSame(Outcome::SUCCESS, $record->outcome());
        self::assertSame(Level::INFO, $record->level());
        self::assertSame(0.25, $record->duration());
        self::assertSame(['database' => 'billing'], $record->data());
        self::assertSame('test-service', $record->service());
        self::assertSame('testing', $record->env());
        self::assertSame('test-host', $record->host());
        self::assertSame('req_fixed', $record->correlation()['request_id']);
    }

    public function test_the_timestamp_is_always_utc(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-03-01T11:20:30', new DateTimeZone('Europe/Rome')));

        $record = $this->factory($clock)->create(new PayloadStub());

        self::assertSame('UTC', $record->timestamp()->getTimezone()->getName());
        self::assertSame('2024-03-01T10:20:30+00:00', $record->timestamp()->format('c'));
    }

    public function test_an_explicit_level_wins_over_the_payload_default(): void
    {
        $payload = new PayloadStub('db_query', 'user_lookup', Outcome::SUCCESS, Level::INFO);

        self::assertSame(Level::CRITICAL, $this->factory()->create($payload, Level::CRITICAL)->level());
        self::assertSame(Level::INFO, $this->factory()->create($payload)->level());
    }

    public function test_the_error_is_carried_over_untouched(): void
    {
        $error = new LogError(ErrorType::DB_ERROR, 'deadlock', '40001');

        self::assertSame($error, $this->factory()->create(new PayloadStub('db_query', 'e', Outcome::FAILURE, Level::ERROR, null, $error))->error());
    }

    public function test_a_null_host_is_allowed(): void
    {
        $factory = new RecordFactory(new FixedClock(new DateTimeImmutable('@0')), $this->contextProvider(), 'svc', 'testing', null);

        self::assertNull($factory->create(new PayloadStub())->host());
    }

    /** @dataProvider invalidPayloads */
    public function test_validates_every_caller_controlled_value(PayloadStub $payload, string $expectedMessage): void
    {
        // The rest of the pipeline assumes a well-formed record, so the checks
        // happen here rather than being trusted to the payload.
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->factory()->create($payload);
    }

    /** @return array<string, array{PayloadStub, string}> */
    public function invalidPayloads(): array
    {
        return [
            'log type with free-form data' => [new PayloadStub('db query for marco@example.com'), '"log_type"'],
            'empty log type' => [new PayloadStub(''), '"log_type"'],
            'event with free-form data' => [new PayloadStub('db_query', 'lookup marco@example.com'), '"event"'],
            'unknown outcome' => [new PayloadStub('db_query', 'e', 'ok'), '"outcome"'],
            'unknown level' => [new PayloadStub('db_query', 'e', Outcome::SUCCESS, 'verbose'), '"level"'],
            'negative duration' => [new PayloadStub('db_query', 'e', Outcome::SUCCESS, Level::INFO, -1.0), '"duration"'],
        ];
    }

    public function test_an_invalid_level_override_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('unknown level "verbose"');

        $this->factory()->create(new PayloadStub(), 'verbose');
    }

    public function test_a_null_duration_is_accepted(): void
    {
        self::assertNull($this->factory()->create(new PayloadStub())->duration());
    }

    public function test_reads_the_context_at_build_time_not_at_wiring_time(): void
    {
        // The user id is only known after authentication, well after the
        // logger was built.
        $contextProvider = $this->contextProvider();
        $factory = $this->factory(null, $contextProvider);

        $contextProvider->setUserId('user-42');

        self::assertNotNull($factory->create(new PayloadStub())->correlation()['user_ref']);
    }

    private function factory(?FixedClock $clock = null, ?MutableContextProvider $contextProvider = null): RecordFactory
    {
        return new RecordFactory($clock ?? new FixedClock(new DateTimeImmutable('2024-03-01T10:20:30+00:00')), $contextProvider ?? $this->contextProvider(), 'test-service', 'testing', 'test-host');
    }

    private function contextProvider(): MutableContextProvider
    {
        return new MutableContextProvider(new RequestContext('req_fixed'), new Pseudonymizer('s3cr3t'));
    }
}
