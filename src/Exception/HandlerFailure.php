<?php

declare(strict_types=1);

namespace Logger\Exception;

use Logger\Interfaces\Exception\LoggerExceptionInterface;
use RuntimeException;

/**
 * Thrown when a destination cannot be written to. Handled by the channel and
 * the circuit breaker; never reaches the application.
 */
final class HandlerFailure extends RuntimeException implements LoggerExceptionInterface
{
}
