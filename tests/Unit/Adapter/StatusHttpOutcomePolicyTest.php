<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Adapter;

use Logger\Adapter\Curl\StatusHttpOutcomePolicy;
use Logger\Enum\Outcome;
use Logger\Interfaces\Adapter\HttpOutcomePolicyInterface;
use PHPUnit\Framework\TestCase;

final class StatusHttpOutcomePolicyTest extends TestCase
{
    public function test_implements_the_policy_contract(): void
    {
        self::assertInstanceOf(HttpOutcomePolicyInterface::class, new StatusHttpOutcomePolicy());
    }

    /** @dataProvider cases */
    public function test_decides_from_the_transport_error_and_the_status(int $errno, int $httpCode, string $expected): void
    {
        self::assertSame($expected, (new StatusHttpOutcomePolicy())->decide($errno, $httpCode));
    }

    /** @return array<string, array{int, int, string}> */
    public function cases(): array
    {
        return [
            'plain success' => [0, 200, Outcome::SUCCESS],
            'created' => [0, 201, Outcome::SUCCESS],
            'redirect' => [0, 302, Outcome::SUCCESS],
            'not found' => [0, 404, Outcome::FAILURE],
            'server error' => [0, 500, Outcome::FAILURE],
            'transport error wins over the status' => [7, 200, Outcome::FAILURE],
            'timeout' => [28, 0, Outcome::FAILURE],
            'no response and no error' => [0, 0, Outcome::UNKNOWN],
        ];
    }

    public function test_a_response_that_was_never_observed_is_not_called_a_success(): void
    {
        // Claiming success for a call that produced neither error nor status
        // would be inventing a fact.
        self::assertSame(Outcome::UNKNOWN, (new StatusHttpOutcomePolicy())->decide(0, 0));
    }

    public function test_the_failure_threshold_is_configurable(): void
    {
        // An API where 404 means "not found, which is fine" must not produce a
        // flood of false failures.
        $lenient = new StatusHttpOutcomePolicy(500);

        self::assertSame(Outcome::SUCCESS, $lenient->decide(0, 404));
        self::assertSame(Outcome::FAILURE, $lenient->decide(0, 500));
    }
}
