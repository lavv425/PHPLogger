<?php

declare(strict_types=1);

namespace Logger\Interfaces\Adapter;

/**
 * Decides whether an outbound HTTP call succeeded.
 *
 * This is the one judgement the package refuses to make on its own: a 404 is a
 * failure for one caller and the expected answer for another. The adapter
 * collects the facts, this decides what they mean.
 */
interface HttpOutcomePolicyInterface
{
    /**
     * @param int $errno cURL error number, 0 when the transfer itself worked
     * @param int $httpCode status code, 0 when no response was received
     * @return string one of the Outcome constants
     */
    public function decide(int $errno, int $httpCode): string;
}
