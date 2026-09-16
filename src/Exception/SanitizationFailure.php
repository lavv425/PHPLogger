<?php

declare(strict_types=1);

namespace Logger\Exception;

use RuntimeException;

/**
 * Thrown by the sanitizing gate. When this is raised the original record is
 * dropped everywhere, including the fail-safe path: an unsanitized record is
 * never written.
 */
final class SanitizationFailure extends RuntimeException implements LoggerException
{
}
