<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Support;

use Logger\Support\ErrorTrap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorTrapTest extends TestCase
{
    public function test_returns_the_operation_result(): void
    {
        self::assertSame(42, ErrorTrap::run(static function (): int {
            return 42;
        }));
    }

    public function test_captures_a_warning_instead_of_raising_it(): void
    {
        $error = null;

        $result = ErrorTrap::run(static function () {
            return fopen('/this/path/does/not/exist/logger.ndjson', 'rb');
        }, $error);

        self::assertFalse($result);
        self::assertIsString($error);
        self::assertNotSame('', $error);
    }

    public function test_reports_no_error_when_the_operation_is_clean(): void
    {
        $error = 'leftover';

        ErrorTrap::run(static function (): void {
        }, $error);

        self::assertNull($error, 'the out parameter must be reset, not left stale');
    }

    public function test_keeps_only_the_first_warning(): void
    {
        $error = null;

        ErrorTrap::run(static function (): void {
            trigger_error('first problem', E_USER_WARNING);
            trigger_error('second problem', E_USER_WARNING);
        }, $error);

        self::assertSame('first problem', $error);
    }

    public function test_restores_the_previous_handler_even_when_the_operation_throws(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            ErrorTrap::run(static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('the exception should have propagated');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        // If the trap had not restored the handler, this would be swallowed by
        // the trap's own handler and $seen would stay empty.
        trigger_error('after the trap', E_USER_WARNING);
        restore_error_handler();

        self::assertSame(['after the trap'], $seen);
    }

    public function test_does_not_leak_into_the_surrounding_handler(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        ErrorTrap::run(static function (): void {
            trigger_error('inside the trap', E_USER_WARNING);
        });

        restore_error_handler();

        self::assertSame([], $seen, 'the application handler must never see the logger own warnings');
    }
}
