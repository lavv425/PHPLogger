<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Enum;

use Logger\Enum\HttpMethod;
use PHPUnit\Framework\TestCase;

final class HttpMethodTest extends TestCase
{
    public function test_normalize_trims_and_uppercases(): void
    {
        self::assertSame('GET', HttpMethod::normalize('get'));
        self::assertSame('POST', HttpMethod::normalize("  post \n"));
        self::assertSame('PATCH', HttpMethod::normalize('PaTcH'));
    }

    public function test_validates_the_supported_methods(): void
    {
        foreach (HttpMethod::all() as $method) {
            self::assertTrue(HttpMethod::isValid($method), $method . ' should be valid');
        }

        self::assertFalse(HttpMethod::isValid('TRACE'));
        self::assertFalse(HttpMethod::isValid('get'), 'validation happens after normalize()');
    }
}
