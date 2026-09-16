<?php

declare(strict_types=1);

namespace PhpLogger\Context;

/**
 * Turns an identifier into a stable, non-reversible reference.
 *
 * Correlation in the dashboards keeps working; the resulting value cannot be
 * replayed against the application. With no pepper configured the class is
 * disabled and returns null, so the raw value is never emitted by accident.
 */
final class Pseudonymizer
{
    private string $pepper;
    private int $length;

    public function __construct(string $pepper, int $length = 16)
    {
        $this->pepper = $pepper;
        $this->length = max(8, min(64, $length));
    }

    public function isEnabled(): bool
    {
        return $this->pepper !== '';
    }

    public function pseudonymize(?string $value): ?string
    {
        if ($value === null || $value === '' || !$this->isEnabled()) {
            return null;
        }

        return substr(hash_hmac('sha256', $value, $this->pepper), 0, $this->length);
    }
}
