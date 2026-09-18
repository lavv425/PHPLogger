<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Payload;

use Logger\Enum\ErrorType;
use Logger\Enum\Level;
use Logger\Enum\LogType;
use Logger\Enum\Outcome;
use Logger\Payload\PhpLogPayload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpLogPayloadTest extends TestCase
{
    public function test_describes_a_free_form_application_log(): void
    {
        $payload = PhpLogPayload::create('custom_log', 'checkout started')->withContext(['route' => '/checkout']);

        self::assertSame(LogType::PHP_LOG, $payload->logType());
        self::assertSame('custom_log', $payload->event());
        self::assertSame(Outcome::UNKNOWN, $payload->outcome());
        self::assertSame('checkout started', $payload->data()['message']);
        self::assertSame(['route' => '/checkout'], $payload->data()['context']);
        self::assertNull($payload->error());
    }

    public function test_renders_every_type_specific_field(): void
    {
        $payload = PhpLogPayload::create('custom_log', 'done')->withLocation('/app/Checkout.php', 42)->withCallSite('App\Checkout', 'run')->withMemoryUsage(1048576);

        self::assertSame([
            'message' => 'done',
            'file' => '/app/Checkout.php',
            'line' => 42,
            'class' => 'App\Checkout',
            'function' => 'run',
            'memory_usage' => 1048576,
            'context' => null,
        ], $payload->data());
    }

    public function test_describes_a_throwable(): void
    {
        $exception = new RuntimeException('connection refused');
        $line = __LINE__ - 1;

        $payload = PhpLogPayload::fromThrowable('uncaught_exception', $exception);

        self::assertSame(Outcome::FAILURE, $payload->outcome());
        self::assertSame(Level::ERROR, $payload->defaultLevel());
        self::assertSame('connection refused', $payload->data()['message']);
        self::assertSame(__FILE__, $payload->data()['file']);
        self::assertSame($line, $payload->data()['line']);
        self::assertSame(RuntimeException::class, $payload->data()['class']);

        self::assertNotNull($payload->error());
        self::assertSame(ErrorType::EXCEPTION, $payload->error()->type());
    }

    public function test_the_message_mirrors_the_error_message(): void
    {
        // One field for a dashboard to read, regardless of how the record was
        // produced.
        $payload = PhpLogPayload::fromThrowable('uncaught_exception', new RuntimeException('boom'));

        self::assertSame($payload->error()->message(), $payload->data()['message']);
    }

    public function test_the_frame_budget_is_forwarded(): void
    {
        $payload = PhpLogPayload::fromThrowable('uncaught_exception', $this->nest(6), 2);

        self::assertCount(3, $payload->error()->stackTrace(), 'two frames plus the truncation marker');
    }

    /** @dataProvider phpErrors */
    public function test_describes_a_php_error(int $severity, string $level, string $outcome): void
    {
        $payload = PhpLogPayload::fromPhpError('error_handler', $severity, 'undefined index', '/app/a.php', 10);

        self::assertSame($level, $payload->defaultLevel());
        self::assertSame($outcome, $payload->outcome());
        self::assertSame('undefined index', $payload->data()['message']);
        self::assertSame('/app/a.php', $payload->data()['file']);
        self::assertSame(10, $payload->data()['line']);
        self::assertSame(ErrorType::PHP_ERROR, $payload->error()->type());
        self::assertSame($severity, $payload->error()->severityCode());
    }

    /** @return array<string, array{int, string, string}> */
    public function phpErrors(): array
    {
        return [
            'fatal' => [1, Level::CRITICAL, Outcome::FAILURE],
            'user error' => [256, Level::ERROR, Outcome::FAILURE],
            'warning' => [2, Level::WARNING, Outcome::UNKNOWN],
            'deprecation' => [8192, Level::NOTICE, Outcome::UNKNOWN],
        ];
    }

    public function test_a_php_error_can_carry_a_stack_trace(): void
    {
        $payload = PhpLogPayload::fromPhpError('error_handler', 2, 'boom', '/app/a.php', 10, ['#0 a.php(1): a()']);

        self::assertSame(['#0 a.php(1): a()'], $payload->error()->stackTrace());
    }

    public function test_an_explicit_level_wins_over_the_severity_mapping(): void
    {
        $payload = PhpLogPayload::fromPhpError('error_handler', 1, 'boom', '/app/a.php', 10)->withLevel(Level::DEBUG);

        self::assertSame(Level::DEBUG, $payload->defaultLevel());
    }

    public function test_with_methods_return_copies(): void
    {
        $original = PhpLogPayload::create('custom_log', 'msg');

        $modified = $original->withLocation('/app/a.php', 1)->withCallSite('C', 'm')->withMemoryUsage(10)->withContext(['a' => 1]);

        self::assertNull($original->data()['file']);
        self::assertNull($original->data()['class']);
        self::assertNull($original->data()['memory_usage']);
        self::assertNull($original->data()['context']);
        self::assertSame('/app/a.php', $modified->data()['file']);
    }

    private function nest(int $depth): RuntimeException
    {
        if ($depth <= 0) {
            return new RuntimeException('deep');
        }

        return $this->nest($depth - 1);
    }
}
