<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Payload\PhpLogPayload;
use PhpLogger\Payload\ServiceCallPayload;
use PhpLogger\Support\Text;
use PhpLoggerTests\Support\Harness;
use PhpLoggerTests\TestCase;

final class TruncatorTest extends TestCase
{
    public function testOversizedRecordsAreTrimmedAndFlagged(): void
    {
        $limits = Harness::limits(1024, 4096);
        $gate = Harness::gate(['service_call' => ['payload' => ['body']]], false, $limits);
        [$logger, $handler] = Harness::loggerWithMemory($gate);

        $logger->log(
            ServiceCallPayload::create('si_service_call', 'POST', 'https://api.example.com/v1/orders')
                ->withPayload(['body' => str_repeat('lorem ipsum dolor sit amet ', 200)])
        );

        $line = $handler->lines()[0];
        $record = $handler->lastAsArray();

        $this->assertTrue(strlen($line) <= 1200, 'record should be close to the configured limit');
        $this->assertTrue($record['_truncated']);
        $this->assertSame(1, $record['data']['payload']['_omitted']);
    }

    public function testStackTracesAreCappedByFrameCount(): void
    {
        $gate = Harness::gate([], false, Harness::limits());
        [$logger, $handler] = Harness::loggerWithMemory($gate);

        $logger->log(PhpLogPayload::fromThrowable('uncaught_exception', self::deepThrowable(40)));

        $record = $handler->lastAsArray();

        $this->assertTrue(count($record['error']['stack_trace']) <= 20);
    }

    public function testTruncationNeverBreaksUtf8(): void
    {
        // "€" is three bytes: cutting at 4 must not leave half a sequence.
        $truncated = Text::truncateBytes('ab€cd', 4, '');

        $this->assertSame('ab', $truncated);
        $this->assertTrue(Text::isValidUtf8($truncated));
    }

    public function testShortStringsAreLeftAlone(): void
    {
        $this->assertSame('hello', Text::truncateBytes('hello', 32));
    }

    private static function deepThrowable(int $depth): \RuntimeException
    {
        if ($depth <= 0) {
            return new \RuntimeException('deep');
        }

        return self::deepThrowable($depth - 1);
    }
}
