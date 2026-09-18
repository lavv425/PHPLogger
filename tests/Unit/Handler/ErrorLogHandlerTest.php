<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Handler;

use Logger\Formatter\JsonFormatter;
use Logger\Handler\ErrorLogHandler;
use Logger\Interfaces\Handler\HandlerInterface;
use Logger\Tests\Support\RecordBuilder;
use PHPUnit\Framework\TestCase;

final class ErrorLogHandlerTest extends TestCase
{
    private string $file;
    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'errorlog');
        self::assertIsString($path);
        $this->file = $path;

        // Without this the record would go wherever the host error_log points,
        // which is exactly the unpredictability this handler is documented for.
        $this->previousErrorLog = ini_set('error_log', $this->file);
    }

    protected function tearDown(): void
    {
        if (is_string($this->previousErrorLog)) {
            ini_set('error_log', $this->previousErrorLog);
        }

        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_implements_the_handler_contract(): void
    {
        self::assertInstanceOf(HandlerInterface::class, new ErrorLogHandler(new JsonFormatter()));
    }

    public function test_hands_the_record_to_error_log(): void
    {
        $handler = new ErrorLogHandler(new JsonFormatter());

        $handler->handle(RecordBuilder::make(['database' => 'billing']));

        self::assertStringContainsString('"database":"billing"', (string) file_get_contents($this->file));
    }

    public function test_strips_the_trailing_newline_the_formatter_adds(): void
    {
        $handler = new ErrorLogHandler(new JsonFormatter());

        $handler->handle(RecordBuilder::make(['n' => 1]));

        // error_log() adds its own line ending; keeping the formatter one would
        // produce a blank line between records.
        self::assertStringNotContainsString("}\n\n", (string) file_get_contents($this->file));
    }

    public function test_close_is_a_no_op(): void
    {
        $handler = new ErrorLogHandler(new JsonFormatter());
        $handler->handle(RecordBuilder::make());

        $handler->close();

        self::assertNotSame('', (string) file_get_contents($this->file));
    }
}
