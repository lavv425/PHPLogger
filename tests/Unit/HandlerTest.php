<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Channel\Channel;
use PhpLogger\Enum\Level;
use PhpLogger\Exception\HandlerFailure;
use PhpLogger\Formatter\JsonFormatter;
use PhpLogger\Handler\CircuitBreakerHandler;
use PhpLogger\Handler\InMemoryHandler;
use PhpLogger\Handler\StreamHandler;
use PhpLogger\Payload\DbQueryPayload;
use PhpLoggerTests\Support\FailingHandler;
use PhpLoggerTests\Support\Harness;
use PhpLoggerTests\TestCase;

final class HandlerTest extends TestCase
{
    public function testStreamHandlerWritesOneLinePerRecord(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phplogger');
        $handler = new StreamHandler($path, new JsonFormatter());
        $record = Harness::recordFactory()->create(DbQueryPayload::create('user_lookup', 'db'));

        $handler->handle($record);
        $handler->handle($record);
        $handler->close();

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        unlink($path);

        $this->assertCount(2, $lines);
        $this->assertSame('user_lookup', json_decode($lines[0], true)['event']);
    }

    public function testStreamHandlerReportsAnUnreachableTarget(): void
    {
        $handler = new StreamHandler('/this/path/does/not/exist/app.ndjson', new JsonFormatter());
        $record = Harness::recordFactory()->create(DbQueryPayload::create('user_lookup', 'db'));

        $failure = $this->assertThrows(HandlerFailure::class, static function () use ($handler, $record): void {
            $handler->handle($record);
        });

        $this->assertStringContains('cannot open log target', $failure->getMessage());
    }

    public function testCircuitBreakerStopsCallingADeadDestination(): void
    {
        $inner = new FailingHandler();
        $breaker = new CircuitBreakerHandler($inner, Harness::clock(), 2, 30);
        $record = Harness::recordFactory()->create(DbQueryPayload::create('user_lookup', 'db'));

        for ($i = 0; $i < 5; ++$i) {
            try {
                $breaker->handle($record);
            } catch (HandlerFailure $failure) {
                // expected while the breaker is closed
            }
        }

        $this->assertSame(2, $inner->attempts());
        $this->assertTrue($breaker->isOpen());
    }

    public function testCircuitBreakerRecoversAfterTheCooldown(): void
    {
        $clock = Harness::clock();
        $inner = new FailingHandler();
        $breaker = new CircuitBreakerHandler($inner, $clock, 1, 30);
        $record = Harness::recordFactory()->create(DbQueryPayload::create('user_lookup', 'db'));

        try {
            $breaker->handle($record);
        } catch (HandlerFailure $failure) {
            // expected
        }

        $this->assertTrue($breaker->isOpen());

        $clock->advance(31.0);

        $this->assertFalse($breaker->isOpen());
    }

    public function testChannelHonoursItsMinimumLevel(): void
    {
        $memory = new InMemoryHandler();
        $channel = new Channel('errors', Level::WARNING, [], [$memory]);
        $factory = Harness::recordFactory();

        $channel->handle($factory->create(DbQueryPayload::create('user_lookup', 'db'), Level::INFO));
        $channel->handle($factory->create(DbQueryPayload::create('user_lookup', 'db'), Level::ERROR));

        $this->assertCount(1, $memory->records());
        $this->assertSame(Level::ERROR, $memory->records()[0]->level());
    }
}
