<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Contract;

use Logger\Contract\LogError;
use Logger\Enum\ErrorType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogErrorTest extends TestCase
{
    public function test_an_unknown_type_falls_back_to_other(): void
    {
        self::assertSame(ErrorType::OTHER, (new LogError('network', 'boom'))->type());
        self::assertSame(ErrorType::DB_ERROR, (new LogError(ErrorType::DB_ERROR, 'boom'))->type());
    }

    public function test_describes_a_throwable(): void
    {
        $exception = new RuntimeException('connection refused', 7);

        $error = LogError::fromThrowable($exception);

        self::assertSame(ErrorType::EXCEPTION, $error->type());
        self::assertSame('connection refused', $error->message());
        self::assertSame('7', $error->code());
        self::assertSame(RuntimeException::class, $error->class());
        self::assertNull($error->severity());
        self::assertIsArray($error->stackTrace());
    }

    public function test_a_zero_exception_code_is_reported_as_absent(): void
    {
        // Almost every exception carries code 0; emitting it would suggest a
        // meaningful value that is not there.
        self::assertNull(LogError::fromThrowable(new RuntimeException('boom'))->code());
    }

    public function test_the_stack_trace_never_carries_call_arguments(): void
    {
        $error = LogError::fromThrowable($this->throwFrom('hunter2'));

        $rendered = implode("\n", $error->stackTrace() ?? []);

        // getTraceAsString() would inline the argument, which is one of the
        // most reliable ways to leak a password into a log line.
        self::assertStringNotContainsString('hunter2', $rendered);
        self::assertStringContainsString('throwFrom()', $rendered);
    }

    public function test_the_stack_trace_is_capped_and_says_so(): void
    {
        $error = LogError::fromThrowable($this->nest(6), 3);

        $frames = $error->stackTrace();
        self::assertIsArray($frames);
        self::assertCount(4, $frames, 'three frames plus the truncation marker');
        self::assertSame('#3 {truncated}', $frames[3]);
    }

    public function test_describes_a_php_error(): void
    {
        $error = LogError::fromPhpError(2, 'undefined index', ['#0 a.php(1): a()']);

        self::assertSame(ErrorType::PHP_ERROR, $error->type());
        self::assertSame('undefined index', $error->message());
        self::assertSame('E_WARNING', $error->severity());
        self::assertSame(2, $error->severityCode());
        self::assertNull($error->code(), 'an E_* constant is not an exception code');
        self::assertSame(['#0 a.php(1): a()'], $error->stackTrace());
    }

    public function test_a_curl_timeout_gets_its_own_type(): void
    {
        $timeout = LogError::fromCurl(28, 'Operation timed out');
        self::assertSame(ErrorType::TIMEOUT, $timeout->type());
        self::assertSame('28', $timeout->code());

        $other = LogError::fromCurl(6, 'Could not resolve host');
        self::assertSame(ErrorType::CURL_ERROR, $other->type());
        self::assertSame('6', $other->code());
    }

    public function test_describes_an_http_status(): void
    {
        $error = LogError::fromHttpStatus(503);

        self::assertSame(ErrorType::HTTP_ERROR, $error->type());
        self::assertSame('503', $error->code());
        self::assertSame('HTTP status 503', $error->message());

        self::assertSame('gateway down', LogError::fromHttpStatus(502, 'gateway down')->message());
    }

    public function test_describes_a_database_failure(): void
    {
        $error = LogError::fromDatabase('deadlock found', '40001');

        self::assertSame(ErrorType::DB_ERROR, $error->type());
        self::assertSame('40001', $error->code());
        self::assertSame('deadlock found', $error->message());
    }

    public function test_with_methods_return_copies(): void
    {
        $original = new LogError(ErrorType::EXCEPTION, 'original', null, null, null, null, ['#0 a.php(1): a()']);

        $rewritten = $original->withMessage('rewritten')->withStackTrace(null);

        self::assertSame('original', $original->message());
        self::assertSame(['#0 a.php(1): a()'], $original->stackTrace());
        self::assertSame('rewritten', $rewritten->message());
        self::assertNull($rewritten->stackTrace());
    }

    public function test_renders_every_field_of_the_public_shape(): void
    {
        $error = new LogError(ErrorType::PHP_ERROR, 'boom', '42', 'RuntimeException', 'E_WARNING', 2, ['#0 a.php(1): a()']);

        self::assertSame([
            'type' => 'php_error',
            'code' => '42',
            'message' => 'boom',
            'class' => 'RuntimeException',
            'severity' => 'E_WARNING',
            'severity_code' => 2,
            'stack_trace' => ['#0 a.php(1): a()'],
        ], $error->toArray());
    }

    private function throwFrom(string $password): RuntimeException
    {
        try {
            throw new RuntimeException('failed');
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    private function nest(int $depth): RuntimeException
    {
        if ($depth <= 0) {
            return new RuntimeException('deep');
        }

        return $this->nest($depth - 1);
    }
}
