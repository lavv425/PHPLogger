<?php

declare(strict_types=1);

namespace Logger\Exception;

use InvalidArgumentException;
use Logger\Interfaces\Exception\LoggerExceptionInterface;

/**
 * Thrown at boot time. Never caught by the logger: a misconfigured logger must
 * stop the application before it starts serving traffic.
 */
final class InvalidConfigurationException extends InvalidArgumentException implements LoggerExceptionInterface
{
    public static function forKey(string $key, string $reason): self
    {
        return new self(sprintf('Invalid logger configuration at "%s": %s', $key, $reason));
    }
}
