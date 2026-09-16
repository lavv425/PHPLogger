<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Enum;

use Logger\Enum\ErrorType;
use Logger\Enum\LogType;
use PHPUnit\Framework\TestCase;

final class ErrorTypeTest extends TestCase
{
    public function test_validates_the_known_error_types(): void
    {
        foreach (ErrorType::all() as $type) {
            self::assertTrue(ErrorType::isValid($type), $type . ' should be valid');
        }

        self::assertFalse(ErrorType::isValid('network'));
        self::assertFalse(ErrorType::isValid(''));
    }

    public function test_built_in_log_types_are_listed(): void
    {
        self::assertSame(
            ['db_query', 'service_call', 'php_log', 'logger_error'],
            LogType::all()
        );
    }
}
