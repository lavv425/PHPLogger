<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Channel;

use Logger\Channel\Channel;
use Logger\Contract\LogRecord;
use Logger\Enum\Level;
use Logger\Exception\HandlerFailure;
use Logger\Handler\InMemoryHandler;
use Logger\Interfaces\Processor\ProcessorInterface;
use Logger\Tests\Support\FailingHandler;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    public function test_exposes_its_name(): void
    {
        self::assertSame('errors', (new Channel('errors', Level::DEBUG, [], []))->name());
    }

    public function test_writes_a_record_at_or_above_the_floor(): void
    {
        $handler = new InMemoryHandler();
        $channel = new Channel('errors', Level::WARNING, [], [$handler]);

        $channel->handle(RecordBuilder::make([], Level::WARNING));
        $channel->handle(RecordBuilder::make([], Level::ERROR));

        self::assertCount(2, $handler->records());
    }

    public function test_drops_a_record_below_the_floor(): void
    {
        $handler = new InMemoryHandler();
        $channel = new Channel('errors', Level::WARNING, [], [$handler]);

        $channel->handle(RecordBuilder::make([], Level::INFO));

        self::assertSame([], $handler->records());
    }

    public function test_fans_a_record_out_to_every_handler(): void
    {
        $first = new InMemoryHandler();
        $second = new InMemoryHandler();
        $channel = new Channel('errors', Level::DEBUG, [], [$first, $second]);

        $channel->handle(RecordBuilder::make());

        self::assertCount(1, $first->records());
        self::assertCount(1, $second->records());
    }

    public function test_a_processor_can_rewrite_the_record(): void
    {
        $handler = new InMemoryHandler();
        $channel = new Channel('stdout', Level::DEBUG, [$this->rewritingProcessor()], [$handler]);

        $channel->handle(RecordBuilder::make(['original' => true]));

        self::assertSame(['rewritten' => true], $handler->records()[0]->data());
    }

    public function test_a_processor_can_drop_the_record(): void
    {
        $handler = new InMemoryHandler();
        $channel = new Channel('stdout', Level::DEBUG, [$this->droppingProcessor()], [$handler]);

        $channel->handle(RecordBuilder::make());

        self::assertSame([], $handler->records());
    }

    public function test_processors_run_in_order_and_stop_at_the_first_drop(): void
    {
        $handler = new InMemoryHandler();
        $second = $this->rewritingProcessor();
        $channel = new Channel('stdout', Level::DEBUG, [$this->droppingProcessor(), $second], [$handler]);

        $channel->handle(RecordBuilder::make());

        self::assertSame([], $handler->records());
    }

    public function test_one_surviving_destination_is_enough(): void
    {
        $healthy = new InMemoryHandler();
        $channel = new Channel('errors', Level::DEBUG, [], [new FailingHandler(), $healthy]);

        $channel->handle(RecordBuilder::make());

        self::assertCount(1, $healthy->records(), 'a single dead destination must not lose the record');
    }

    public function test_reports_only_a_total_blackout(): void
    {
        $channel = new Channel('errors', Level::DEBUG, [], [new FailingHandler('first down'), new FailingHandler('second down')]);

        $this->expectException(HandlerFailure::class);
        $this->expectExceptionMessage('second down');

        $channel->handle(RecordBuilder::make());
    }

    public function test_a_channel_without_handlers_swallows_the_record(): void
    {
        $channel = new Channel('off', Level::DEBUG, [], []);

        $channel->handle(RecordBuilder::make());

        self::assertSame('off', $channel->name());
    }

    public function test_close_closes_every_handler(): void
    {
        $first = new FailingHandler();
        $second = new FailingHandler();
        $channel = new Channel('errors', Level::DEBUG, [], [$first, $second]);

        $channel->close();

        self::assertTrue($first->isClosed());
        self::assertTrue($second->isClosed());
    }

    private function rewritingProcessor(): ProcessorInterface
    {
        return new class () implements ProcessorInterface {
            public function process(LogRecord $record): ?LogRecord
            {
                return $record->withData(['rewritten' => true]);
            }
        };
    }

    private function droppingProcessor(): ProcessorInterface
    {
        return new class () implements ProcessorInterface {
            public function process(LogRecord $record): ?LogRecord
            {
                return null;
            }
        };
    }
}
