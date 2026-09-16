<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Handler;

use Logger\Exception\HandlerFailure;
use Logger\Formatter\JsonFormatter;
use Logger\Handler\StreamHandler;
use Logger\Tests\Support\RecordBuilder;
use Logger\Tests\Support\UnreliableStream;
use PHPUnit\Framework\TestCase;

/**
 * A half-written line is a corrupted record for the collector, so the handler
 * has to deal with a destination that accepts only part of the buffer.
 */
final class StreamHandlerWriteTest extends TestCase
{
    protected function tearDown(): void
    {
        UnreliableStream::disable();
    }

    public function test_keeps_writing_until_the_whole_line_is_out(): void
    {
        UnreliableStream::enable(UnreliableStream::MODE_PARTIAL, 7);

        $handler = new StreamHandler(UnreliableStream::PROTOCOL . '://log', new JsonFormatter());
        $record = RecordBuilder::make(['database' => 'billing']);

        $handler->handle($record);

        $expected = (new JsonFormatter())->format($record);
        self::assertSame($expected, UnreliableStream::$written, 'the record must arrive whole, not in one chunk');
        self::assertGreaterThan(7, strlen(UnreliableStream::$written));
    }

    public function test_a_single_byte_destination_still_gets_the_whole_record(): void
    {
        UnreliableStream::enable(UnreliableStream::MODE_PARTIAL, 1);

        $handler = new StreamHandler(UnreliableStream::PROTOCOL . '://log', new JsonFormatter());
        $record = RecordBuilder::make(['n' => 1]);

        $handler->handle($record);

        self::assertSame((new JsonFormatter())->format($record), UnreliableStream::$written);
    }

    public function test_a_destination_that_accepts_nothing_is_reported_rather_than_looped_on(): void
    {
        UnreliableStream::enable(UnreliableStream::MODE_STALLED);

        $handler = new StreamHandler(UnreliableStream::PROTOCOL . '://log', new JsonFormatter());

        // Without the empty-write cap this would spin forever against a
        // blocked pipe and stall the request.
        $this->expectException(HandlerFailure::class);
        $this->expectExceptionMessage('stalled');

        $handler->handle(RecordBuilder::make());
    }
}
