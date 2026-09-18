<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Adapter;

use Logger\Adapter\Error\ErrorHandlerBridge;
use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Exception\InvalidLogEventException;
use Logger\Tests\Support\LoggerHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerBridgeTest extends TestCase
{
    private LoggerHarness $harness;
    private ErrorHandlerBridge $bridge;

    protected function setUp(): void
    {
        // php_log is routed to a channel with a warning floor by default; an
        // empty routing keeps notices readable in one place.
        $this->harness = LoggerHarness::create(['routing' => []]);
        $this->bridge = new ErrorHandlerBridge($this->harness->logger());
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    public function test_logs_a_reported_error(): void
    {
        $this->bridge->handleError(E_USER_WARNING, 'qualcosa non va', '/app/Checkout.php', 42);

        $record = $this->harness->lastRecord();
        self::assertSame('php_log', $record['log_type']);
        self::assertSame('error_handler', $record['event']);
        self::assertSame(Level::WARNING, $record['level']);
        self::assertSame('qualcosa non va', $record['data']['message']);
        self::assertSame('/app/Checkout.php', $record['data']['file']);
        self::assertSame(42, $record['data']['line']);
        self::assertSame('E_USER_WARNING', $record['error']['severity']);
    }

    public function test_returns_false_so_php_keeps_handling_the_error(): void
    {
        // Returning true would silence the error for everyone else, including
        // the destinations already configured in php.ini.
        self::assertFalse($this->bridge->handleError(E_USER_WARNING, 'msg', '/app/a.php', 1));
    }

    public function test_ignores_an_error_the_operator_asked_not_to_see(): void
    {
        $previous = error_reporting(0);

        try {
            $this->bridge->handleError(E_USER_WARNING, 'soppresso con @', '/app/a.php', 1);
        } finally {
            error_reporting($previous);
        }

        self::assertSame([], $this->harness->lines(), 'the @ operator must be honoured');
    }

    public function test_ignores_a_severity_switched_off_in_the_configuration(): void
    {
        $previous = error_reporting(E_ALL & ~E_USER_DEPRECATED);

        try {
            $this->bridge->handleError(E_USER_DEPRECATED, 'deprecato', '/app/a.php', 1);
            $this->bridge->handleError(E_USER_WARNING, 'avviso', '/app/a.php', 1);
        } finally {
            error_reporting($previous);
        }

        self::assertCount(1, $this->harness->lines());
        self::assertSame('avviso', $this->harness->lastRecord()['data']['message']);
    }

    public function test_a_warning_does_not_claim_the_operation_failed(): void
    {
        $this->bridge->handleError(E_USER_WARNING, 'msg', '/app/a.php', 1);

        self::assertSame(Outcome::UNKNOWN, $this->harness->lastRecord()['outcome']);
        self::assertNull($this->harness->lastRecord()['success']);
    }

    public function test_logs_an_uncaught_exception_and_lets_it_through(): void
    {
        $exception = new RuntimeException('esplosione');

        try {
            $this->bridge->handleException($exception);
            self::fail('the exception must not be swallowed');
        } catch (RuntimeException $rethrown) {
            self::assertSame($exception, $rethrown, 'the original object, not a copy');
        }

        $record = $this->harness->lastRecord();
        self::assertSame('uncaught_exception', $record['event']);
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertSame('esplosione', $record['error']['message']);
    }

    public function test_stack_traces_are_off_by_default(): void
    {
        $this->bridge->handleError(E_USER_WARNING, 'msg', '/app/a.php', 1);

        self::assertNull($this->harness->lastRecord()['error']['stack_trace']);
    }

    public function test_stack_traces_can_be_enabled_without_leaking_arguments(): void
    {
        $bridge = new ErrorHandlerBridge($this->harness->logger(), 0, true, 5);

        $this->callThrough($bridge, 'hunter2');

        $trace = $this->harness->lastRecord()['error']['stack_trace'];
        self::assertIsArray($trace);
        self::assertNotSame([], $trace);
        self::assertStringNotContainsString('hunter2', implode("\n", $trace), 'DEBUG_BACKTRACE_IGNORE_ARGS is not optional');
    }

    public function test_a_logging_failure_never_reaches_the_application(): void
    {
        $harness = LoggerHarness::create(['routing' => ['php_log' => 'dead']]);
        $bridge = new ErrorHandlerBridge($harness->logger());

        self::assertFalse($bridge->handleError(E_USER_WARNING, 'msg', '/app/a.php', 1));

        $harness->cleanUp();
    }

    public function test_an_invalid_event_name_is_refused_at_construction(): void
    {
        // Caught at wiring time rather than on the first error, when the
        // application is already in trouble.
        $this->expectException(InvalidLogEventException::class);

        new ErrorHandlerBridge($this->harness->logger(), 0, false, 20, 'evento non valido');
    }

    public function test_register_installs_and_unregister_restores_the_handlers(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        $bridge = new ErrorHandlerBridge($this->harness->logger(), 0);
        $bridge->register();
        trigger_error('mentre il ponte e installato', E_USER_WARNING);
        $bridge->unregister();
        trigger_error('dopo il ripristino', E_USER_WARNING);

        restore_error_handler();

        // Both reach the pre-existing handler: the bridge chains to it rather
        // than replacing it.
        self::assertSame(['mentre il ponte e installato', 'dopo il ripristino'], $seen);
        self::assertCount(1, $this->harness->lines(), 'only the first one was logged by the bridge');
    }

    public function test_registering_twice_is_harmless(): void
    {
        $bridge = new ErrorHandlerBridge($this->harness->logger(), 0);
        $bridge->register();
        $bridge->register();
        $bridge->unregister();

        self::assertSame([], $this->harness->lines());
    }

    /**
     * $secret is deliberately unused: it is here to sit in this frame's
     * argument list, so the test can prove the captured trace does not carry
     * call arguments.
     */
    private function callThrough(ErrorHandlerBridge $bridge, string $secret): void
    {
        $bridge->handleError(E_USER_WARNING, 'msg', '/app/a.php', 1);
    }
}
