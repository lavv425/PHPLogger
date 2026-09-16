<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Handler;

use Logger\Handler\InMemoryHandler;
use Logger\Handler\NullHandler;
use Logger\Interfaces\Handler\HandlerInterface;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class InMemoryHandlerTest extends TestCase
{
    public function test_keeps_both_the_records_and_their_rendered_lines(): void
    {
        $handler = new InMemoryHandler();

        $handler->handle(RecordBuilder::make(['n' => 1]));
        $handler->handle(RecordBuilder::make(['n' => 2]));

        self::assertCount(2, $handler->records());
        self::assertCount(2, $handler->lines());
        self::assertStringContainsString('"n":1', $handler->lines()[0]);
    }

    public function test_exposes_the_last_record_as_an_array(): void
    {
        $handler = new InMemoryHandler();

        $handler->handle(RecordBuilder::make(['n' => 1]));
        $handler->handle(RecordBuilder::make(['n' => 2]));

        $last = $handler->lastAsArray();
        self::assertIsArray($last);
        self::assertSame(2, $last['data']['n']);
    }

    public function test_the_last_record_of_an_empty_handler_is_null(): void
    {
        self::assertNull((new InMemoryHandler())->lastAsArray());
    }

    public function test_clear_empties_the_buffer(): void
    {
        $handler = new InMemoryHandler();
        $handler->handle(RecordBuilder::make());

        $handler->clear();

        self::assertSame([], $handler->records());
        self::assertSame([], $handler->lines());
        self::assertNull($handler->lastAsArray());
    }

    public function test_close_keeps_what_was_collected(): void
    {
        $handler = new InMemoryHandler();
        $handler->handle(RecordBuilder::make());

        $handler->close();

        self::assertCount(1, $handler->records(), 'a test destination must stay readable after close');
    }

    public function test_the_null_handler_discards_everything_without_failing(): void
    {
        $handler = new NullHandler();

        $handler->handle(RecordBuilder::make());
        $handler->close();

        self::assertInstanceOf(HandlerInterface::class, $handler);
    }
}
