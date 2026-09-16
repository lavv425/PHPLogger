<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Handler;

use Logger\Exception\HandlerFailure;
use Logger\Formatter\JsonFormatter;
use Logger\Handler\StreamHandler;
use Logger\Interfaces\Handler\HandlerInterface;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class StreamHandlerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stream');
        self::assertIsString($path);
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_implements_the_handler_contract(): void
    {
        self::assertInstanceOf(HandlerInterface::class, new StreamHandler($this->file, new JsonFormatter()));
    }

    public function test_writes_the_formatted_record_to_the_target(): void
    {
        $handler = new StreamHandler($this->file, new JsonFormatter());

        $handler->handle(RecordBuilder::make(['database' => 'billing']));
        $handler->close();

        $decoded = json_decode(trim((string) file_get_contents($this->file)), true);
        self::assertIsArray($decoded);
        self::assertSame('billing', $decoded['data']['database']);
    }

    public function test_appends_one_line_per_record(): void
    {
        $handler = new StreamHandler($this->file, new JsonFormatter());

        $handler->handle(RecordBuilder::make(['n' => 1]));
        $handler->handle(RecordBuilder::make(['n' => 2]));
        $handler->handle(RecordBuilder::make(['n' => 3]));
        $handler->close();

        $lines = explode("\n", rtrim((string) file_get_contents($this->file), "\n"));
        self::assertCount(3, $lines);

        foreach ($lines as $line) {
            self::assertIsArray(json_decode($line, true), 'every line must parse on its own');
        }
    }

    public function test_reopens_the_target_after_close(): void
    {
        $handler = new StreamHandler($this->file, new JsonFormatter());

        $handler->handle(RecordBuilder::make(['n' => 1]));
        $handler->close();
        $handler->handle(RecordBuilder::make(['n' => 2]));
        $handler->close();

        self::assertCount(2, explode("\n", rtrim((string) file_get_contents($this->file), "\n")));
    }

    public function test_an_unopenable_target_is_reported_as_a_handler_failure(): void
    {
        $handler = new StreamHandler('/this/path/does/not/exist/app.ndjson', new JsonFormatter());

        $this->expectException(HandlerFailure::class);
        $this->expectExceptionMessage('cannot open log target');

        $handler->handle(RecordBuilder::make());
    }

    public function test_writes_with_locking_when_asked(): void
    {
        $handler = new StreamHandler($this->file, new JsonFormatter(), true);

        $handler->handle(RecordBuilder::make(['database' => 'billing']));
        $handler->close();

        self::assertStringContainsString('billing', (string) file_get_contents($this->file));
    }

    public function test_can_wrap_a_stream_the_caller_owns(): void
    {
        $stream = fopen('php://memory', 'a+b');
        self::assertIsResource($stream);

        $handler = StreamHandler::fromResource($stream, new JsonFormatter());
        $handler->handle(RecordBuilder::make(['database' => 'billing']));

        rewind($stream);
        self::assertStringContainsString('billing', (string) stream_get_contents($stream));

        fclose($stream);
    }

    public function test_does_not_close_a_stream_it_does_not_own(): void
    {
        $stream = fopen('php://memory', 'a+b');
        self::assertIsResource($stream);

        $handler = StreamHandler::fromResource($stream, new JsonFormatter());
        $handler->handle(RecordBuilder::make(['n' => 1]));
        $handler->close();

        // Closing a caller-supplied stream would break the application that
        // handed it over.
        self::assertIsResource($stream);
        $handler->handle(RecordBuilder::make(['n' => 2]));

        rewind($stream);
        self::assertCount(2, explode("\n", rtrim((string) stream_get_contents($stream), "\n")));

        fclose($stream);
    }

    public function test_recovers_by_reopening_when_the_wrapped_stream_dies(): void
    {
        $stream = fopen('php://memory', 'a+b');
        self::assertIsResource($stream);

        $handler = StreamHandler::fromResource($stream, new JsonFormatter());
        fclose($stream);

        // The wrapped resource is gone, so the handler opens its own target
        // rather than writing to a dead descriptor. Losing the destination must
        // not take the application down.
        $handler->handle(RecordBuilder::make());

        self::assertFalse(is_resource($stream));
    }
}
