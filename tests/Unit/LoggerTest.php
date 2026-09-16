<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Contract\LogError;
use PhpLogger\Contract\LogPayload;
use PhpLogger\Contract\LogRecord;
use PhpLogger\Enum\Level;
use PhpLogger\Enum\Outcome;
use PhpLogger\Exception\InvalidLogEventException;
use PhpLogger\Handler\HandlerInterface;
use PhpLogger\Handler\InMemoryHandler;
use PhpLogger\Logger;
use PhpLogger\LoggerInterface;
use PhpLogger\Payload\DbQueryPayload;
use PhpLogger\Payload\PhpLogPayload;
use PhpLogger\PsrStyleLogger;
use PhpLogger\Support\FailSafe;
use PhpLoggerTests\Support\FailingHandler;
use PhpLoggerTests\Support\Harness;
use PhpLoggerTests\TestCase;
use RuntimeException;

/** Payload that passes construction but is rejected by the record factory. */
final class UnnamedPayload implements LogPayload
{
    public function logType(): string
    {
        return 'not a valid type';
    }

    public function event(): string
    {
        return 'whatever';
    }

    public function outcome(): string
    {
        return Outcome::UNKNOWN;
    }

    public function defaultLevel(): string
    {
        return Level::INFO;
    }

    public function duration(): ?float
    {
        return null;
    }

    public function error(): ?LogError
    {
        return null;
    }

    public function data(): array
    {
        return [];
    }
}

/** Handler that logs while handling, to exercise the reentrancy guard. */
final class ReentrantHandler implements HandlerInterface
{
    private ?LoggerInterface $logger = null;
    private int $calls = 0;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function handle(LogRecord $record): void
    {
        ++$this->calls;

        if ($this->logger !== null) {
            $this->logger->log(PhpLogPayload::create('custom_log', 'recursive call'));
        }
    }

    public function close(): void
    {
    }

    public function calls(): int
    {
        return $this->calls;
    }
}

final class LoggerTest extends TestCase
{
    public function testRecordCarriesEnvelopeAndPayload(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();

        $logger->log(
            DbQueryPayload::create('user_lookup', 'production_db')
                ->withStatement('SELECT * FROM users WHERE id = ?', ['id' => 1])
                ->withDuration(0.045)
                ->withSuccess()
        );

        $record = $handler->lastAsArray();

        $this->assertSame(1, $record['schema_version']);
        $this->assertSame(Harness::SERVICE, $record['service']);
        $this->assertSame(Harness::HOST, $record['host']);
        $this->assertSame('db_query', $record['log_type']);
        $this->assertSame('user_lookup', $record['event']);
        $this->assertSame('success', $record['outcome']);
        $this->assertTrue($record['success']);
        $this->assertSame(0.045, $record['duration']);
        $this->assertSame('req_test0123456789', $record['request_id']);
        $this->assertNull($record['user_ref']);
        $this->assertSame('production_db', $record['data']['database']);
    }

    public function testUnknownOutcomeReportsNullSuccess(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();

        $logger->log(PhpLogPayload::fromPhpError('error_handler', 8192, 'deprecated', '/app/a.php', 3));

        $record = $handler->lastAsArray();

        $this->assertSame('unknown', $record['outcome']);
        $this->assertNull($record['success']);
        $this->assertSame('notice', $record['level']);
    }

    public function testExplicitLevelOverridesThePayloadDefault(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();

        $logger->log(DbQueryPayload::create('user_lookup', 'db'), Level::DEBUG);

        $this->assertSame('debug', $handler->lastAsArray()['level']);
    }

    public function testADownDestinationDoesNotBreakTheApplication(): void
    {
        $failSafe = new FailSafe('php://memory', 3, Harness::SERVICE, Harness::ENV);
        $failing = new FailingHandler();
        $logger = Harness::logger([$failing], null, false, $failSafe);

        $this->assertDoesNotThrow(static function () use ($logger): void {
            $logger->log(DbQueryPayload::create('user_lookup', 'db'));
        });

        $this->assertSame(1, $failing->attempts());
        $this->assertSame(1, $failSafe->emitted());
    }

    public function testOneSurvivingDestinationIsEnough(): void
    {
        $failSafe = new FailSafe('php://memory', 3, Harness::SERVICE, Harness::ENV);
        $memory = new InMemoryHandler();
        $logger = Harness::logger([new FailingHandler(), $memory], null, false, $failSafe);

        $logger->log(DbQueryPayload::create('user_lookup', 'db'));

        $this->assertCount(1, $memory->records());
        $this->assertSame(0, $failSafe->emitted());
    }

    public function testFailSafeIsRateLimited(): void
    {
        $failSafe = new FailSafe('php://memory', 2, Harness::SERVICE, Harness::ENV);
        $logger = Harness::logger([new FailingHandler()], null, false, $failSafe);

        for ($i = 0; $i < 10; ++$i) {
            $logger->log(DbQueryPayload::create('user_lookup', 'db'));
        }

        $this->assertSame(2, $failSafe->emitted());
    }

    public function testInvalidEventIsDegradedUnlessStrict(): void
    {
        $failSafe = new FailSafe('php://memory', 3, Harness::SERVICE, Harness::ENV);
        $memory = new InMemoryHandler();
        $logger = Harness::logger([$memory], null, false, $failSafe);

        $this->assertDoesNotThrow(static function () use ($logger): void {
            $logger->log(new UnnamedPayload());
        });

        $this->assertCount(0, $memory->records());
        $this->assertSame(1, $failSafe->emitted());
    }

    public function testInvalidEventSurfacesInStrictMode(): void
    {
        $logger = Harness::logger([new InMemoryHandler()], null, true);

        $this->assertThrows(InvalidLogEventException::class, static function () use ($logger): void {
            $logger->log(new UnnamedPayload());
        });
    }

    public function testTheOriginalThrowableSurvivesAFailedLogCall(): void
    {
        $logger = Harness::logger([new FailingHandler()]);

        $caught = $this->assertThrows(RuntimeException::class, static function () use ($logger): void {
            try {
                throw new RuntimeException('original failure');
            } catch (RuntimeException $exception) {
                $logger->log(PhpLogPayload::fromThrowable('uncaught_exception', $exception));

                throw $exception;
            }
        });

        $this->assertSame('original failure', $caught->getMessage());
    }

    public function testLoggingFromInsideAHandlerDoesNotRecurse(): void
    {
        $handler = new ReentrantHandler();
        $logger = Harness::logger([$handler]);
        $handler->setLogger($logger);

        $logger->log(DbQueryPayload::create('user_lookup', 'db'));

        $this->assertSame(1, $handler->calls());
    }

    public function testChannelPinningRejectsUnknownChannels(): void
    {
        $logger = Harness::logger([new InMemoryHandler()]);

        $this->assertThrows(\PhpLogger\Exception\InvalidConfigurationException::class, static function () use ($logger): void {
            $logger->channel('does-not-exist');
        });
    }

    public function testPsrAdapterProducesPhpLogRecords(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();
        $psr = new PsrStyleLogger($logger);

        $psr->warning('cache miss', ['event' => 'cache_miss']);

        $record = $handler->lastAsArray();

        $this->assertSame('php_log', $record['log_type']);
        $this->assertSame('cache_miss', $record['event']);
        $this->assertSame('warning', $record['level']);
        $this->assertSame('cache miss', $record['data']['message']);
    }

    public function testPsrAdapterAttachesAnExceptionFromTheContext(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();
        $psr = new PsrStyleLogger($logger);

        $psr->error('checkout failed', ['exception' => new RuntimeException('boom')]);

        $record = $handler->lastAsArray();

        $this->assertSame('failure', $record['outcome']);
        $this->assertSame('exception', $record['error']['type']);
        $this->assertSame('boom', $record['error']['message']);
    }

    public function testLoggerIsAnInterface(): void
    {
        $logger = Harness::logger([new InMemoryHandler()]);

        $this->assertTrue($logger instanceof Logger);
        $this->assertTrue($logger instanceof LoggerInterface);
    }
}
