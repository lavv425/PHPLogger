<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Enum;

use Logger\Enum\Outcome;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    public function test_accepts_only_the_three_known_outcomes(): void
    {
        self::assertTrue(Outcome::isValid(Outcome::SUCCESS));
        self::assertTrue(Outcome::isValid(Outcome::FAILURE));
        self::assertTrue(Outcome::isValid(Outcome::UNKNOWN));

        self::assertFalse(Outcome::isValid('ok'));
        self::assertFalse(Outcome::isValid('SUCCESS'));
        self::assertFalse(Outcome::isValid(''));
    }

    public function test_success_flag_never_claims_a_failure_that_did_not_happen(): void
    {
        self::assertTrue(Outcome::toSuccessFlag(Outcome::SUCCESS));
        self::assertFalse(Outcome::toSuccessFlag(Outcome::FAILURE));
        self::assertNull(
            Outcome::toSuccessFlag(Outcome::UNKNOWN),
            'an unknown outcome must stay null, not collapse into false'
        );
    }
}
