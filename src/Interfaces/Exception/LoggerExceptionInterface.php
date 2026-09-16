<?php

declare(strict_types=1);

namespace Logger\Interfaces\Exception;

use Throwable;

/**
 * Marker for every exception thrown by the logger, so callers can catch the
 * whole family without depending on concrete classes.
 */
interface LoggerExceptionInterface extends Throwable
{
}
