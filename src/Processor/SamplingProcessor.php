<?php

declare(strict_types=1);

namespace Logger\Processor;

use Exception;
use Logger\Contract\LogRecord;
use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Interfaces\Processor\ProcessorInterface;

/**
 * Drops a share of high volume log types.
 *
 * Failures and anything at error level or above are always kept: sampling is
 * there to cut the cost of the boring records, never to hide the interesting
 * ones.
 */
final class SamplingProcessor implements ProcessorInterface
{
    private const PRECISION = 10000;

    /** @var array<string, float> log type => rate between 0 and 1 */
    private array $rates;

    /** @param array<string, float> $rates */
    public function __construct(array $rates)
    {
        $this->rates = $rates;
    }

    public function process(LogRecord $record): ?LogRecord
    {
        $rate = $this->rates[$record->logType()] ?? 1.0;

        if ($rate >= 1.0) {
            return $record;
        }

        if ($record->outcome() === Outcome::FAILURE || Level::isAtLeast($record->level(), Level::ERROR)) {
            return $record;
        }

        if ($rate <= 0.0) {
            return null;
        }

        return $this->draw() <= (int) round($rate * self::PRECISION) ? $record : null;
    }

    private function draw(): int
    {
        try {
            return random_int(1, self::PRECISION);
        } catch (Exception $exception) {
            return mt_rand(1, self::PRECISION);
        }
    }
}
