<?php

declare(strict_types=1);

namespace Logger\Adapter\Curl;

use Logger\Enum\Outcome;
use Logger\Interfaces\Adapter\HttpOutcomePolicyInterface;

/**
 * The obvious default: a transport error is a failure, so is a status at or
 * above the threshold, and everything else succeeded.
 *
 * Deliberately replaceable. An API that answers 404 to mean "not found, which
 * is fine" wants its own policy rather than a flood of false failures.
 */
final class StatusHttpOutcomePolicy implements HttpOutcomePolicyInterface
{
    private int $failureFrom;

    public function __construct(int $failureFrom = 400)
    {
        $this->failureFrom = $failureFrom;
    }

    public function decide(int $errno, int $httpCode): string
    {
        if ($errno !== 0) {
            return Outcome::FAILURE;
        }

        // No status at all and no transport error: nothing to judge. Reporting
        // success here would claim something that was never observed.
        if ($httpCode === 0) {
            return Outcome::UNKNOWN;
        }

        return $httpCode >= $this->failureFrom ? Outcome::FAILURE : Outcome::SUCCESS;
    }
}
